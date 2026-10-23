<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\PendingPairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\StudentAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The student roster: list, single creation (with its login), CSV import
 * (`nombre,curso`) and delete. Creating or importing a student mints the
 * 1:1 account in the same transaction; the temporary password is
 * flashed exactly once.
 */
class StudentController extends Controller
{
    private const IMPORT_MAX_ROWS = 500;

    public function __construct(
        private readonly StudentAccountService $accounts,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $classId = (int) $request->query('class_id', 0);
        $credential = (string) $request->query('credential', 'all');

        if (! in_array($credential, ['all', 'card', 'phone', 'linked', 'unlinked'], true)) {
            $credential = 'all';
        }

        $students = Student::query()
            ->with([
                'schoolClass',
                'account',
                'cards' => fn ($query) => $query->where('status', CardStatus::Active->value),
            ])
            ->withCount('cards')
            ->withSum('pointsLedger as balance', 'delta')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('account', fn (Builder $account) => $account->where('email', 'like', '%'.$search.'%'))
            ))
            ->when($classId > 0, fn (Builder $query) => $query->where('class_id', $classId))
            ->when($credential !== 'all', function (Builder $query) use ($credential): void {
                $active = fn (Builder $cards) => $cards->where('status', CardStatus::Active->value);

                match ($credential) {
                    'card' => $query->whereHas('cards', fn (Builder $cards) => $active($cards)
                        ->where('kind', 'physical')),
                    'phone' => $query->whereHas('cards', fn (Builder $cards) => $active($cards)
                        ->where('kind', 'hce')),
                    'linked' => $query->whereHas('cards', $active),
                    'unlinked' => $query->whereDoesntHave('cards', $active),
                    default => null,
                };
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.students', [
            'students' => $students,
            'classes' => SchoolClass::withCount('students')->orderBy('name')->get(),
            'totalStudents' => Student::count(),
            'totalClasses' => SchoolClass::count(),
            'pendingStudentIds' => PendingPairing::active()->pluck('student_id')->map(fn ($id) => (int) $id)->all(),
            'search' => $search,
            'credentialFilter' => $credential,
            'selectedClassId' => $classId,
            'studentEmailDomain' => settings()->studentEmailDomain(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
        ]);

        if (Student::where('name', $data['name'])->where('class_id', $data['class_id'])->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe un estudiante con ese nombre en ese curso.',
            ]);
        }

        [$student, $provisioned] = DB::transaction(function () use ($data): array {
            $student = Student::create([
                'name' => trim((string) $data['name']),
                'class_id' => (int) $data['class_id'],
            ]);

            return [$student, $this->accounts->provisionFor($student)];
        });

        $account = $provisioned['user'];

        return redirect()->route('admin.students')->with([
            'status' => 'Estudiante creado: '.$student->name,
            'account_email' => $account->email,
            'account_password' => $provisioned['password'],
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = $path !== false ? @fopen($path, 'r') : false;

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo.']);
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $columns = $header === false ? null : $this->columnMap($header);

            if ($columns === null) {
                throw ValidationException::withMessages([
                    'file' => 'El CSV debe tener las columnas nombre,curso.',
                ]);
            }

            $credentials = [];

            [$creados, $omitidos] = DB::transaction(function () use ($handle, $columns, &$credentials): array {
                $creados = 0;
                $omitidos = 0;
                $students = [];
                $rows = 0;

                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    if (count($row) === 1 && trim((string) $row[0]) === '') {
                        continue; // blank line — not a data row
                    }

                    $rows++;

                    if ($rows > self::IMPORT_MAX_ROWS) {
                        $omitidos++;

                        continue;
                    }

                    $name = trim((string) ($row[$columns['nombre']] ?? ''));
                    $className = trim((string) ($row[$columns['curso']] ?? ''));

                    if ($name === '' || $className === '') {
                        $omitidos++;

                        continue;
                    }

                    $class = SchoolClass::firstOrCreate(['name' => $className]);

                    $duplicate = Student::where('name', $name)
                        ->where('class_id', $class->id)
                        ->exists();

                    if ($duplicate) {
                        $omitidos++;

                        continue;
                    }

                    $students[] = Student::create(['name' => $name, 'class_id' => $class->id]);
                    $creados++;
                }

                if ($students !== []) {
                    $credentials = $this->accounts->provisionMany(collect($students));
                }

                return [$creados, $omitidos];
            });
        } finally {
            @fclose($handle);
        }

        return redirect()->route('admin.students')->with([
            'status' => "Importación terminada: {$creados} creados, {$omitidos} omitidos.",
            'creados' => $creados,
            'omitidos' => $omitidos,
            'account_passwords' => array_values(array_filter(
                $credentials,
                fn (array $row): bool => $row['password'] !== '',
            )),
        ]);
    }

    public function destroy(Student $student): RedirectResponse
    {
        $name = $student->name;

        // Foreign keys cascade: cards, events, deposits and ledger rows go
        // with the student; the account row survives with student_id nulled.
        $student->delete();

        return redirect()->route('admin.students')->with('status', 'Estudiante eliminado: '.$name);
    }

    /**
     * @param  array<int, string|false|null>  $header
     * @return array<string, int>|null
     */
    private function columnMap(array $header): ?array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $key = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $cell)));

            if (in_array($key, ['nombre', 'curso'], true)) {
                $map[$key] = (int) $index;
            }
        }

        return isset($map['nombre'], $map['curso']) ? $map : null;
    }
}

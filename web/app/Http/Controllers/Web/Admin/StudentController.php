<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\StudentAccountService;
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

    public function index(): View
    {
        return view('admin.students', [
            'students' => Student::query()
                ->with(['schoolClass', 'account'])
                ->withCount('cards')
                ->withSum('pointsLedger as balance', 'delta')
                ->orderBy('name')
                ->paginate(25),
            'classes' => SchoolClass::orderBy('name')->get(),
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

        [$student, $account] = DB::transaction(function () use ($data): array {
            $student = Student::create([
                'name' => trim((string) $data['name']),
                'class_id' => (int) $data['class_id'],
            ]);

            $account = $this->accounts->provisionFor($student)['user'];

            return [$student, $account];
        });

        return redirect()->route('admin.students')->with([
            'status' => 'Estudiante creado: '.$student->name,
            'account_email' => $account->email,
            'account_password' => (string) settings()->studentInitialPassword(),
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

            [$creados, $omitidos] = DB::transaction(function () use ($handle, $columns): array {
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
                    $this->accounts->provisionMany(collect($students));
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

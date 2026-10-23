<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Classes and teacher assignment. One class, many teachers (the
 * `teacher_class` pivot); the teacher's StudentScope is derived from
 * that same pivot, so assignment is the authorization source.
 */
class ClassController extends Controller
{
    public function index(): View
    {
        return view('admin.classes', [
            'classes' => SchoolClass::query()
                ->withCount(['students', 'teachers'])
                ->with('teachers:id,name')
                ->orderBy('name')
                ->get(),
            'teachers' => User::where('role', UserRole::Teacher->value)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
        ]);

        $name = trim((string) $data['name']);

        if (SchoolClass::whereRaw('lower(name) = lower(?)', [$name])->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya existe un curso con ese nombre.']);
        }

        SchoolClass::create(['name' => $name]);

        return redirect()->route('admin.classes')->with('status', 'Curso creado: '.$name);
    }

    public function teachers(Request $request, SchoolClass $class): RedirectResponse
    {
        $data = $request->validate([
            'teacher_ids' => ['array'],
            'teacher_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['teacher_ids'] ?? [])));
        $teacherCount = User::whereIn('id', $ids)->where('role', UserRole::Teacher->value)->count();

        // The plan pins a 422 (not a redirect) for a non-teaching account:
        // a student/station/admin id is a client error, never a silent sync.
        abort_if($teacherCount !== count($ids), 422, 'Solo se pueden asignar cuentas docentes.');

        $class->teachers()->sync($ids);

        return redirect()->route('admin.classes')->with('status', 'Docentes actualizados para '.$class->name.'.');
    }
}

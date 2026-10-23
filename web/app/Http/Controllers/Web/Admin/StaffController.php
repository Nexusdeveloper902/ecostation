<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff accounts: admin, teacher and station. The station role is the
 * speaker-app account, so it deliberately skips the forced first-login
 * password rotation (the speaker scrapes the login form). Admin and
 * teacher accounts rotate like every other human login.
 */
class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.staff', [
            'staff' => User::query()
                ->whereIn('role', [UserRole::Admin->value, UserRole::Teacher->value, UserRole::Station->value])
                ->with('classes:id,name')
                ->orderBy('name')
                ->get(),
            'roles' => [UserRole::Admin->value, UserRole::Teacher->value, UserRole::Station->value],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in([UserRole::Admin->value, UserRole::Teacher->value, UserRole::Station->value])],
            'password' => ['required', 'string', 'min:6', 'max:120'],
        ]);

        $role = (string) $data['role'];

        $user = User::create([
            'name' => trim((string) $data['name']),
            'email' => mb_strtolower(trim((string) $data['email'])),
            'role' => $role,
            'password' => (string) $data['password'],
            'must_change_password' => $role !== UserRole::Station->value,
        ]);

        return redirect()->route('admin.staff')->with([
            'status' => 'Cuenta creada: '.$user->email,
            'account_email' => $user->email,
            'account_password' => (string) $data['password'],
        ]);
    }
}

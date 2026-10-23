<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The post-login / post-rotation landing page per role. Task 11 only
 * ships the station page; the dashboard names resolve automatically as
 * Tasks 13/14/16 register them. Until then an authenticated user falls
 * back to the password-change page (always reachable, never a 500).
 */
final class HomeTarget
{
    public static function for(User $user): string
    {
        $target = match (true) {
            $user->isStudent() => 'student.dashboard',
            $user->isTeacher() => 'teacher.dashboard',
            $user->isAdmin() => 'admin.students',
            $user->isStation() => 'station',
            default => 'password.change',
        };

        return Route::has($target) ? route($target) : route('password.change');
    }
}

<?php

use App\Http\Controllers\Web\Admin\CardController as AdminCardController;
use App\Http\Controllers\Web\Admin\ClassController as AdminClassController;
use App\Http\Controllers\Web\Admin\PairingController as AdminPairingController;
use App\Http\Controllers\Web\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Web\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\Web\Admin\StationController as AdminStationController;
use App\Http\Controllers\Web\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DepositPhotoController;
use App\Http\Controllers\Web\ManifestController;
use App\Http\Controllers\Web\PasswordController;
use App\Http\Controllers\Web\RealtimeTokenController;
use App\Http\Controllers\Web\StudentController;
use App\Http\Controllers\Web\TeacherDashboardController;
use App\Http\Controllers\Web\TeacherExportController;
use App\Support\HomeTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public discovery endpoint: the Android subnet-sweep probe reads it
// without a session (and it can never sit behind the password wall).
Route::get('/manifest.webmanifest', [ManifestController::class, 'show'])->name('manifest');

// Landing -> login (or the role-appropriate page when authenticated).
Route::get('/', function (Request $request) {
    $user = $request->user();

    if ($user === null) {
        return redirect()->route('login');
    }

    return redirect()->to(HomeTarget::for($user));
})->name('home');

// Guest-only. Login is throttled (the `login` limiter: 5/min per
// email+IP) — a brute-force guard with no UX change for a human.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Password rotation. The form lives outside any role gate (it is the
// exemption target of the rotation wall); the update clears the flag and
// lands on the role's own page.
Route::middleware('auth')->group(function () {
    Route::get('/password/change', [PasswordController::class, 'edit'])
        ->name('password.change');

    Route::put('/password/change', [PasswordController::class, 'update'])
        ->name('password.update');

    // The speaker-app account: web landing page only.
    Route::get('/station', fn () => view('station'))
        ->middleware('role:station')
        ->name('station');

    // Deposit photos: staff only, scoped by StudentScope inside.
    Route::get('/deposits/{deposit}/photo', [DepositPhotoController::class, 'show'])
        ->middleware('role:admin,teacher')
        ->name('deposits.photo');

    // Feed token for the live panel and the speaker app (session cookie;
    // the app sends Accept: application/json).
    Route::get('/realtime/token', [RealtimeTokenController::class, 'issue'])
        ->middleware('role:admin,teacher,station')
        ->name('realtime.token');

    // Student self-service: own balance, own deposits, shared board.
    Route::middleware('role:student')->group(function () {
        Route::get('/student', [StudentController::class, 'dashboard'])->name('student.dashboard');
        Route::get('/student/history', [StudentController::class, 'history'])->name('student.history');
        Route::get('/student/leaderboard', [StudentController::class, 'leaderboard'])->name('student.leaderboard');
    });

    // Teacher reporting: scoped by StudentScope inside every action.
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/teacher', [TeacherDashboardController::class, 'dashboard'])->name('teacher.dashboard');
        Route::get('/teacher/classes/{class}', [TeacherDashboardController::class, 'showClass'])->name('teacher.class');
        Route::get('/teacher/students/{student}', [TeacherDashboardController::class, 'showStudent'])->name('teacher.student');
        Route::get('/teacher/live', [TeacherDashboardController::class, 'live'])->name('teacher.live');
        Route::get('/teacher/export', [TeacherExportController::class, 'page'])->name('teacher.export');

        Route::get('/teacher/export.csv', [TeacherExportController::class, 'csv'])->name('teacher.export.csv');
        Route::get('/teacher/export.pdf', [TeacherExportController::class, 'pdf'])->name('teacher.export.pdf');
        Route::get('/teacher/students/{student}/export.csv', [TeacherExportController::class, 'studentCsv'])
            ->name('teacher.student.export.csv');
        Route::get('/teacher/students/{student}/export.pdf', [TeacherExportController::class, 'studentPdf'])
            ->name('teacher.student.export.pdf');
    });

    // Admin desks: admin-only, unstyled forms in this batch.
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        // Roster.
        Route::get('/students', [AdminStudentController::class, 'index'])->name('students');
        Route::post('/students', [AdminStudentController::class, 'store'])->name('students.store');
        Route::post('/students/import', [AdminStudentController::class, 'import'])->name('students.import');
        Route::delete('/students/{student}', [AdminStudentController::class, 'destroy'])->name('students.destroy');

        Route::get('/classes', [AdminClassController::class, 'index'])->name('classes');
        Route::post('/classes', [AdminClassController::class, 'store'])->name('classes.store');
        Route::put('/classes/{class}/teachers', [AdminClassController::class, 'teachers'])->name('classes.teachers');

        Route::get('/staff', [AdminStaffController::class, 'index'])->name('staff');
        Route::post('/staff', [AdminStaffController::class, 'store'])->name('staff.store');

        // Stations and keys.
        Route::get('/stations', [AdminStationController::class, 'index'])->name('stations');
        Route::post('/stations', [AdminStationController::class, 'store'])->name('stations.store');
        Route::post('/stations/{reader}/rotate-key', [AdminStationController::class, 'rotateKey'])->name('stations.rotate');
        Route::delete('/stations/{reader}', [AdminStationController::class, 'destroy'])->name('stations.destroy');

        // Pairing desk.
        Route::get('/pairing', [AdminPairingController::class, 'index'])->name('pairing');
        Route::post('/pairing/arm', [AdminPairingController::class, 'arm'])->name('pairing.arm');
        Route::get('/pairing/status', [AdminPairingController::class, 'status'])->name('pairing.status');

        // Credentials.
        Route::post('/cards/{card}/revoke', [AdminCardController::class, 'revoke'])->name('cards.revoke');
        Route::delete('/cards/{card}', [AdminCardController::class, 'destroy'])->name('cards.destroy');

        // Settings.
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    });
});

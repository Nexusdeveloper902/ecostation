<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The pairing desk: read the armed window and recent completions, arm a
 * student (the write path) and serve the JSON status the desk polls.
 * The device half of the flow stays POST /api/v1/admin/cards/pair.
 */
class PairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function index(): View
    {
        return view('admin.pairing', [
            'status' => $this->pairings->statusPayload(),
            'students' => Student::query()
                ->with(['schoolClass', 'cards'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function arm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
        ]);

        $student = Student::findOrFail((int) $data['student_id']);
        $this->pairings->arm($student);

        return redirect()->route('admin.pairing')
            ->with('status', 'Ventana armada para '.$student->name.'.');
    }

    public function status(): JsonResponse
    {
        return response()->json(['status' => 'ok'] + $this->pairings->statusPayload());
    }
}

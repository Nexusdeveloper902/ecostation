<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The settings desk. GET renders every effective value; PUT applies a
 * partial batch through SettingsService (the single validation/persistence
 * truth), so runtime consumers pick the change up on the next request.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(): View
    {
        return view('admin.settings', ['values' => $this->settings->all()]);
    }

    public function update(Request $request): RedirectResponse
    {
        try {
            $this->settings->setMany((array) $request->input('settings', []));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('status', 'Configuración guardada.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    /** Session key holding the real (admin) user id while impersonating. */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Start impersonating a customer account. Admin-only (the route sits in
     * the 'admin' middleware group).
     *
     * Deliberate limits: an admin can never impersonate another admin (that
     * would be a lateral privilege move with no audit value), never
     * themselves, and never a deactivated account — the resulting session
     * would immediately be rejected by EnsureUserIsActive and look like a
     * broken login. Nesting is blocked too: stop the current impersonation
     * before starting another, so `impersonator_id` always points at a real
     * admin rather than a chain.
     */
    public function store(Request $request, int $userId): RedirectResponse
    {
        $admin = $request->user();
        $target = User::find($userId);

        if ($request->session()->has(self::SESSION_KEY)) {
            return back()->withErrors(['impersonate' => 'Sie befinden sich bereits in einer Sitzungsübernahme.']);
        }

        if (! $target) {
            return back()->withErrors(['impersonate' => 'Benutzer wurde nicht gefunden.']);
        }

        if ($target->is($admin)) {
            return back()->withErrors(['impersonate' => 'Sie können sich nicht selbst übernehmen.']);
        }

        if ($target->isAdmin()) {
            return back()->withErrors(['impersonate' => 'Administratorkonten können nicht übernommen werden.']);
        }

        if (! $target->is_active) {
            return back()->withErrors(['impersonate' => 'Deaktivierte Konten können nicht übernommen werden.']);
        }

        Log::info('admin.impersonation.started', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'target_id' => $target->id,
            'target_email' => $target->email,
            'ip' => $request->ip(),
        ]);

        $request->session()->regenerate();
        Auth::login($target);
        $request->session()->put(self::SESSION_KEY, $admin->id);

        return to_route($target->homeRouteName());
    }

    /**
     * Return to the admin account. Reachable by the impersonated (non-admin)
     * session, so it only needs 'auth' — the session key is the authority,
     * and a session without one can't end anything.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get(self::SESSION_KEY);

        if (! $impersonatorId) {
            return to_route('dashboard');
        }

        $admin = User::find($impersonatorId);

        if (! $admin || ! $admin->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        Log::info('admin.impersonation.stopped', [
            'admin_id' => $admin->id,
            'target_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();
        Auth::login($admin);

        return to_route('admin.customers.index');
    }
}

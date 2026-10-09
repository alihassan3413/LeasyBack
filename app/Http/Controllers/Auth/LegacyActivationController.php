<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use App\Support\LegacyImport\LegacyActivation;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link in the Base44 activation mail: the user sets the password their
 * imported account never had, then signs in normally — MFA and everything
 * else as for any other account.
 *
 * NewPasswordController with the `legacy_activation` broker: the same page,
 * the same password rules and the same reset, but tokens from their own table
 * with a longer life, so this link and a normal reset link never interfere.
 * Setting the password fires PasswordReset, which MarkLegacyActivationComplete
 * records.
 */
class LegacyActivationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'submitRoute' => 'legacy-activation.store',
            'heading' => 'Konto aktivieren',
            'intro' => 'Willkommen im neuen LeasyBack-Portal. Legen Sie Ihr Passwort fest — danach melden Sie sich wie gewohnt an.',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', ...AppServiceProvider::passwordRules()],
        ]);

        $status = Password::broker(LegacyActivation::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PasswordReset) {
            return to_route('login')
                ->with('status', 'Ihr Konto ist aktiviert. Bitte melden Sie sich mit Ihrem neuen Passwort an.')
                ->with('success', 'Konto aktiviert.');
        }

        throw ValidationException::withMessages([
            'email' => [match ($status) {
                Password::InvalidToken => 'Dieser Aktivierungslink ist ungültig, abgelaufen oder wurde bereits verwendet. Über „Passwort vergessen" erhalten Sie jederzeit einen neuen Link.',
                Password::InvalidUser => 'Für diese E-Mail-Adresse konnte kein Konto gefunden werden.',
                default => 'Das Konto konnte nicht aktiviert werden. Bitte versuchen Sie es erneut.',
            }],
        ]);
    }
}

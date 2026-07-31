<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Show the password reset page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        //
        // Bug found and fixed here: __($status) previously passed Laravel's raw
        // passwords.* translation key straight to __() (e.g. 'passwords.token').
        // Since this app has no lang/ directory, __() has nothing to translate
        // it against and returns the key verbatim — a real user would have seen
        // the literal string "passwords.token" instead of a message. Mapped to
        // explicit German text instead, consistent with the rest of the app's
        // no-lang-directory convention (see docs/AUTH_MODULE.md).
        if ($status == Password::PasswordReset) {
            return to_route('login')->with('status', 'Dein Passwort wurde erfolgreich zurückgesetzt.');
        }

        throw ValidationException::withMessages([
            'email' => [match ($status) {
                Password::InvalidToken => 'Dieser Link zum Zurücksetzen ist ungültig oder abgelaufen. Bitte fordere einen neuen an.',
                Password::InvalidUser => 'Für diese E-Mail-Adresse konnte kein Account gefunden werden.',
                Password::ResetThrottled => 'Zu viele Versuche. Bitte warte einen Moment und versuche es erneut.',
                default => 'Das Passwort konnte nicht zurückgesetzt werden. Bitte versuche es erneut.',
            }],
        ]);
    }
}

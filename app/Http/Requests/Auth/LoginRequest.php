<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // The is_active constraint is folded into the same credential lookup
        // (not checked separately after success) so a deactivated account
        // fails identically to a wrong password — no enumeration.
        $credentials = $this->only('email', 'password');
        $credentials[] = fn ($query) => $query->where('is_active', true);

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // TEMPORARY login debugging — remove once the admin-login issue is
            // resolved. Records what actually reached the server (never the
            // password itself, only its length).
            if (app()->environment('local')) {
                $submittedEmail = (string) $this->input('email');
                $user = User::where('email', $submittedEmail)->first();

                Log::debug('login failed', [
                    'email_raw' => '['.$submittedEmail.']',
                    'email_length' => strlen($submittedEmail),
                    'password_length' => strlen((string) $this->input('password')),
                    'user_found' => (bool) $user,
                    'is_active' => $user?->is_active,
                    'password_matches' => $user ? Hash::check((string) $this->input('password'), $user->password) : null,
                ]);
            }

            // Bug found and fixed here: trans('auth.failed') passes a raw
            // translation key to trans(). This app has no lang/ directory
            // (see docs/AUTH_MODULE.md), so trans() had nothing to translate
            // it against and returned the key verbatim — a real user would
            // have seen the literal string "auth.failed" on every wrong
            // password, not an actual message.
            throw ValidationException::withMessages([
                'email' => 'Diese Anmeldedaten stimmen nicht mit unseren Aufzeichnungen überein.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        // Same trans()-with-no-lang-directory bug as above: this used to
        // render as the literal string "auth.throttle" with the :seconds/
        // :minutes placeholders never substituted (placeholder substitution
        // only happens against a *found* translation line, not the raw key).
        throw ValidationException::withMessages([
            'email' => "Zu viele Anmeldeversuche. Bitte versuche es in {$seconds} Sekunden erneut.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}

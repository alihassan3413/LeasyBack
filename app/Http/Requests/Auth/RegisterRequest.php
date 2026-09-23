<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserType;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Modules\UserProfile\B2B\Services\B2bInvitationService;
use App\Rules\CaseInsensitiveUniqueEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    private ?B2bInvitation $resolvedInvitation = null;

    private bool $invitationResolved = false;

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
            // Optional: RegisteredUserController falls back to the email's
            // local part when omitted (matching Api\RegisterRequest).
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                new CaseInsensitiveUniqueEmail,
            ],
            'password' => ['required', Password::defaults()],
            // Admin is deliberately excluded from registrableValues() — it
            // can never be reached through this form no matter what a client
            // sends, since anything outside this allow-list fails validation.
            //
            // Dropped entirely when registering from an invitation, rather
            // than made conditionally-required: joining an existing company
            // makes the account a Firmenkunde, and the controller ignores
            // whatever was posted. `required_without` was not enough — the
            // hidden select still posts an empty value, which
            // ConvertEmptyStringsToNull turns into null, and `string`/`in`
            // then failed on a field the invited user cannot see.
            'user_type' => $this->invitation() !== null
                ? ['nullable']
                : ['required', 'string', Rule::in(UserType::registrableValues())],
            'invitation' => ['nullable', 'string'],
        ];
    }

    /**
     * The address has to be the invited one.
     *
     * The token proves the holder received mail at that address and nothing
     * else — registering a *different* address with it would turn a forwarded
     * invitation into an account in someone else's name. B2bInvitationService
     * ::accept() refuses the mismatch too; catching it here makes it a field
     * error on the form instead of an exception after the account exists.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $invitation = $this->invitation();

                if ($invitation === null) {
                    return;
                }

                if (Str::lower((string) $this->input('email')) !== Str::lower($invitation->email)) {
                    $validator->errors()->add('email', 'Diese Einladung gilt für '.$invitation->email.'.');
                }
            },
        ];
    }

    /**
     * The pending invitation this registration is joining, or null for an
     * ordinary sign-up. Resolved once — `after()` and the controller both ask.
     */
    public function invitation(): ?B2bInvitation
    {
        if ($this->invitationResolved) {
            return $this->resolvedInvitation;
        }

        $this->invitationResolved = true;
        $token = (string) $this->input('invitation', '');

        if ($token !== '') {
            $invitation = app(B2bInvitationService::class)->findAnyByToken($token);
            $this->resolvedInvitation = $invitation?->isPending() === true ? $invitation : null;
        }

        return $this->resolvedInvitation;
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_type.required' => 'Bitte wählen Sie eine Kontoart aus.',
            'user_type.in' => 'Bitte wählen Sie eine gültige Kontoart aus.',
        ];
    }
}

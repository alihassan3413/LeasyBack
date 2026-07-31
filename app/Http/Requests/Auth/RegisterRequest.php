<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserType;
use App\Rules\CaseInsensitiveUniqueEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'user_type' => ['required', 'string', Rule::in(UserType::registrableValues())],
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_type.in' => 'Bitte wählen Sie eine gültige Kontoart aus.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api;

use App\Enums\UserType;
use App\Rules\CaseInsensitiveUniqueEmail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                new CaseInsensitiveUniqueEmail,
            ],
            'user_type' => [
                'required',
                'string',
                Rule::in(UserType::registrableValues()),
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Extra Scribe documentation (description/example) for each body
     * parameter, layered on top of rules() above — Scribe already infers
     * type/required/enum from the validation rules themselves.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'user_email' => [
                'description' => 'The account email address. Must be unique case-insensitively (e.g. `A@x.com` and `a@x.com` collide).',
                'example' => 'jane.doe@example.com',
            ],
            'user_type' => [
                'description' => 'The account type. Admin cannot be self-registered through this endpoint.',
                'example' => 'Privatkunde',
            ],
            'password' => [
                'description' => 'Plaintext password, 8–128 characters. Hashed with Argon2id before storage.',
                'example' => 'correct-horse-battery-staple',
            ],
            'name' => [
                'description' => 'Optional display name. If omitted, defaults to the local part of the email address (before the `@`).',
                'example' => 'Jane Doe',
            ],
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
            'user_type.in' => 'Invalid user type. Allowed: '.implode(', ', UserType::registrableValues()),
            'user_email.email' => 'Please provide a valid email address.',
            'password.min' => 'Password must be at least 8 characters.',
        ];
    }

    /**
     * Return JSON validation errors for API responses.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}

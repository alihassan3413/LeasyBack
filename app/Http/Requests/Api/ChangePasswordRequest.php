<?php

namespace App\Http\Requests\Api;

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => [
                'required',
                'string',
            ],
            // Same policy as the web screens (AppServiceProvider::passwordRules()).
            'new_password' => ['required', 'string', ...AppServiceProvider::passwordRules(), 'different:current_password'],
        ];
    }

    /**
     * Extra Scribe documentation (description/example) for each body
     * parameter, layered on top of rules() above.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'current_password' => [
                'description' => 'The user\'s current password, for verification.',
                'example' => 'correct-horse-battery-staple',
            ],
            'new_password' => [
                'description' => 'The new password, 12–128 characters, must differ from the current password.',
                'example' => 'another-horse-battery-staple',
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
            'new_password.min' => 'New password must be at least '.AppServiceProvider::PASSWORD_MIN_LENGTH.' characters.',
            'new_password.mixed' => 'New password must contain upper and lower case letters.',
            'new_password.numbers' => 'New password must contain at least one number.',
            'new_password.different' => 'New password must be different from current password.',
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

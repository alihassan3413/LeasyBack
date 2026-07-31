<?php

namespace App\Http\Requests\Api;

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
            'new_password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'different:current_password',
            ],
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
                'description' => 'The new password, 8–128 characters, must differ from the current password.',
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
            'new_password.min' => 'New password must be at least 8 characters.',
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

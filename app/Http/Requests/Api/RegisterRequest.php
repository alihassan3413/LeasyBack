<?php

namespace App\Http\Requests\Api;

use App\Enums\UserType;
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
                'email:rfc,dns',
                'max:255',
                'unique:users,email',
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
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_type.in' => 'Invalid user type. Allowed: '.implode(', ', UserType::registrableValues()),
            'user_email.unique' => 'This email is already registered.',
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

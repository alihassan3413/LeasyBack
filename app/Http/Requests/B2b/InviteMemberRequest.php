<?php

namespace App\Http\Requests\B2b;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Inviting someone is the member-access shape plus the address to send it to.
 */
class InviteMemberRequest extends MemberAccessRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    public function email(): string
    {
        return $this->validated('email');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'email.required' => 'Bitte geben Sie eine E-Mail-Adresse an.',
            'email.email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
        ];
    }
}

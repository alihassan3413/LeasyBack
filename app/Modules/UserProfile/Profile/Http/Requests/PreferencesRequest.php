<?php

namespace App\Modules\UserProfile\Profile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'preference_id' => [$this->isMethod('put') ? 'required' : 'prohibited', 'uuid'],
            'timezone' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_+\-]+(?:\/[A-Za-z0-9_+\-]+)+$/'],
            'sprache' => ['required', 'in:de,en,fr,es,it'],
            'benachrichtigungseinstellungen_push' => ['required', 'boolean'],
            'benachrichtigungseinstellungen_email' => ['required', 'boolean'],
        ];
    }
}

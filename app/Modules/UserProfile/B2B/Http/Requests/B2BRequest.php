<?php

namespace App\Modules\UserProfile\B2B\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class B2BRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $create = $this->isMethod('post');
        $required = $create ? 'required' : 'sometimes';

        return [
            'contact_id' => ['sometimes', 'nullable', 'uuid'],
            'address_id' => ['sometimes', 'nullable', 'uuid'],
            'company_name' => [$required, 'string', 'max:255'],
            'vat_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'address' => [$create ? 'required' : 'sometimes', 'array'],
            'address.street' => ['required_with:address', 'string', 'max:255'],
            'address.number' => ['required_with:address', 'string', 'max:50'],
            'address.additional_address' => ['nullable', 'string', 'max:255'],
            'address.zip_code' => ['required_with:address', 'string', 'max:20'],
            'address.city' => ['required_with:address', 'string', 'max:100'],
            'address.country' => ['required_with:address', 'string', 'max:100'],
            'address.longitude' => ['sometimes', 'numeric'],
            'address.latitude' => ['sometimes', 'numeric'],
            'contact' => [$create ? 'required' : 'sometimes', 'array'],
            'contact.salutation' => ['nullable', 'string', 'max:50'],
            'contact.first_name' => ['required_with:contact', 'string', 'max:100'],
            'contact.last_name' => ['required_with:contact', 'string', 'max:100'],
            'contact.international_prefix' => ['required_with:contact', 'string', 'max:10'],
            'contact.primary_phone_number' => ['required_with:contact', 'string', 'max:50'],
            'contact.phone_numbers' => ['sometimes', 'array', 'max:20'],
            'contact.phone_numbers.*.international_prefix' => ['required', 'string', 'max:10'],
            'contact.phone_numbers.*.phone_number' => ['required', 'string', 'max:50'],
            'contact.address_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}

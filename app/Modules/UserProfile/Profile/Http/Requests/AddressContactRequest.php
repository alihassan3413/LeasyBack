<?php

namespace App\Modules\UserProfile\Profile\Http\Requests;

use App\Models\LeasybackUserProfile;
use Illuminate\Foundation\Http\FormRequest;

class AddressContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->isMethod('put')
            ? $user->can('updateProfile', LeasybackUserProfile::class)
            : $user->can('createProfile', LeasybackUserProfile::class);
    }

    public function rules(): array
    {
        return [
            'address_id' => [$this->isMethod('put') ? 'required' : 'prohibited', 'uuid'],
            'contact_id' => [$this->isMethod('put') ? 'required' : 'prohibited', 'uuid'],
            'address' => ['required', 'array'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.number' => ['required', 'string', 'max:50'],
            'address.additional_address' => ['nullable', 'string', 'max:255'],
            'address.zip_code' => ['required', 'string', 'max:20'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.country' => ['required', 'string', 'max:100'],
            'address.longitude' => ['sometimes', 'numeric'],
            'address.latitude' => ['sometimes', 'numeric'],
            'contact' => ['required', 'array'],
            'contact.salutation' => ['required', 'string', 'max:50'],
            'contact.first_name' => ['required', 'string', 'max:100'],
            'contact.last_name' => ['required', 'string', 'max:100'],
            'phones' => ['required', 'array', 'max:20'],
            'phones.*.international_prefix' => ['required', 'string', 'max:10'],
            'phones.*.phone_number' => ['required', 'string', 'max:50'],
        ];
    }
}

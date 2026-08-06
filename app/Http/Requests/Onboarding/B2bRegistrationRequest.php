<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\UserType;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for the session-authenticated Firmenkunde (B2B) registration
 * form. Deliberately separate from the API's B2BRequest: this one speaks the
 * Inertia form's shape (a flat `phones` array like the B2C onboarding's
 * AddressContactRequest, plus an uploaded `logo` file) rather than the API's
 * `contact.international_prefix` / `contact.primary_phone_number` pair —
 * B2bRegistrationController maps between the two before calling B2BService.
 */
class B2bRegistrationRequest extends FormRequest
{
    /** Countries the app currently serves; mirrors the frontend's dropdown. */
    private const COUNTRIES = ['Deutschland', 'Österreich', 'Schweiz'];

    /** Dialing prefixes offered by PhoneNumberFieldset. */
    private const DIALING_PREFIXES = ['+49', '+43', '+41'];

    private const SALUTATIONS = ['Herr', 'Frau', 'Divers'];

    /** Germany uses 5-digit ZIP codes, Austria and Switzerland 4. */
    private const ZIP_LENGTH_BY_COUNTRY = [
        'Deutschland' => 5,
        'Österreich' => 4,
        'Schweiz' => 4,
    ];

    /**
     * Either a Firmenkunde — who registers a company here and edits it from
     * "Mein Konto" afterwards — or anyone currently acting as a company they
     * belong to. The latter covers a private account that accepted a B2B
     * invitation: it edits company master data through this same request, and
     * gating on `user_type` alone would 403 it.
     *
     * This is not the permission check. Editing goes through the
     * `b2b.can:company.manage` route middleware, which is what decides whether
     * this member may change the company's data at all.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->user_type === UserType::Firmenkunde
            || app(B2bContext::class)->actsAsCompany($user);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            // Format follows the EU VAT identification number layout: a
            // two-letter country code plus 2–12 alphanumerics. Kept optional
            // because small Gewerbe registrations legitimately have none yet.
            'vat_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z]{2}[A-Za-z0-9]{2,12}$/'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:255'],

            'address' => ['required', 'array'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.number' => ['required', 'string', 'max:50'],
            'address.additional_address' => ['nullable', 'string', 'max:255'],
            'address.zip_code' => ['required', 'string', 'max:20', 'regex:/^\d{4,5}$/'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.country' => ['required', 'string', Rule::in(self::COUNTRIES)],

            'contact' => ['required', 'array'],
            'contact.salutation' => ['required', 'string', Rule::in(self::SALUTATIONS)],
            'contact.first_name' => ['required', 'string', 'max:100'],
            'contact.last_name' => ['required', 'string', 'max:100'],

            'phones' => ['required', 'array', 'min:1', 'max:20'],
            'phones.*.international_prefix' => ['required', 'string', Rule::in(self::DIALING_PREFIXES)],
            'phones.*.phone_number' => ['required', 'string', 'regex:/^\d{4,14}$/'],

            // The logo is optional; the client never supplies a path or URL —
            // B2bRegistrationController stores the file and derives both.
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * ZIP-code length depends on the selected country, which a single regex
     * can't express — checked here so the message points at the ZIP field.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $country = $this->input('address.country');
                $zip = $this->input('address.zip_code');
                $expected = self::ZIP_LENGTH_BY_COUNTRY[$country] ?? null;

                // Only meaningful once the field itself passed its own rules —
                // otherwise the user would see two errors for one mistake.
                if ($expected === null || ! is_string($zip) || preg_match('/^\d+$/', $zip) !== 1) {
                    return;
                }

                if (strlen($zip) !== $expected) {
                    $validator->errors()->add(
                        'address.zip_code',
                        "Die PLZ für {$country} muss {$expected} Ziffern haben."
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_name.required' => 'Bitte geben Sie den Firmennamen an.',
            'vat_id.regex' => 'Bitte geben Sie eine gültige USt-IdNr. an (z. B. DE123456789).',
            'contact_email.required' => 'Bitte geben Sie eine E-Mail-Adresse für Anfragen an.',
            'contact_email.email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
            'address.street.required' => 'Bitte geben Sie die Straße an.',
            'address.number.required' => 'Bitte geben Sie die Hausnummer an.',
            'address.zip_code.required' => 'Bitte geben Sie die PLZ an.',
            'address.zip_code.regex' => 'Die PLZ darf nur Ziffern enthalten.',
            'address.city.required' => 'Bitte geben Sie den Ort an.',
            'address.country.in' => 'Bitte wählen Sie ein gültiges Land aus.',
            'contact.salutation.required' => 'Bitte wählen Sie eine Anrede aus.',
            'contact.salutation.in' => 'Bitte wählen Sie eine gültige Anrede aus.',
            'contact.first_name.required' => 'Bitte geben Sie den Vornamen an.',
            'contact.last_name.required' => 'Bitte geben Sie den Nachnamen an.',
            'phones.required' => 'Bitte geben Sie mindestens eine Telefonnummer an.',
            'phones.*.international_prefix.in' => 'Bitte wählen Sie eine gültige Vorwahl aus.',
            'phones.*.phone_number.required' => 'Bitte geben Sie eine Telefonnummer an.',
            'phones.*.phone_number.regex' => 'Bitte geben Sie eine gültige Telefonnummer an (4–14 Ziffern).',
            'logo.mimes' => 'Bitte laden Sie nur JPG- oder PNG-Dateien hoch.',
            'logo.max' => 'Die Datei darf maximal 8 MB groß sein.',
        ];
    }
}

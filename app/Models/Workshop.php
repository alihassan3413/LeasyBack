<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Workshop extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'workshop_name', 'logo_url', 'logo_path', 'contact_email', 'has_vat_id',
        'vat_id', 'iban', 'bic', 'account_holder', 'packages_selected',
        'terms_accepted', 'privacy_accepted', 'address_id', 'street', 'number',
        'additional_address', 'zip_code', 'city', 'country', 'longitude',
        'latitude', 'contact_id', 'salutation', 'first_name', 'last_name',
        'international_prefix', 'primary_phone_number', 'phone_numbers',
        'imprint_text', 'services_offered',
    ];

    protected $hidden = ['iban', 'bic', 'account_holder'];

    protected function casts(): array
    {
        return [
            'has_vat_id' => 'boolean', 'terms_accepted' => 'boolean',
            'privacy_accepted' => 'boolean', 'iban' => 'encrypted',
            'bic' => 'encrypted', 'account_holder' => 'encrypted',
            'phone_numbers' => 'array', 'services_offered' => 'array',
            'longitude' => 'float', 'latitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

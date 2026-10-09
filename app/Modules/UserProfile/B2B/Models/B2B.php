<?php

namespace App\Modules\UserProfile\B2B\Models;

use App\Models\User;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class B2B extends Model
{
    /**
     * Where an uploaded logo is served, built when it is read: the file path
     * under the public disk, root-relative, so it shows on whatever host
     * serves the page. The absolute URL stored at upload froze APP_URL as it
     * was then. A logo given only as a URL (no uploaded file) is kept as is.
     */
    public static function publicLogoUrl(?string $path, ?string $storedUrl): ?string
    {
        if ($path !== null && trim($path) !== '') {
            return '/storage/'.ltrim($path, '/');
        }

        return $storedUrl !== null && $storedUrl !== '' ? $storedUrl : null;
    }

    /** publicLogoUrl() for a raw query selecting from the b2b table aliased $alias. */
    public static function logoUrlSelect(string $alias = 'b'): Expression
    {
        return DB::raw("CASE WHEN {$alias}.logo_path IS NOT NULL AND {$alias}.logo_path <> '' THEN '/storage/' || {$alias}.logo_path ELSE {$alias}.logo_url END AS logo_url");
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (?string $value, array $attributes) => self::publicLogoUrl($attributes['logo_path'] ?? null, $value));
    }

    protected $table = 'b2b';

    protected $primaryKey = 'b2b_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'b2b_id',
        'contact_id',
        'address_id',
        'company_name',
        'vat_id',
        'logo_url',
        'logo_path',
        'contact_email',
        'is_active',
        'service_fee_amount',
        'service_fee_effective_from',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'service_fee_amount' => 'decimal:2',
        'service_fee_effective_from' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->b2b_id)) {
                $model->b2b_id = (string) Str::uuid();
            }

            // §13: 1 January 2026 is the start date for *existing* customers
            // (the column default). A company created now starts its
            // agreement today.
            if (empty($model->service_fee_effective_from)) {
                $model->service_fee_effective_from = now()->toDateString();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id', 'address_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_b2b', 'b2b_id', 'user_id')
            ->withPivot('role');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'b2b_id', 'b2b_id');
    }
}

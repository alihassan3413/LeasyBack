<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * Deliberately excludes `user_type` and `is_active`: both are
     * privilege-relevant (self-registration as Admin, account
     * activation state) and must only ever be set through explicit,
     * reviewed code paths (e.g. AuthController::register,
     * AdminUserSeeder), never through mass-assigned request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'zip_code',
        'country',
        'avatar_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => UserType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->user_type === UserType::Admin;
    }

    /**
     * Check if user is a specific type.
     */
    public function isType(UserType $type): bool
    {
        return $this->user_type === $type;
    }

    /**
     * The B2B companies this user belongs to.
     */
    public function b2bCompanies(): BelongsToMany
    {
        return $this->belongsToMany(B2B::class, 'user_b2b', 'user_id', 'b2b_id')
            ->withPivot('role');
    }

    /**
     * Vehicles owned directly by this user (B2C).
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'b2c_user_id', 'id');
    }

    /**
     * The Leasyback user profile (address/contact linked profile).
     */
    public function leasybackProfile(): HasOne
    {
        return $this->hasOne(LeasybackUserProfile::class, 'user_id', 'id');
    }

    /**
     * User preferences.
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class, 'user_id', 'id');
    }
}

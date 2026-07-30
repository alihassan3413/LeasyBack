<?php

namespace App\Modules\UserProfile\Profile\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The "user_profile" table from the Rust backend.
 * Named LeasybackUserProfile to avoid confusion with Laravel's User model.
 */
class LeasybackUserProfile extends Model
{
    protected $table = 'user_profiles';
    protected $primaryKey = 'profile_id';

    protected $fillable = [
        'email',
        'user_id',
        'contact_id',
        'is_admin',
        'image_url',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }
}

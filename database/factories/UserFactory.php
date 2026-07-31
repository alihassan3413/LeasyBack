<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * `user_type`/`is_active` are set explicitly here even though the DB
     * columns already default to the same values (Privatkunde / true):
     * Eloquent's create() does not re-fetch DB-generated column defaults
     * into the in-memory model afterward, so an in-memory factory-created
     * instance would otherwise have these attributes genuinely unset
     * (null after casting) until the model is re-fetched from the
     * database — unlike a real request, which always re-fetches the
     * authenticated user fresh. Sets them explicitly so factory instances
     * behave like a real, freshly-loaded user everywhere, including under
     * `actingAs()` in tests (which binds the in-memory instance directly).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'user_type' => UserType::Privatkunde,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Mail\RegistrationWelcome;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group(
    name: 'Authentication',
    description: "Registration, login, logout, current-user, and password-change for the leasyback API.\n\n"
        .'Authenticated via Laravel Sanctum bearer tokens (not JWT) — call `POST /api/auth/login` to obtain a '
        .'token, then send `Authorization: Bearer {token}` on subsequent requests.'."\n\n"
        .'Reachable at both `/api/auth/*` and `/auth/*` (an unprefixed compatibility alias for the legacy '
        .'leasyback_web SPA) — same controller, same behavior.'
)]
class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * POST /api/auth/register
     */
    #[Endpoint(title: 'Register', description: 'Create a new account. Defaults to no auth required.')]
    #[Unauthenticated]
    #[Response(
        status: 200,
        content: [
            'ok' => true,
            'data' => [
                'user_id' => 1,
                'user_email' => 'jane.doe@example.com',
                'user_type' => 'Privatkunde',
                'created_at' => '2026-07-31T12:00:00.000000Z',
            ],
            'message' => 'User registered',
        ],
        description: 'Registered successfully. Note: `user_id` here is the internal database primary key '
            .'(bigint) — there is currently no separate public identifier on the User model.'
    )]
    #[Response(
        status: 422,
        content: [
            'ok' => false,
            'data' => null,
            'message' => 'Validation failed.',
            'errors' => ['user_email' => ['This email is already registered.']],
        ],
        description: 'Email already registered (case-insensitive match).'
    )]
    #[Response(
        status: 422,
        content: [
            'ok' => false,
            'data' => null,
            'message' => 'Validation failed.',
            'errors' => ['password' => ['Password must be at least 8 characters.']],
        ],
        description: 'General validation failure (missing/invalid field).'
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'] ?? explode('@', $validated['user_email'])[0],
            'email' => $validated['user_email'],
            'password' => Hash::make($validated['password']),
        ]);

        // `user_type` is intentionally not mass-assignable (see User::$fillable) —
        // set explicitly here, the one place a new account's type is decided.
        $user->user_type = $validated['user_type'];
        $user->save();

        // Send welcome email (non-blocking: failure does not break registration)
        try {
            Mail::to($user->email)->queue(new RegistrationWelcome($user));
        } catch (\Throwable $e) {
            Log::error('Registration email failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_type' => $user->user_type,
                'created_at' => $user->created_at->toISOString(),
            ],
            'message' => 'User registered',
        ]);
    }

    /**
     * Login an existing user.
     *
     * POST /api/auth/login
     */
    #[Endpoint(title: 'Login', description: 'Authenticate and obtain a Sanctum bearer token.')]
    #[Unauthenticated]
    #[Response(
        status: 200,
        content: [
            'ok' => true,
            'data' => [
                'token' => '1|abcdefghijklmnopqrstuvwxyz0123456789ABCDEF',
                'user_id' => 1,
                'user_type' => 'Privatkunde',
            ],
            'message' => 'Login successful.',
        ],
        description: 'Login succeeded. `token` is a Sanctum plain-text token — send it as '
            .'`Authorization: Bearer {token}` on subsequent requests. There is no `device_name` parameter; '
            .'every login creates a token literally named `auth-token`.'
    )]
    #[Response(
        status: 401,
        content: [
            'ok' => false,
            'data' => null,
            'message' => 'Invalid credentials.',
        ],
        description: 'Wrong password, unknown email, AND a deactivated account all return this exact same '
            .'response — deliberately, so a caller who has not yet proven ownership of the account cannot '
            .'distinguish "wrong password" from "account deactivated" from "no such account" (no enumeration).'
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($validated['user_email'])])
            ->first();

        // Same generic response for wrong password, nonexistent email, AND a
        // deactivated account — the caller hasn't proven ownership yet at this
        // point, so none of those cases may be distinguishable (no enumeration).
        if (! $user || ! Hash::check($validated['password'], $user->password) || ! $user->is_active) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Revoke old tokens if needed (single device login)
        // $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'data' => [
                'token' => $token,
                'user_id' => $user->id,
                'user_type' => $user->user_type,
            ],
            'message' => 'Login successful.',
        ]);
    }

    /**
     * Change the authenticated user's password.
     *
     * POST /api/auth/changepassword
     */
    #[Endpoint(title: 'Change password', description: 'Change the authenticated user\'s password.')]
    #[Response(
        status: 200,
        content: [
            'ok' => true,
            'data' => null,
            'message' => 'Password updated successfully.',
        ],
        description: 'Password changed successfully.'
    )]
    #[Response(
        status: 422,
        content: [
            'ok' => false,
            'data' => null,
            'message' => 'Current password is incorrect.',
        ],
        description: 'The supplied current_password does not match.'
    )]
    #[Response(
        status: 422,
        content: [
            'ok' => false,
            'data' => null,
            'message' => 'Validation failed.',
            'errors' => ['new_password' => ['New password must be different from current password.']],
        ],
        description: 'General validation failure (too short, or same as current password).'
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        // Optionally revoke all tokens except current
        // $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'ok' => true,
            'data' => null,
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Logout the authenticated user (revoke current token).
     *
     * POST /api/auth/logout
     */
    #[Endpoint(title: 'Logout', description: 'Revoke the Sanctum token used to authenticate the current request.')]
    #[Response(
        status: 200,
        content: [
            'ok' => true,
            'data' => null,
            'message' => 'Logged out successfully.',
        ],
        description: 'Only the current token is revoked; any other tokens issued to the user remain valid.'
    )]
    public function logout(): JsonResponse
    {
        $user = request()->user();
        $user->currentAccessToken()->delete();

        return response()->json([
            'ok' => true,
            'data' => null,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get the authenticated user profile.
     *
     * GET /api/auth/me
     */
    #[Endpoint(title: 'Current user', description: 'Get the authenticated user\'s profile.')]
    #[Response(
        status: 200,
        content: [
            'ok' => true,
            'data' => [
                'user_id' => 1,
                'user_email' => 'jane.doe@example.com',
                'user_type' => 'Privatkunde',
                'name' => 'Jane Doe',
                'created_at' => '2026-07-31T12:00:00.000000Z',
            ],
            'message' => null,
        ],
        description: 'Note: `user_id` is the internal database primary key (bigint), returned directly — '
            .'there is currently no separate public identifier on the User model.'
    )]
    public function me(): JsonResponse
    {
        $user = request()->user();

        return response()->json([
            'ok' => true,
            'data' => [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_type' => $user->user_type,
                'name' => $user->name,
                'created_at' => $user->created_at->toISOString(),
            ],
            'message' => null,
        ]);
    }
}

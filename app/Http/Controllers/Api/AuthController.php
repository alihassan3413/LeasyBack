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

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'] ?? explode('@', $validated['user_email'])[0],
            'email' => $validated['user_email'],
            'user_type' => $validated['user_type'],
            'password' => Hash::make($validated['password']),
        ]);

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
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($validated['user_email'])])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserProfileController extends Controller
{
    /**
     * Get the authenticated user's full profile.
     *
     * GET /api/profile
     *
     * Returns all user profile data including:
     * - user_id, name, email, user_type
     * - avatar_url (if uploaded)
     * - phone, address, city, zip_code, country
     * - created_at, updated_at
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'data' => $this->formatProfile($user),
            'message' => null,
        ]);
    }

    /**
     * Update the authenticated user's profile.
     *
     * PUT /api/profile
     *
     * Updatable fields:
     * - name, phone, address, city, zip_code, country
     * - Email change requires current password confirmation
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // If email is being changed, verify current password
        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            if (! isset($validated['current_password'])) {
                return response()->json([
                    'ok' => false,
                    'data' => null,
                    'message' => 'Current password is required to change email.',
                ], 422);
            }

            if (! Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'ok' => false,
                    'data' => null,
                    'message' => 'Current password is incorrect.',
                ], 422);
            }

            $user->email = $validated['email'];
            $user->email_verified_at = null; // Re-verify new email
        }

        // Update basic profile fields
        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        if (array_key_exists('address', $validated)) {
            $user->address = $validated['address'];
        }
        if (array_key_exists('city', $validated)) {
            $user->city = $validated['city'];
        }
        if (array_key_exists('zip_code', $validated)) {
            $user->zip_code = $validated['zip_code'];
        }
        if (array_key_exists('country', $validated)) {
            $user->country = $validated['country'];
        }

        $user->save();

        return response()->json([
            'ok' => true,
            'data' => $this->formatProfile($user->fresh()),
            'message' => 'Profile updated successfully.',
        ]);
    }

    /**
     * Upload/update user avatar.
     *
     * POST /api/profile/avatar
     *
     * Accepts: jpeg, png, webp (max 2MB)
     * Stores in: storage/app/public/avatars/
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        // Delete old avatar if exists
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        // Store new avatar
        $path = $request->file('avatar')->store(
            'avatars/'.$user->id,
            'public'
        );

        $user->update(['avatar_path' => $path]);

        return response()->json([
            'ok' => true,
            'data' => [
                'avatar_url' => Storage::disk('public')->url($path),
            ],
            'message' => 'Avatar uploaded successfully.',
        ]);
    }

    /**
     * Delete user avatar.
     *
     * DELETE /api/profile/avatar
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->update(['avatar_path' => null]);

        return response()->json([
            'ok' => true,
            'data' => null,
            'message' => 'Avatar deleted successfully.',
        ]);
    }

    /**
     * Delete user account (soft: deactivate).
     *
     * DELETE /api/profile
     *
     * Requires current password confirmation.
     * Revokes all tokens and soft-deletes the account.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete the account
        $user->delete();

        return response()->json([
            'ok' => true,
            'data' => null,
            'message' => 'Account deleted successfully.',
        ]);
    }

    /**
     * Format user profile for API response.
     */
    private function formatProfile(User $user): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_type' => $user->user_type,
            'phone' => $user->phone,
            'address' => $user->address,
            'city' => $user->city,
            'zip_code' => $user->zip_code,
            'country' => $user->country,
            'avatar_url' => $user->avatar_path
                ? Storage::disk('public')->url($user->avatar_path)
                : null,
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at->toISOString(),
            'updated_at' => $user->updated_at->toISOString(),
        ];
    }
}

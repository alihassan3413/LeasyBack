<?php

namespace App\Http\Controllers\Settings;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2BService;
use App\Modules\UserProfile\Profile\Services\ProfileService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly B2bContext $context,
        private readonly B2BService $b2bService,
    ) {}

    /**
     * Show the user's "Mein Konto" page.
     *
     * While acting as a company the page shows that company's master data
     * instead of a personal profile: the address, contact person and phone
     * numbers entered during company registration are the account's, and there
     * is no second set of them to keep in sync.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'profile' => $this->profileService->findForUser($request->user()),
            'company' => $this->companyState($request->user()),
        ]);
    }

    /**
     * The company half of "Mein Konto", or null when the user is not acting as
     * a company — which is what tells the page to fall back to the personal
     * profile cards.
     *
     * Follows the active context rather than `user_type`, exactly like the
     * dashboard and every vehicle listing: a dual-context account sees its
     * company's master data while acting as that company, and its own personal
     * profile while acting privately. Switching sides switches this page too,
     * so the account page never describes a company the rest of the session is
     * not in.
     *
     * @return array{data: array<string, mixed>|null, can_manage: bool, can_register: bool}|null
     */
    private function companyState(User $user): ?array
    {
        $membership = $this->context->activeMembership($user);

        if ($membership === null) {
            // A Firmenkunde belongs to no company yet — the page offers the
            // registration form rather than an empty set of company cards.
            // Everyone else, including a dual-context account on its private
            // side, gets the personal profile.
            return $user->user_type === UserType::Firmenkunde
                ? ['data' => null, 'can_manage' => false, 'can_register' => true]
                : null;
        }

        // A member without company.view is told the data exists but isn't
        // theirs to see, exactly as the server would answer.
        return [
            'data' => $membership->can(B2bPermission::ViewCompany)
                ? $this->b2bService->findById($membership->b2bId)
                : null,
            'can_manage' => $membership->can(B2bPermission::ManageCompany),
            'can_register' => false,
        ];
    }

    /**
     * Change the password from the account page: current password plus a new
     * one, with no confirmation field (see settings/Profile.vue's
     * "Passwort & Anmeldung" card). The separate settings/password screen
     * keeps its own confirmed-password flow.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Passwort wurde geändert.');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit')->with('success', 'Profil wurde aktualisiert.');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', 'Ihr Konto wurde gelöscht.');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Enums\B2bRole;
use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Modules\UserProfile\B2B\Services\B2bInvitationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(private readonly B2bInvitationService $invitations) {}

    /**
     * Show the registration page.
     *
     * Registering *from an invitation* is a different question from
     * registering in general, and the form says so: someone joining a company
     * that already exists has no account type to choose and no address to
     * type — both are settled by the invitation. Asking them anyway sent them
     * to "Firmenkunde", and from there into the company-registration wizard,
     * which is the opposite of joining one.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $token = (string) $request->query('invitation', '');

        if ($token === '') {
            return Inertia::render('auth/Register', ['invitation' => null]);
        }

        $invitation = $this->invitations->findAnyByToken($token);

        // Expired, revoked or already used. The invitation page is the one
        // place that explains which — and offers the right way out — so send
        // them there rather than silently dropping the context here.
        if ($invitation === null || ! $invitation->isPending()) {
            return to_route('b2b.invitations.show', $token);
        }

        return Inertia::render('auth/Register', [
            'invitation' => [
                'token' => $token,
                'email' => $invitation->email,
                'company_name' => $invitation->company?->company_name ?? '',
                'role_label' => $this->roleLabel($invitation),
            ],
        ]);
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $invitation = $request->invitation();

        // The invitation decides the account type: someone joining a company
        // is a Firmenkunde, whatever the form did or didn't carry.
        $userType = $invitation !== null
            ? UserType::Firmenkunde
            : UserType::from($validated['user_type']);

        $user = User::create([
            'name' => $validated['name'] ?? explode('@', $validated['email'])[0],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // `user_type` is intentionally not mass-assignable (see User::$fillable) —
        // set explicitly here from the validated, allow-listed value. Admin can
        // never reach this: RegisterRequest restricts user_type to
        // UserType::registrableValues(), which excludes Admin.
        $user->user_type = $userType;
        $user->save();

        event(new Registered($user));

        Auth::login($user);

        if ($invitation !== null) {
            // Joining is the whole point of the registration, so it happens
            // here rather than sending them back to the link a second time.
            // accept() re-checks the address and that the invitation is still
            // pending, under a lock.
            return $this->withServiceErrorHandling(
                'invitation',
                fn () => $this->invitations->accept($invitation, $user)
            ) ?? to_route('dashboard')->with('success', 'Willkommen! Sie sind dem Unternehmen beigetreten.');
        }

        // Customers land in the onboarding flow matching their account type
        // first — Privatkunde in the three-step wizard (profile, vehicle,
        // appointment), Firmenkunde in the company registration — mirroring
        // leasyback_web's split between B2CRegistrationView and
        // RegisterCompanyView. Werkstatt has no wizard yet and goes straight
        // to its dashboard.
        return match ($user->user_type) {
            UserType::Privatkunde => to_route('onboarding.show'),
            UserType::Firmenkunde => to_route('onboarding.b2b.show'),
            default => to_route('dashboard'),
        };
    }

    /** The named company role, matching what the invitation page shows. */
    private function roleLabel(B2bInvitation $invitation): string
    {
        return B2bRolePreset::labelFor(
            B2bRole::tryFrom($invitation->role) ?? B2bRole::Member,
            B2bPermissionSet::fromRaw($invitation->permissions),
        );
    }
}

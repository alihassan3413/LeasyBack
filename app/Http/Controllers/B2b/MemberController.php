<?php

namespace App\Http\Controllers\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRole;
use App\Enums\B2bVehicleScope;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\B2b\MemberAccessRequest;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Services\B2bAnalyticsService;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bInvitationService;
use App\Modules\UserProfile\B2B\Services\B2bMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's team page: who belongs to it, what each of them may do, and
 * which invitations are still open.
 *
 * Route middleware has already established that the caller may at least view
 * members; the finer questions — may they edit, may they touch an owner — are
 * answered by B2bMembershipService so they hold for every entry point.
 */
class MemberController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly B2bContext $context,
        private readonly B2bMembershipService $members,
        private readonly B2bInvitationService $invitations,
        private readonly B2bAnalyticsService $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $membership = $this->membership($request);
        $canManage = $membership->can(B2bPermission::ManageMembers);

        return Inertia::render('b2b/Members', [
            'company' => $membership->company,
            'members' => $this->members->listMembers($membership->b2bId),
            // Only someone who can actually invite has any use for the
            // pending list, and it exposes addresses that were invited but
            // never joined.
            'invitations' => $canManage ? $this->invitations->listForCompany($membership->b2bId) : [],
            'analytics' => $membership->can(B2bPermission::ViewAnalytics)
                ? $this->analytics->summary($membership->b2bId, $request->user())
                : null,
            'permissionCatalog' => $this->permissionCatalog(),
            'roleOptions' => $this->roleOptions(),
            'vehicleScopeOptions' => $this->vehicleScopeOptions(),
            'can' => [
                'manage_members' => $canManage,
                'assign_owner' => $membership->isOwner(),
            ],
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function update(MemberAccessRequest $request, int $userId): RedirectResponse
    {
        $membership = $this->membership($request);

        return $this->withServiceErrorHandling(
            'member',
            fn () => $this->members->updateMember(
                $membership,
                $userId,
                $request->role(),
                $request->permissions(),
                $request->vehicleScope(),
            )
        ) ?? back()->with('success', 'Berechtigungen wurden aktualisiert.');
    }

    public function destroy(Request $request, int $userId): RedirectResponse
    {
        $membership = $this->membership($request);

        return $this->withServiceErrorHandling(
            'member',
            fn () => $this->members->removeMember($membership, $userId)
        ) ?? back()->with('success', 'Mitglied wurde entfernt.');
    }

    /**
     * Every permission there is, grouped and labelled — the members UI is
     * generated from this, so adding a case to B2bPermission is all it takes
     * for the new right to appear in the editor.
     *
     * @return list<array<string, mixed>>
     */
    private function permissionCatalog(): array
    {
        $groups = [];

        foreach (B2bPermission::cases() as $permission) {
            $groups[$permission->group()][] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
                'requires' => $permission->requires(),
            ];
        }

        return array_map(
            fn (string $group, array $permissions) => ['group' => $group, 'permissions' => $permissions],
            array_keys($groups),
            $groups,
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function roleOptions(): array
    {
        return array_map(
            fn (B2bRole $role) => ['value' => $role->value, 'label' => $role->label()],
            B2bRole::cases(),
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function vehicleScopeOptions(): array
    {
        return array_map(
            fn (B2bVehicleScope $scope) => ['value' => $scope->value, 'label' => $scope->label()],
            B2bVehicleScope::cases(),
        );
    }

    private function membership(Request $request): B2bMembership
    {
        $membership = $this->context->activeMembership($request->user());

        abort_if($membership === null, 403, 'Sie gehören derzeit zu keinem Unternehmen.');

        return $membership;
    }
}

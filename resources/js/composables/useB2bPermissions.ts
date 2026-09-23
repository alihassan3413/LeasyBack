import type { SharedData } from '@/types';
import type { B2bPermissionValue } from '@/types/b2b';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Reads the signed-in user's company membership from the shared Inertia props.
 *
 * Purely for rendering: hiding a button the server would refuse anyway. Every
 * one of these questions is answered again server-side by EnsureB2bPermission
 * and VehicleScopeService, so a user who edits their own props gains nothing
 * beyond a UI that lies to them.
 */
export function useB2bPermissions() {
    const page = usePage<SharedData>();

    const state = computed(() => page.props.auth?.b2b ?? null);
    const membership = computed(() => state.value?.active ?? null);
    const memberships = computed(() => state.value?.memberships ?? []);

    /** True while the user is actually acting as one of their companies. */
    const isCompanyUser = computed(() => membership.value !== null);
    const isOwner = computed(() => membership.value?.role === 'owner');
    const seesOwnVehiclesOnly = computed(() => membership.value?.vehicle_scope === 'own');

    /** True for a dual-context account that can return to its private area. */
    const hasPersonalArea = computed(() => state.value?.personal_available === true);

    /**
     * Worth showing a switcher for: more than one company, or one company plus
     * a private area to go back to.
     */
    const canSwitchCompany = computed(() => memberships.value.length + (hasPersonalArea.value ? 1 : 0) > 1);

    /**
     * Accounts with no company side at all (Werkstatt, Admin, an uninvited
     * Privatkunde) have no company permissions — they get `true` so shared
     * components stay usable for them, exactly as EnsureB2bPermission waves
     * them through. A dual-context account acting privately is in the same
     * position: `active` is null, so it is waved through too.
     */
    function can(permission: B2bPermissionValue): boolean {
        if (state.value === null || (membership.value === null && hasPersonalArea.value)) {
            return true;
        }

        return membership.value?.permissions.includes(permission) ?? false;
    }

    function canAny(...permissions: B2bPermissionValue[]): boolean {
        return permissions.some((permission) => can(permission));
    }

    return { membership, memberships, isCompanyUser, isOwner, seesOwnVehiclesOnly, hasPersonalArea, canSwitchCompany, can, canAny };
}

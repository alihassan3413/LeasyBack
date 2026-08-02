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

    /** True only for Firmenkunde accounts that belong to a company. */
    const isCompanyUser = computed(() => membership.value !== null);
    const isOwner = computed(() => membership.value?.role === 'owner');
    const seesOwnVehiclesOnly = computed(() => membership.value?.vehicle_scope === 'own');
    const canSwitchCompany = computed(() => memberships.value.length > 1);

    /**
     * Non-Firmenkunde accounts (Privatkunde, Werkstatt, Admin) have no company
     * permissions at all — they get `true` so shared components stay usable
     * for them, exactly as EnsureB2bPermission waves them through.
     */
    function can(permission: B2bPermissionValue): boolean {
        if (state.value === null) {
            return true;
        }

        return membership.value?.permissions.includes(permission) ?? false;
    }

    function canAny(...permissions: B2bPermissionValue[]): boolean {
        return permissions.some((permission) => can(permission));
    }

    return { membership, memberships, isCompanyUser, isOwner, seesOwnVehiclesOnly, canSwitchCompany, can, canAny };
}

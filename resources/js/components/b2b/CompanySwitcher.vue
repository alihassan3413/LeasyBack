<script setup lang="ts">
/**
 * Lets a user who belongs to more than one company choose which one they are
 * acting as. Renders nothing at all for the common single-company case.
 *
 * Switching is a server round trip on purpose: the whole page — vehicles,
 * permissions, navigation — is rendered for one company at a time, so there
 * is nothing meaningful to update client-side.
 */
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { router } from '@inertiajs/vue3';

defineProps<{ collapsed?: boolean }>();

const { membership, memberships, canSwitchCompany } = useB2bPermissions();

function switchTo(b2bId: string) {
    if (b2bId === membership.value?.b2b_id) {
        return;
    }

    router.post(route('b2b.switch'), { b2b_id: b2bId }, { preserveScroll: false });
}
</script>

<template>
    <DropdownMenu v-if="canSwitchCompany && membership">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-[8px] border border-[#e3ebeb] bg-white px-3 py-2 text-left transition hover:border-[#01B990]"
                :aria-label="`Aktives Unternehmen: ${membership.company_name}`"
            >
                <IconMdiOfficeBuildingOutline class="size-4 shrink-0 text-[#6f8585]" aria-hidden="true" />
                <span v-if="!collapsed" class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-[#10393b]">{{ membership.company_name }}</span>
                    <span class="block truncate text-[11px] text-[#6f8585]">{{ membership.role_label }}</span>
                </span>
                <IconMdiChevronDown v-if="!collapsed" class="size-4 shrink-0 text-[#6f8585]" aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-64">
            <DropdownMenuLabel>Unternehmen wechseln</DropdownMenuLabel>

            <DropdownMenuItem
                v-for="option in memberships"
                :key="option.b2b_id"
                class="cursor-pointer"
                @click="switchTo(option.b2b_id)"
            >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{ option.company_name }}</span>
                    <span class="block truncate text-xs text-[#6f8585]">{{ option.role_label }}</span>
                </span>
                <IconMdiCheck v-if="option.b2b_id === membership.b2b_id" class="size-4 shrink-0 text-[#01B990]" aria-hidden="true" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

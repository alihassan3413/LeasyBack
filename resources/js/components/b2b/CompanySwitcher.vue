<script setup lang="ts">
/**
 * Lets a user who has more than one context choose which one they are acting
 * as: any of their companies, plus — for an account that kept a private side
 * after accepting a company invitation — their own private area. Renders
 * nothing at all for the common single-context case.
 *
 * Switching is a server round trip on purpose: the whole page — vehicles,
 * permissions, navigation — is rendered for one context at a time, so there
 * is nothing meaningful to update client-side.
 */
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { router } from '@inertiajs/vue3';

defineProps<{ collapsed?: boolean }>();

const { membership, memberships, hasPersonalArea, canSwitchCompany } = useB2bPermissions();

function switchTo(b2bId: string | null) {
    if (b2bId === (membership.value?.b2b_id ?? null)) {
        return;
    }

    router.post(route('b2b.switch'), { b2b_id: b2bId }, { preserveScroll: false });
}
</script>

<template>
    <DropdownMenu v-if="canSwitchCompany">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-[8px] border border-[#e3ebeb] bg-white px-3 py-2 text-left transition hover:border-[#01B990]"
                :aria-label="`Aktiver Bereich: ${membership ? membership.company_name : 'Privater Bereich'}`"
            >
                <IconMdiOfficeBuildingOutline v-if="membership" class="size-4 shrink-0 text-[#6f8585]" aria-hidden="true" />
                <IconMdiAccountOutline v-else class="size-4 shrink-0 text-[#6f8585]" aria-hidden="true" />
                <span v-if="!collapsed" class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-[#10393b]">
                        {{ membership ? membership.company_name : 'Privater Bereich' }}
                    </span>
                    <span class="block truncate text-[11px] text-[#6f8585]">
                        {{ membership ? membership.role_label : 'Meine eigenen Fahrzeuge' }}
                    </span>
                </span>
                <IconMdiChevronDown v-if="!collapsed" class="size-4 shrink-0 text-[#6f8585]" aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-64">
            <DropdownMenuLabel>Bereich wechseln</DropdownMenuLabel>

            <DropdownMenuItem v-if="hasPersonalArea" class="cursor-pointer" @click="switchTo(null)">
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">Privater Bereich</span>
                    <span class="block truncate text-xs text-[#6f8585]">Meine eigenen Fahrzeuge</span>
                </span>
                <IconMdiCheck v-if="!membership" class="size-4 shrink-0 text-[#01B990]" aria-hidden="true" />
            </DropdownMenuItem>

            <DropdownMenuItem v-for="option in memberships" :key="option.b2b_id" class="cursor-pointer" @click="switchTo(option.b2b_id)">
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{ option.company_name }}</span>
                    <span class="block truncate text-xs text-[#6f8585]">{{ option.role_label }}</span>
                </span>
                <IconMdiCheck v-if="option.b2b_id === membership?.b2b_id" class="size-4 shrink-0 text-[#01B990]" aria-hidden="true" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

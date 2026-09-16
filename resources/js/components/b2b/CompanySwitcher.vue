<script setup lang="ts">
/**
 * Lets a user who has more than one context choose which one they are acting
 * as: any of their companies, plus — for an account that kept a private side
 * after accepting a company invitation — their own private area. Renders
 * nothing at all for the common single-context case.
 *
 * Guarded on `canSwitchCompany`, never on "is currently in a company": the
 * private area is one of the things you switch *between*, so hiding the
 * control while it is active strands the user there with no way back.
 *
 * Switching is a server round trip on purpose: the whole page — vehicles,
 * permissions, navigation — is rendered for one context at a time, so there
 * is nothing meaningful to update client-side.
 */
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

withDefaults(
    defineProps<{
        collapsed?: boolean;
        /**
         * `sidebar` sits on the dark navigation and borrows its palette;
         * `bar` is the light strip the mobile layout puts above the content,
         * where the dark treatment would be a black box on white.
         */
        variant?: 'sidebar' | 'bar';
    }>(),
    { collapsed: false, variant: 'sidebar' },
);

const { membership, memberships, hasPersonalArea, canSwitchCompany } = useB2bPermissions();

const contextName = computed(() => membership.value?.company_name ?? 'Privater Bereich');
const contextSubtitle = computed(() => membership.value?.role_label ?? 'Meine eigenen Fahrzeuge');

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
                class="flex w-full items-center gap-2.5 rounded-[13px] border px-2.5 py-2 text-left transition-colors"
                :class="variant === 'sidebar' ? 'border-white/10 bg-white/[0.06] hover:bg-white/[0.1]' : 'border-border bg-card hover:bg-muted'"
                :aria-label="`Aktiver Bereich: ${contextName}. Bereich wechseln`"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-[9px]"
                    :class="variant === 'sidebar' ? 'bg-brand-green/25 text-white' : 'bg-brand-green/12 text-brand-teal'"
                >
                    <IconMdiOfficeBuildingOutline v-if="membership" class="size-4" aria-hidden="true" />
                    <IconMdiAccountOutline v-else class="size-4" aria-hidden="true" />
                </span>

                <span v-if="!collapsed" class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-semibold" :class="variant === 'sidebar' ? 'text-white' : 'text-brand-teal'">
                        {{ contextName }}
                    </span>
                    <span class="block truncate text-[11px]" :class="variant === 'sidebar' ? 'text-white/45' : 'text-muted-foreground'">
                        {{ contextSubtitle }}
                    </span>
                </span>

                <!-- The two-way chevron says "switch", where a single one reads as "expand". -->
                <IconMdiUnfoldMoreHorizontal
                    v-if="!collapsed"
                    class="size-4 shrink-0"
                    :class="variant === 'sidebar' ? 'text-white/40' : 'text-muted-foreground'"
                    aria-hidden="true"
                />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-64">
            <DropdownMenuLabel class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">
                Bereich wechseln
            </DropdownMenuLabel>

            <DropdownMenuItem v-if="hasPersonalArea" class="cursor-pointer gap-2.5" @click="switchTo(null)">
                <span class="bg-muted text-muted-foreground flex size-7 shrink-0 items-center justify-center rounded-[9px]">
                    <IconMdiAccountOutline class="size-4" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">Privater Bereich</span>
                    <span class="text-muted-foreground block truncate text-xs">Meine eigenen Fahrzeuge</span>
                </span>
                <IconMdiCheck v-if="!membership" class="text-brand-green size-4 shrink-0" aria-hidden="true" />
            </DropdownMenuItem>

            <DropdownMenuItem v-for="option in memberships" :key="option.b2b_id" class="cursor-pointer gap-2.5" @click="switchTo(option.b2b_id)">
                <span class="bg-muted text-muted-foreground flex size-7 shrink-0 items-center justify-center rounded-[9px]">
                    <IconMdiOfficeBuildingOutline class="size-4" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{ option.company_name }}</span>
                    <span class="text-muted-foreground block truncate text-xs">{{ option.role_label }}</span>
                </span>
                <IconMdiCheck v-if="option.b2b_id === membership?.b2b_id" class="text-brand-green size-4 shrink-0" aria-hidden="true" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

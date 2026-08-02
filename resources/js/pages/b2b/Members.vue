<script setup lang="ts">
import MemberAccessModal from '@/components/b2b/MemberAccessModal.vue';
import RowIconAction from '@/components/b2b/RowIconAction.vue';
import StatCard from '@/components/b2b/StatCard.vue';
import type { SelectFieldOption } from '@/components/form/SelectField.vue';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import type { B2bAnalytics, B2bCompanySummary, B2bInvitationRow, B2bMemberRow, B2bPermissionGroup } from '@/types/b2b';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiAccountGroupOutline from '~icons/mdi/account-group-outline';
import MdiCarOutline from '~icons/mdi/car-outline';
import MdiClipboardTextOutline from '~icons/mdi/clipboard-text-outline';
import MdiCloseCircleOutline from '~icons/mdi/close-circle-outline';
import MdiCogOutline from '~icons/mdi/cog-outline';
import MdiEmailSyncOutline from '~icons/mdi/email-sync-outline';
import MdiTrashCanOutline from '~icons/mdi/trash-can-outline';

const props = defineProps<{
    company: Pick<B2bCompanySummary, 'b2b_id' | 'company_name' | 'logo_url'>;
    members: B2bMemberRow[];
    invitations: B2bInvitationRow[];
    analytics: B2bAnalytics | null;
    permissionCatalog: B2bPermissionGroup[];
    roleOptions: SelectFieldOption[];
    vehicleScopeOptions: SelectFieldOption[];
    can: { manage_members: boolean; assign_owner: boolean };
    currentUserId: number;
}>();

/*
 * Column-label styling for the green header band. Horizontal padding and text
 * alignment are deliberately NOT in here — they are set per cell so each label
 * lines up with the body cell beneath it, and so `text-right` on the numeric
 * columns never has to fight a `text-left` in the same utility layer.
 */
const thBase = 'py-3 text-[11px] font-semibold tracking-[0.06em] text-white uppercase';

/* ── Search ──────────────────────────────────────────────────────────────
 * Filtered in the browser rather than through the server: a company's member
 * list is tens of rows, already fully loaded, so a round trip would only add
 * latency to every keystroke.
 */
const search = ref('');

const normalisedSearch = computed(() => search.value.trim().toLowerCase());

function matches(...fields: (string | null)[]): boolean {
    if (!normalisedSearch.value) {
        return true;
    }

    return fields.some((field) => (field ?? '').toLowerCase().includes(normalisedSearch.value));
}

const filteredMembers = computed(() => props.members.filter((member) => matches(member.name, member.email, member.role_label)));

const filteredInvitations = computed(() =>
    props.invitations.filter((invitation) => matches(invitation.email, invitation.role_label, invitation.invited_by_email)),
);

const stats = computed(() => {
    const cards = [{ key: 'members', label: 'Mitglieder', value: props.members.length, icon: MdiAccountGroupOutline }];

    if (props.analytics) {
        cards.push({ key: 'vehicles', label: 'Fahrzeuge', value: props.analytics.totals.vehicles, icon: MdiCarOutline });
        cards.push({
            key: 'open_orders',
            label: 'Laufende Aufträge',
            value: props.analytics.totals.open_orders,
            icon: MdiClipboardTextOutline,
        });
    }

    return cards;
});

/* ── Modals ──────────────────────────────────────────────────────────── */
const accessModalOpen = ref(false);
const accessModalMode = ref<'invite' | 'edit'>('invite');
const memberBeingEdited = ref<B2bMemberRow | null>(null);

const memberPendingRemoval = ref<B2bMemberRow | null>(null);
const removing = ref(false);

const ownerCount = computed(() => props.members.filter((member) => member.role === 'owner').length);

function openInvite() {
    accessModalMode.value = 'invite';
    memberBeingEdited.value = null;
    accessModalOpen.value = true;
}

function openEdit(member: B2bMemberRow) {
    accessModalMode.value = 'edit';
    memberBeingEdited.value = member;
    accessModalOpen.value = true;
}

function canEdit(member: B2bMemberRow): boolean {
    return props.can.manage_members && (member.role !== 'owner' || props.can.assign_owner);
}

/**
 * The last owner cannot be removed or demoted — the server enforces it too
 * (B2bMembershipService), this just avoids offering an action that will fail.
 */
function canRemove(member: B2bMemberRow): boolean {
    if (!props.can.manage_members) {
        return false;
    }

    if (member.role === 'owner') {
        return props.can.assign_owner && ownerCount.value > 1;
    }

    return true;
}

function removalTooltip(member: B2bMemberRow): string {
    if (member.role === 'owner' && ownerCount.value <= 1) {
        return 'Der letzte Inhaber kann nicht entfernt werden';
    }

    return 'Nur Inhaber können Inhaber entfernen';
}

function confirmRemoval() {
    const member = memberPendingRemoval.value;

    if (!member) {
        return;
    }

    removing.value = true;

    router.delete(route('b2b.members.destroy', member.user_id), {
        preserveScroll: true,
        onFinish: () => {
            removing.value = false;
            memberPendingRemoval.value = null;
        },
    });
}

function resendInvitation(invitation: B2bInvitationRow) {
    router.post(route('b2b.invitations.resend', invitation.invitation_id), {}, { preserveScroll: true });
}

function revokeInvitation(invitation: B2bInvitationRow) {
    router.delete(route('b2b.invitations.revoke', invitation.invitation_id), { preserveScroll: true });
}

/* ── Formatting ──────────────────────────────────────────────────────── */
const dateFormatter = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? '—' : dateFormatter.format(parsed);
}

function scopeLabel(member: B2bMemberRow): string {
    return props.vehicleScopeOptions.find((option) => option.value === member.vehicle_scope)?.label ?? member.vehicle_scope;
}

function initials(member: B2bMemberRow): string {
    const source = member.name?.trim() || member.email;
    const parts = source.split(/[\s@.]+/).filter(Boolean);

    return parts.length >= 2 ? (parts[0][0] + parts[1][0]).toUpperCase() : source.slice(0, 2).toUpperCase();
}

/** Mint fill with dark-green text for owners; muted for everyone else. */
function roleBadgeClass(member: B2bMemberRow): string {
    return member.role === 'owner' ? 'bg-brand-green/12 text-brand-teal' : 'bg-muted text-muted-foreground';
}
</script>

<template>
    <Head title="Team" />

    <AppLayout>
        <!--
            Page title and the page's own controls live in the app header,
            matching the admin list pages — the body then starts straight at
            the content instead of repeating a heading.
        -->
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <h1 class="text-brand-teal shrink-0 text-base font-extrabold tracking-[-0.3px]">Team</h1>

                <div class="admin-search ml-auto min-w-0 sm:max-w-[240px]">
                    <IconMdiMagnify class="size-4 shrink-0" />

                    <input v-model="search" type="search" placeholder="Name oder E-Mail…" class="admin-search-input" />

                    <button v-if="search" type="button" class="search-clear" title="Suche zurücksetzen" @click="search = ''">
                        <IconMdiClose class="size-3.5" />
                    </button>
                </div>

                <button
                    v-if="can.manage_members"
                    type="button"
                    class="bg-brand-orange flex shrink-0 items-center justify-center gap-1.5 rounded-full px-3 py-2 text-sm font-bold text-white transition-opacity hover:opacity-90 sm:px-4"
                    @click="openInvite"
                >
                    <IconMdiPlus class="size-4" aria-hidden="true" />
                    <span class="hidden sm:inline">Einladen</span>
                    <span class="sr-only sm:hidden">Mitglied einladen</span>
                </button>
            </div>
        </template>

        <TooltipProvider :delay-duration="200">
            <div class="mx-auto w-full max-w-[1120px] space-y-6 pb-4">
                <!-- ── STATS ── -->
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <StatCard v-for="stat in stats" :key="stat.key" :label="stat.label" :value="stat.value" :icon="stat.icon" />
                </div>

                <p v-if="normalisedSearch" class="text-muted-foreground text-xs">
                    {{ filteredMembers.length }} von {{ members.length }} Mitgliedern · {{ filteredInvitations.length }} von
                    {{ invitations.length }} Einladungen
                </p>

                <!-- ── MEMBERS ── -->
                <section class="border-border bg-card overflow-hidden rounded-xl border shadow-sm">
                    <!-- Mobile has no column header, so it gets the band as a section title. -->
                    <div class="bg-brand-green px-4 py-3 md:hidden">
                        <span class="text-[11px] font-semibold tracking-[0.06em] text-white uppercase">Mitglieder</span>
                    </div>

                    <p v-if="!filteredMembers.length" class="text-muted-foreground px-6 py-12 text-center text-sm">
                        Keine Treffer für „{{ search }}“.
                    </p>

                    <template v-else>
                        <!-- Desktop: a real table for semantics, with the app's green header band. -->
                        <table class="hidden w-full border-collapse md:table">
                            <thead>
                                <tr class="bg-brand-green">
                                    <th :class="[thBase, 'w-[34%] pr-4 pl-6 text-left']">Mitglied</th>
                                    <th :class="[thBase, 'w-[14%] pr-4 text-left']">Rolle</th>
                                    <th :class="[thBase, 'w-[20%] pr-4 text-left']">Sichtbare Fahrzeuge</th>
                                    <th :class="[thBase, 'w-[9%] pr-4 text-right']">Fahrzeuge</th>
                                    <th :class="[thBase, 'w-[9%] pr-4 text-right']">Aufträge</th>
                                    <th :class="[thBase, 'w-[12%] pr-4 text-left']">Beigetreten</th>
                                    <th v-if="can.manage_members" :class="[thBase, 'w-[92px] pr-6 text-right']">
                                        <span class="sr-only">Optionen</span>
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-border divide-y">
                                <tr v-for="member in filteredMembers" :key="member.user_id" class="hover:bg-muted/40 transition-colors">
                                    <td class="py-4 pr-4 pl-6">
                                        <div class="flex items-center gap-3">
                                            <span
                                                class="bg-brand-green/12 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                            >
                                                {{ initials(member) }}
                                            </span>
                                            <span class="min-w-0">
                                                <span class="text-brand-teal block truncate text-sm font-semibold">
                                                    {{ member.name || member.email }}
                                                    <span v-if="member.user_id === currentUserId" class="text-muted-foreground font-normal">
                                                        · Sie
                                                    </span>
                                                </span>
                                                <span class="text-muted-foreground block truncate text-xs">{{ member.email }}</span>
                                            </span>
                                        </div>
                                    </td>

                                    <td class="py-4 pr-4 align-middle">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="roleBadgeClass(member)"
                                        >
                                            {{ member.role_label }}
                                        </span>
                                        <span v-if="!member.is_active" class="text-destructive mt-1 block text-xs">deaktiviert</span>
                                    </td>

                                    <td class="text-muted-foreground py-4 pr-4 align-middle text-sm">{{ scopeLabel(member) }}</td>
                                    <td class="text-brand-teal py-4 pr-4 text-right align-middle text-sm font-medium tabular-nums">
                                        {{ member.vehicle_count }}
                                    </td>
                                    <td class="text-brand-teal py-4 pr-4 text-right align-middle text-sm font-medium tabular-nums">
                                        {{ member.order_count }}
                                    </td>
                                    <td class="text-muted-foreground py-4 pr-4 align-middle text-sm">{{ formatDate(member.joined_at) }}</td>

                                    <td v-if="can.manage_members" class="py-4 pr-6 align-middle">
                                        <div class="flex justify-end">
                                            <div class="border-border inline-flex items-center gap-0.5 rounded-lg border p-0.5">
                                                <RowIconAction
                                                    :icon="MdiCogOutline"
                                                    label="Berechtigungen bearbeiten"
                                                    disabled-label="Nur Inhaber können Inhaber bearbeiten"
                                                    :disabled="!canEdit(member)"
                                                    @click="openEdit(member)"
                                                />
                                                <RowIconAction
                                                    :icon="MdiTrashCanOutline"
                                                    label="Mitglied entfernen"
                                                    :disabled-label="removalTooltip(member)"
                                                    :disabled="!canRemove(member)"
                                                    danger
                                                    @click="memberPendingRemoval = member"
                                                />
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Mobile: the same rows as stacked cards. -->
                        <ul class="divide-border divide-y md:hidden">
                            <li v-for="member in filteredMembers" :key="member.user_id" class="p-4">
                                <div class="flex items-start gap-3">
                                    <span
                                        class="bg-brand-green/12 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                    >
                                        {{ initials(member) }}
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="text-brand-teal truncate text-sm font-semibold">
                                                    {{ member.name || member.email }}
                                                    <span v-if="member.user_id === currentUserId" class="text-muted-foreground font-normal">
                                                        · Sie
                                                    </span>
                                                </p>
                                                <p class="text-muted-foreground truncate text-xs">{{ member.email }}</p>
                                            </div>

                                            <span
                                                class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                                                :class="roleBadgeClass(member)"
                                            >
                                                {{ member.role_label }}
                                            </span>
                                        </div>

                                        <dl class="mt-3 grid grid-cols-2 gap-2">
                                            <div class="bg-muted/50 rounded-lg px-3 py-2">
                                                <dt class="text-muted-foreground text-[11px]">Fahrzeuge</dt>
                                                <dd class="text-brand-teal text-sm font-semibold tabular-nums">{{ member.vehicle_count }}</dd>
                                            </div>
                                            <div class="bg-muted/50 rounded-lg px-3 py-2">
                                                <dt class="text-muted-foreground text-[11px]">Aufträge</dt>
                                                <dd class="text-brand-teal text-sm font-semibold tabular-nums">{{ member.order_count }}</dd>
                                            </div>
                                        </dl>

                                        <p class="text-muted-foreground mt-2 text-xs">
                                            {{ scopeLabel(member) }} · seit {{ formatDate(member.joined_at) }}
                                        </p>

                                        <div v-if="can.manage_members" class="border-border mt-3 flex items-center gap-1 border-t pt-3">
                                            <button
                                                type="button"
                                                class="text-brand-teal hover:bg-muted inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium disabled:opacity-40"
                                                :disabled="!canEdit(member)"
                                                @click="openEdit(member)"
                                            >
                                                <MdiCogOutline class="size-4" aria-hidden="true" />
                                                Rechte
                                            </button>

                                            <button
                                                type="button"
                                                class="text-destructive hover:bg-destructive/10 inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium disabled:opacity-40"
                                                :disabled="!canRemove(member)"
                                                @click="memberPendingRemoval = member"
                                            >
                                                <MdiTrashCanOutline class="size-4" aria-hidden="true" />
                                                Entfernen
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- ── INVITATIONS ── -->
                <section v-if="can.manage_members" class="border-border bg-card overflow-hidden rounded-xl border shadow-sm">
                    <div class="bg-brand-green flex items-center justify-between gap-4 px-4 py-3 md:px-6">
                        <span class="text-[11px] font-semibold tracking-[0.06em] text-white uppercase">Offene Einladungen</span>
                        <span v-if="invitations.length" class="text-xs font-semibold text-white tabular-nums">{{ invitations.length }}</span>
                    </div>

                    <!-- Real empty state: icon, headline, one line, secondary action. -->
                    <div v-if="!invitations.length" class="flex flex-col items-center px-6 py-12 text-center">
                        <span class="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full">
                            <IconMdiEmailOutline class="size-6" aria-hidden="true" />
                        </span>

                        <p class="text-brand-teal mt-4 text-sm font-semibold">Keine offenen Einladungen</p>
                        <p class="text-muted-foreground mt-1 max-w-sm text-sm">
                            Laden Sie Kolleginnen und Kollegen ein, um gemeinsam Fahrzeuge und Rückgaben zu verwalten.
                        </p>

                        <button
                            type="button"
                            class="border-border text-brand-teal hover:bg-muted mt-5 inline-flex items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-semibold transition-colors"
                            @click="openInvite"
                        >
                            <IconMdiPlus class="size-4" aria-hidden="true" />
                            Einladen
                        </button>
                    </div>

                    <p v-else-if="!filteredInvitations.length" class="text-muted-foreground px-6 py-12 text-center text-sm">
                        Keine Treffer für „{{ search }}“.
                    </p>

                    <ul v-else class="divide-border divide-y">
                        <li
                            v-for="invitation in filteredInvitations"
                            :key="invitation.invitation_id"
                            class="hover:bg-muted/40 flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-4 transition-colors md:px-6"
                        >
                            <span class="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-full">
                                <IconMdiEmailOutline class="size-[18px]" aria-hidden="true" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="text-brand-teal block truncate text-sm font-semibold">{{ invitation.email }}</span>
                                <span class="text-muted-foreground block truncate text-xs">
                                    eingeladen von {{ invitation.invited_by_email ?? '—' }}
                                </span>
                            </span>

                            <span class="bg-muted text-muted-foreground inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold">
                                {{ invitation.role_label }}
                            </span>

                            <span class="text-muted-foreground text-sm whitespace-nowrap">bis {{ formatDate(invitation.expires_at) }}</span>

                            <span class="border-border inline-flex items-center gap-0.5 rounded-lg border p-0.5">
                                <RowIconAction :icon="MdiEmailSyncOutline" label="Einladung erneut senden" @click="resendInvitation(invitation)" />
                                <RowIconAction
                                    :icon="MdiCloseCircleOutline"
                                    label="Einladung zurückziehen"
                                    danger
                                    @click="revokeInvitation(invitation)"
                                />
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </TooltipProvider>

        <MemberAccessModal
            v-model:open="accessModalOpen"
            :mode="accessModalMode"
            :member="memberBeingEdited"
            :catalog="permissionCatalog"
            :role-options="roleOptions"
            :vehicle-scope-options="vehicleScopeOptions"
            :can-assign-owner="can.assign_owner"
        />

        <AppModal
            :open="memberPendingRemoval !== null"
            title="Mitglied entfernen?"
            :description="`${memberPendingRemoval?.name || memberPendingRemoval?.email} verliert sofort den Zugriff auf ${company.company_name}. Die angelegten Fahrzeuge bleiben beim Unternehmen.`"
            :width="560"
            @update:open="(open) => !open && (memberPendingRemoval = null)"
        >
            <template #footer>
                <AppModalButton type="button" variant="secondary" :disabled="removing" @click="memberPendingRemoval = null">
                    Abbrechen
                </AppModalButton>
                <AppModalButton type="button" :disabled="removing" @click="confirmRemoval">Entfernen</AppModalButton>
            </template>
        </AppModal>
    </AppLayout>
</template>

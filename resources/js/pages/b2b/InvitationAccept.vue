<script setup lang="ts">
/**
 * What the invited person lands on. Reachable logged out on purpose — they
 * may not have an account yet — so every state the button can't be pressed in
 * explains itself and offers the next step instead of just failing.
 */
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import B2bRegistrationLayout from '@/layouts/onboarding/B2bRegistrationLayout.vue';
import type { B2bPermissionValue, B2bVehicleScopeValue } from '@/types/b2b';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface InvitationDetails {
    company_name: string;
    company_logo_url: string | null;
    email: string;
    role_label: string;
    permissions: B2bPermissionValue[];
    vehicle_scope: B2bVehicleScopeValue;
    expires_at: string;
}

interface Viewer {
    email: string;
    user_type: string;
    email_matches: boolean;
    can_join: boolean;
    keeps_private_area: boolean;
}

type InvitationStatus = 'pending' | 'accepted' | 'revoked' | 'expired' | 'invalid';

const props = defineProps<{
    token: string;
    status: InvitationStatus;
    invitation: InvitationDetails | null;
    viewer: Viewer | null;
}>();

/** Why a dead link is dead — an instruction rather than a flat "invalid". */
const deadLink = computed(() => {
    switch (props.status) {
        case 'accepted':
            return {
                title: 'Diese Einladung wurde bereits angenommen',
                body: 'Sie gehören dem Unternehmen bereits an. Melden Sie sich an, um darauf zuzugreifen.',
            };
        case 'revoked':
            return {
                title: 'Diese Einladung wurde zurückgezogen',
                body: 'Das Unternehmen hat die Einladung zurückgenommen. Bitten Sie um eine neue Einladung.',
            };
        case 'expired':
            return {
                title: 'Diese Einladung ist abgelaufen',
                body: 'Einladungen sind nur begrenzt gültig. Bitten Sie das Unternehmen um eine neue Einladung.',
            };
        default:
            return {
                title: 'Diese Einladung ist nicht mehr gültig',
                body: 'Der Link ist unbekannt oder unvollständig. Bitten Sie das Unternehmen um eine neue Einladung.',
            };
    }
});

const page = usePage();
const processing = ref(false);

const errorMessage = computed(() => {
    const errors = page.props.errors as Record<string, string | undefined> | undefined;

    return errors?.invitation;
});

const blockedReason = computed(() => {
    if (!props.viewer) {
        return null;
    }

    if (!props.viewer.email_matches) {
        return `Diese Einladung gilt für ${props.invitation?.email}. Sie sind als ${props.viewer.email} angemeldet — bitte melden Sie sich mit der eingeladenen Adresse an.`;
    }

    if (!props.viewer.can_join) {
        return 'Ihr Konto kann keinem Unternehmen beitreten. Bitte verwenden Sie ein Kundenkonto.';
    }

    return null;
});

const canAccept = computed(() => props.invitation !== null && props.viewer !== null && blockedReason.value === null);

const scopeLabel = computed(() => (props.invitation?.vehicle_scope === 'own' ? 'Nur selbst angelegte Fahrzeuge' : 'Alle Fahrzeuge des Unternehmens'));

const dateFormatter = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

const expiresLabel = computed(() => {
    if (!props.invitation) {
        return '';
    }

    const parsed = new Date(props.invitation.expires_at);

    return Number.isNaN(parsed.getTime()) ? '' : dateFormatter.format(parsed);
});

function accept() {
    processing.value = true;

    router.post(route('b2b.invitations.accept', props.token), {}, { onFinish: () => (processing.value = false) });
}
</script>

<template>
    <Head title="Einladung" />

    <B2bRegistrationLayout title="Einladung" back-label="Zur Startseite">
        <div class="w-full rounded-[10px] bg-white px-6 py-6 shadow-[0_4px_4px_rgba(0,0,0,0.25)] md:px-8">
            <template v-if="!invitation">
                <h2 class="text-brand-teal text-xl font-bold">{{ deadLink.title }}</h2>
                <p class="text-muted-foreground mt-2 text-sm">{{ deadLink.body }}</p>
                <div v-if="status === 'accepted'" class="mt-5 flex justify-end">
                    <Link
                        :href="route('login')"
                        class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-center text-sm font-bold text-white transition sm:w-auto"
                    >
                        Anmelden
                    </Link>
                </div>
            </template>

            <template v-else>
                <div class="flex items-start gap-4">
                    <img
                        v-if="invitation.company_logo_url"
                        :src="invitation.company_logo_url"
                        alt=""
                        class="size-14 shrink-0 rounded-[8px] object-contain"
                    />
                    <div class="min-w-0">
                        <h2 class="text-brand-teal text-xl font-bold">{{ invitation.company_name }}</h2>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Sie wurden als <span class="font-semibold">{{ invitation.role_label }}</span> eingeladen, diesem Unternehmen bei LeasyBack
                            beizutreten.
                        </p>
                    </div>
                </div>

                <div class="bg-brand-green-gray my-4 h-px w-full" />

                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold text-[#6f8585]">Eingeladene Adresse</dt>
                        <dd class="text-brand-teal">{{ invitation.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold text-[#6f8585]">Sichtbare Fahrzeuge</dt>
                        <dd class="text-brand-teal">{{ scopeLabel }}</dd>
                    </div>
                    <div v-if="expiresLabel">
                        <dt class="text-xs font-bold text-[#6f8585]">Gültig bis</dt>
                        <dd class="text-brand-teal">{{ expiresLabel }}</dd>
                    </div>
                </dl>

                <InputError :message="errorMessage" class="mt-4" />

                <p v-if="blockedReason" class="mt-4 rounded-[8px] bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ blockedReason }}
                </p>

                <p v-else-if="viewer?.keeps_private_area" class="mt-4 rounded-[8px] bg-[#e6f8f4] px-4 py-3 text-sm text-[#0b4f49]">
                    Ihr privater Bereich bleibt vollständig erhalten. Nach dem Beitritt können Sie jederzeit zwischen Ihren eigenen Fahrzeugen und
                    {{ invitation.company_name }} wechseln.
                </p>

                <div class="mt-6 flex flex-col-reverse items-center gap-3 border-t pt-5 sm:flex-row sm:justify-end">
                    <template v-if="!viewer">
                        <Link
                            :href="route('register')"
                            class="bg-brand-orange hover:bg-brand-orange/90 w-full rounded-[5px] px-8 py-2.5 text-center text-sm font-bold text-white transition sm:w-auto"
                        >
                            Konto erstellen
                        </Link>
                        <Link
                            :href="route('login')"
                            class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-center text-sm font-bold text-white transition sm:w-auto"
                        >
                            Anmelden
                        </Link>
                    </template>

                    <Button
                        v-else
                        type="button"
                        :loading="processing"
                        :disabled="!canAccept"
                        class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none sm:w-auto"
                        @click="accept"
                    >
                        Einladung annehmen
                    </Button>
                </div>

                <p v-if="!viewer" class="text-muted-foreground mt-3 text-center text-xs sm:text-right">
                    Haben Sie bereits ein Konto für <span class="font-semibold">{{ invitation.email }}</span
                    >? Melden Sie sich damit an — es wird weiterverwendet. Andernfalls registrieren Sie sich mit dieser Adresse und öffnen Sie diesen
                    Link erneut.
                </p>
            </template>
        </div>
    </B2bRegistrationLayout>
</template>

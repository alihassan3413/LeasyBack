<script setup lang="ts">
/**
 * Turning a second factor on, one decision at a time.
 *
 * The server owns the flow's real state — it issues the secret, and it flashes
 * the recovery codes exactly once — so the step shown is derived from the props
 * first and only falls back to local state for the two screens that are purely
 * a choice the user has not made yet.
 */
import InputError from '@/components/InputError.vue';
import MfaStepper from '@/components/mfa/MfaStepper.vue';
import OtpInput from '@/components/mfa/OtpInput.vue';
import RecoveryCodes from '@/components/mfa/RecoveryCodes.vue';
import { Button } from '@/components/ui/button';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import MdiAlertOutline from '~icons/mdi/alert-outline';
import MdiArrowLeft from '~icons/mdi/arrow-left';
import MdiCellphoneKey from '~icons/mdi/cellphone-key';
import MdiCheck from '~icons/mdi/check';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiChevronRight from '~icons/mdi/chevron-right';
import MdiContentCopy from '~icons/mdi/content-copy';
import MdiEmailOutline from '~icons/mdi/email-outline';
import MdiShieldCheckOutline from '~icons/mdi/shield-check-outline';

const props = defineProps<{
    enrolled: boolean;
    method: 'totp' | 'email' | null;
    mandatory: boolean;
    email: string;
    secret: string | null;
    otpauthUri: string | null;
    /** Server-rendered SVG markup. Generated with bacon/bacon-qr-code so the
     *  secret never reaches a client-side QR library. */
    qrCode: string | null;
    recoveryCodes?: string[] | null;
    status?: string | null;
}>();

const RESEND_SECONDS = 30;

const chosen = ref<'totp' | 'email' | null>(null);
const showSecret = ref(false);
const secretCopied = ref(false);
const cooldown = ref(0);
let timer: number | undefined;

const form = useForm({ method: 'totp' as 'totp' | 'email', code: '' });

/** 1 choose · 2 set up · 3 verify · 4 recovery codes. */
const step = computed(() => {
    if (props.recoveryCodes?.length) {
        return 4;
    }

    return chosen.value === null ? 1 : form.code.length === 6 ? 3 : 2;
});

function choose(next: 'totp' | 'email') {
    chosen.value = next;
    form.method = next;
    form.clearErrors();

    if (next === 'email') {
        sendEmail();
    }
}

function back() {
    chosen.value = null;
    form.reset('code');
    form.clearErrors();
}

function startCooldown() {
    cooldown.value = RESEND_SECONDS;
    window.clearInterval(timer);
    timer = window.setInterval(() => {
        cooldown.value -= 1;

        if (cooldown.value <= 0) {
            window.clearInterval(timer);
        }
    }, 1000);
}

function sendEmail() {
    router.post(route('mfa.setup.send-email'), {}, { preserveScroll: true, onSuccess: startCooldown });
}

async function copySecret() {
    if (props.secret === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.secret);
        secretCopied.value = true;
        window.setTimeout(() => (secretCopied.value = false), 2000);
    } catch {
        secretCopied.value = false;
    }
}

function submit() {
    form.post(route('mfa.setup.confirm'), { onError: () => form.reset('code') });
}

function finish() {
    router.visit(route('dashboard'));
}

onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <Head title="Zwei-Faktor-Authentifizierung" />

    <div class="flex min-h-screen items-start justify-center bg-[#f6f9f8] px-4 py-10 max-[560px]:py-6">
        <div class="w-full max-w-lg">
            <div class="rounded-3xl border border-[#ececec] bg-white p-7 max-[560px]:p-5">
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#01b990]/10 text-[#0b7a63]">
                        <MdiShieldCheckOutline class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11.5px] font-bold tracking-wide text-[#9bb0af] uppercase">Leasyback · Sicherheit</p>
                        <h1 class="mt-0.5 text-[21px] leading-tight font-extrabold tracking-[-0.4px] text-[#10393b]">Konto absichern</h1>
                    </div>
                </div>

                <!-- Already on, and nothing new to show. -->
                <p
                    v-if="props.enrolled && !props.recoveryCodes?.length"
                    class="mt-5 flex items-start gap-2 rounded-[13px] border border-[#01b990]/25 bg-[#01b990]/5 px-3.5 py-3 text-[12.5px] font-bold text-[#0b7a63]"
                    data-testid="mfa-enrolled"
                >
                    <MdiCheckCircleOutline class="mt-0.5 size-[18px] shrink-0" aria-hidden="true" />
                    Zwei-Faktor-Authentifizierung ist aktiv ({{ props.method === 'email' ? 'E-Mail-Code' : 'Authenticator-App' }}).
                </p>

                <template v-else>
                    <div class="mt-5">
                        <MfaStepper :current="step" />
                    </div>

                    <!-- Step 4 — the codes, shown once. -->
                    <div v-if="props.recoveryCodes?.length" class="mt-6">
                        <h2 class="text-[16px] font-extrabold text-[#10393b]">Notfallcodes sichern</h2>
                        <p class="mt-1 mb-4 text-[12.5px] leading-[1.5] text-[#6f8585]">
                            Bewahren Sie diese Codes auf, bevor Sie fortfahren. Sie sind Ihr Zugang, wenn Sie Ihr Gerät verlieren.
                        </p>
                        <RecoveryCodes :codes="props.recoveryCodes" :email="props.email" @acknowledged="finish" />
                    </div>

                    <template v-else>
                        <p
                            v-if="props.mandatory"
                            class="mt-5 flex items-start gap-2 rounded-[13px] border border-[#d9a441] bg-[#fffaf0] px-3.5 py-3 text-[12.5px] font-bold text-[#a9741b]"
                        >
                            <MdiAlertOutline class="mt-0.5 size-[18px] shrink-0" aria-hidden="true" />
                            Für Ihr Konto ist eine Zwei-Faktor-Authentifizierung erforderlich.
                        </p>

                        <p
                            v-if="props.status"
                            role="status"
                            class="mt-3 rounded-[13px] border border-[#01b990]/25 bg-[#01b990]/5 px-3.5 py-3 text-[12.5px] font-bold text-[#0b7a63]"
                        >
                            {{ props.status }}
                        </p>

                        <!-- Step 1 — one decision, nothing else on screen. -->
                        <div v-if="chosen === null" class="mt-6">
                            <h2 class="text-[16px] font-extrabold text-[#10393b]">Wie möchten Sie sich bestätigen?</h2>
                            <p class="mt-1 text-[12.5px] leading-[1.5] text-[#6f8585]">
                                Die Zwei-Faktor-Authentifizierung schützt Ihr Konto vor unbefugtem Zugriff.
                            </p>

                            <div class="mt-4 flex flex-col gap-2.5">
                                <button
                                    type="button"
                                    class="group flex cursor-pointer items-center gap-3.5 rounded-[15px] border border-[#d8e4e2] bg-white p-4 text-left transition-colors hover:border-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                                    data-testid="mfa-method-totp"
                                    @click="choose('totp')"
                                >
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#01b990]/10 text-[#0b7a63]">
                                        <MdiCellphoneKey class="size-5" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-[13.5px] font-extrabold text-[#10393b]">Authenticator-App</span>
                                            <span class="rounded-full bg-[#01b990]/10 px-2 py-0.5 text-[10.5px] font-bold text-[#0b7a63]">
                                                Empfohlen
                                            </span>
                                        </span>
                                        <span class="mt-0.5 block text-[12px] leading-[1.45] text-[#6f8585]">
                                            Google Authenticator, Microsoft Authenticator oder 1Password
                                        </span>
                                    </span>
                                    <MdiChevronRight
                                        class="size-5 shrink-0 text-[#9bb0af] transition-colors group-hover:text-[#01b990] motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                </button>

                                <button
                                    type="button"
                                    class="group flex cursor-pointer items-center gap-3.5 rounded-[15px] border border-[#d8e4e2] bg-white p-4 text-left transition-colors hover:border-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                                    data-testid="mfa-method-email"
                                    @click="choose('email')"
                                >
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#f6f9f8] text-[#6f8585]">
                                        <MdiEmailOutline class="size-5" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="text-[13.5px] font-extrabold text-[#10393b]">E-Mail-Bestätigung</span>
                                        <span class="mt-0.5 block truncate text-[12px] leading-[1.45] text-[#6f8585]">
                                            Einmalcode an {{ props.email }}
                                        </span>
                                    </span>
                                    <MdiChevronRight
                                        class="size-5 shrink-0 text-[#9bb0af] transition-colors group-hover:text-[#01b990] motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                        </div>

                        <!-- Steps 2 and 3 — set up, then confirm. -->
                        <div v-else class="mt-6">
                            <button
                                type="button"
                                class="-ml-1 inline-flex min-h-[32px] cursor-pointer items-center gap-1 rounded-[9px] px-1 text-[12px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                                data-testid="mfa-back"
                                @click="back"
                            >
                                <MdiArrowLeft class="size-4 shrink-0" aria-hidden="true" />
                                Andere Methode
                            </button>

                            <div v-if="chosen === 'totp'" class="mt-3">
                                <h2 class="text-[16px] font-extrabold text-[#10393b]">App verknüpfen</h2>
                                <p class="mt-1 text-[12.5px] leading-[1.5] text-[#6f8585]">Scannen Sie den Code mit Ihrer Authenticator-App.</p>

                                <div class="mt-4 flex flex-col items-center rounded-[15px] border border-[#e9efee] bg-[#f6f9f8] p-5">
                                    <!-- eslint-disable-next-line vue/no-v-html -- server-generated
                                         SVG from bacon/bacon-qr-code; contains no user input. -->
                                    <div
                                        v-if="props.qrCode"
                                        class="rounded-[13px] bg-white p-3 [&>svg]:block [&>svg]:size-[184px] max-[380px]:[&>svg]:size-[150px]"
                                        data-testid="mfa-qr"
                                        role="img"
                                        aria-label="QR-Code zum Scannen mit Ihrer Authenticator-App"
                                        v-html="props.qrCode"
                                    />

                                    <a
                                        v-if="props.otpauthUri"
                                        :href="props.otpauthUri"
                                        class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-full border border-[#d8e4e2] bg-white px-4 text-[12.5px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                                        data-testid="mfa-otpauth-link"
                                    >
                                        <MdiCellphoneKey class="size-[18px] shrink-0" aria-hidden="true" />
                                        In Authenticator-App öffnen
                                    </a>
                                </div>

                                <button
                                    v-if="!showSecret"
                                    type="button"
                                    class="mt-2.5 inline-flex min-h-[36px] cursor-pointer items-center rounded-[9px] px-1 text-[12px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                                    data-testid="mfa-show-secret"
                                    @click="showSecret = true"
                                >
                                    Sie können nicht scannen? Schlüssel manuell eintragen
                                </button>

                                <div v-else class="mt-2.5 rounded-[13px] bg-[#f6f9f8] px-3.5 py-3">
                                    <p class="text-[11px] font-bold tracking-wide text-[#9bb0af] uppercase">Einrichtungsschlüssel</p>
                                    <div class="mt-1.5 flex items-start gap-2">
                                        <p
                                            class="min-w-0 flex-1 font-mono text-[12.5px] break-all text-[#10393b] select-all"
                                            data-testid="mfa-secret"
                                        >
                                            {{ props.secret }}
                                        </p>
                                        <button
                                            type="button"
                                            class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[9px] border border-[#d8e4e2] bg-white text-[#6f8585] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                                            :aria-label="secretCopied ? 'Schlüssel kopiert' : 'Schlüssel kopieren'"
                                            data-testid="mfa-copy-secret"
                                            @click="copySecret"
                                        >
                                            <component :is="secretCopied ? MdiCheck : MdiContentCopy" class="size-4" aria-hidden="true" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="mt-3">
                                <h2 class="text-[16px] font-extrabold text-[#10393b]">Code aus Ihrer E-Mail</h2>
                                <p class="mt-1 text-[12.5px] leading-[1.5] text-[#6f8585]">
                                    Wir haben einen Bestätigungscode an <strong class="text-[#10393b]">{{ props.email }}</strong> gesendet.
                                </p>

                                <button
                                    type="button"
                                    class="mt-3 inline-flex min-h-[40px] cursor-pointer items-center gap-2 rounded-full border border-[#d8e4e2] bg-white px-4 text-[12.5px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:cursor-default disabled:opacity-50 motion-reduce:transition-none"
                                    :disabled="cooldown > 0"
                                    data-testid="mfa-send-email"
                                    @click="sendEmail"
                                >
                                    <MdiEmailOutline class="size-[18px] shrink-0" aria-hidden="true" />
                                    <span v-if="cooldown > 0">Neuen Code in {{ cooldown }} s</span>
                                    <span v-else>Code erneut senden</span>
                                </button>
                            </div>

                            <form class="mt-6 border-t border-[#f2f6f5] pt-5" @submit.prevent="submit">
                                <p class="text-[12.5px] font-bold text-[#10393b]">Bestätigungscode eingeben</p>
                                <div class="mt-2.5">
                                    <OtpInput
                                        v-model="form.code"
                                        :invalid="Boolean(form.errors.code)"
                                        :disabled="form.processing"
                                        autofocus
                                        @complete="submit"
                                    />
                                </div>
                                <InputError class="mt-2" :message="form.errors.code" />

                                <Button
                                    type="submit"
                                    class="mt-4 w-full"
                                    :loading="form.processing"
                                    :disabled="form.code.length < 6"
                                    data-testid="mfa-confirm"
                                >
                                    Aktivieren
                                </Button>
                            </form>
                        </div>
                    </template>
                </template>
            </div>

            <p class="mt-4 text-center text-[11.5px] text-[#9bb0af]">Ihre Zugangsdaten werden niemals per E-Mail abgefragt.</p>
        </div>
    </div>
</template>

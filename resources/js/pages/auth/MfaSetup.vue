<script setup lang="ts">
/**
 * Turning a second factor on.
 *
 * The secret arrives as a page prop and is shown for manual entry; the
 * `otpauth://` URI is the exact string a QR image would encode, offered as a
 * link so a phone can open it directly. No QR image is rendered because that
 * would mean a new dependency — see the note in the template.
 *
 * Recovery codes are flashed by the server and survive exactly one render.
 */
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MdiAlertOutline from '~icons/mdi/alert-outline';
import MdiCellphoneKey from '~icons/mdi/cellphone-key';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiEmailOutline from '~icons/mdi/email-outline';

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

const selected = ref<'totp' | 'email'>('totp');
const form = useForm({ method: 'totp' as 'totp' | 'email', code: '' });

function choose(next: 'totp' | 'email') {
    selected.value = next;
    form.method = next;
    form.clearErrors();
}

function sendEmail() {
    router.post(route('mfa.setup.send-email'), {}, { preserveScroll: true });
}

function submit() {
    form.post(route('mfa.setup.confirm'), { onFinish: () => form.reset('code') });
}
</script>

<template>
    <Head title="Zwei-Faktor-Authentifizierung" />

    <div class="flex min-h-screen items-start justify-center bg-[#f6f9f8] px-4 py-10">
        <div class="w-full max-w-lg rounded-3xl border border-[#ececec] bg-white p-6">
            <p class="text-[12px] font-bold tracking-wide text-[#9bb0af] uppercase">Leasyback · Sicherheit</p>
            <h1 class="mt-1 text-[22px] font-extrabold tracking-[-0.4px] text-[#10393b]">Konto absichern</h1>

            <!-- Recovery codes: shown once, immediately after enrollment. -->
            <div v-if="props.recoveryCodes?.length" class="mt-5" data-testid="mfa-recovery-codes">
                <div class="rounded-[13px] border border-[#d9a441] bg-[#fffaf0] p-4">
                    <p class="flex items-start gap-2 text-[13px] font-extrabold text-[#a9741b]">
                        <MdiAlertOutline class="mt-0.5 size-[18px] shrink-0" aria-hidden="true" />
                        Bewahren Sie diese Codes sicher auf.
                    </p>
                    <p class="mt-1.5 text-[12.5px] text-[#7a5310]">
                        Jeder Code funktioniert einmal und ersetzt Ihren zweiten Faktor, falls Sie keinen Zugriff mehr darauf haben. Sie werden nur
                        dieses eine Mal angezeigt.
                    </p>
                    <ul class="mt-3 grid grid-cols-2 gap-2 max-[420px]:grid-cols-1">
                        <li
                            v-for="code in props.recoveryCodes"
                            :key="code"
                            class="rounded-[9px] border border-[#e5c37e] bg-white px-3 py-2 text-center font-mono text-[13px] tracking-wider text-[#10393b]"
                        >
                            {{ code }}
                        </li>
                    </ul>
                </div>
            </div>

            <p
                v-else-if="props.enrolled"
                class="mt-5 flex items-start gap-2 rounded-[13px] border border-[#01b990]/25 bg-[#01b990]/5 px-3 py-2.5 text-[12.5px] font-bold text-[#0b7a63]"
                data-testid="mfa-enrolled"
            >
                <MdiCheckCircleOutline class="mt-0.5 size-[18px] shrink-0" aria-hidden="true" />
                Zwei-Faktor-Authentifizierung ist aktiv ({{ props.method === 'email' ? 'E-Mail-Code' : 'Authenticator-App' }}).
            </p>

            <!-- Enrollment -->
            <template v-if="!props.enrolled">
                <p
                    v-if="props.mandatory"
                    class="mt-3 rounded-[13px] border border-[#d9a441] bg-[#fffaf0] px-3 py-2.5 text-[12.5px] font-bold text-[#a9741b]"
                >
                    Für Ihr Konto ist eine Zwei-Faktor-Authentifizierung erforderlich. Bitte richten Sie sie jetzt ein.
                </p>

                <p
                    v-if="props.status"
                    role="status"
                    class="mt-3 rounded-[13px] border border-[#01b990]/25 bg-[#01b990]/5 px-3 py-2.5 text-[12.5px] font-bold text-[#0b7a63]"
                >
                    {{ props.status }}
                </p>

                <div class="mt-5 flex gap-2" role="tablist">
                    <button
                        v-for="option in [
                            { key: 'totp' as const, label: 'Authenticator-App', icon: MdiCellphoneKey },
                            { key: 'email' as const, label: 'E-Mail-Code', icon: MdiEmailOutline },
                        ]"
                        :key="option.key"
                        type="button"
                        role="tab"
                        :aria-selected="selected === option.key"
                        class="inline-flex min-h-[44px] flex-1 cursor-pointer items-center justify-center gap-2 rounded-[13px] border px-3 py-2 text-[12.5px] font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                        :class="
                            selected === option.key
                                ? 'border-[#01b990] bg-[#01b990]/5 text-[#0b7a63]'
                                : 'border-[#d8e4e2] bg-white text-[#6f8585] hover:border-[#01b990]'
                        "
                        :data-testid="`mfa-method-${option.key}`"
                        @click="choose(option.key)"
                    >
                        <component :is="option.icon" class="size-[18px] shrink-0" aria-hidden="true" />
                        {{ option.label }}
                    </button>
                </div>

                <div v-if="selected === 'totp'" class="mt-5">
                    <p class="text-[12.5px] text-[#6f8585]">
                        Fügen Sie dieses Konto in Ihrer Authenticator-App hinzu — per Link auf dem Handy oder indem Sie den Schlüssel manuell
                        eintippen.
                    </p>

                    <!-- eslint-disable-next-line vue/no-v-html -- server-generated
                         SVG from bacon/bacon-qr-code; contains no user input. -->
                    <div
                        v-if="props.qrCode"
                        class="mt-3 inline-block rounded-[13px] border border-[#e9efee] bg-white p-3 [&>svg]:block [&>svg]:size-[180px]"
                        data-testid="mfa-qr"
                        role="img"
                        aria-label="QR-Code zum Scannen mit Ihrer Authenticator-App"
                        v-html="props.qrCode"
                    />

                    <a
                        v-if="props.otpauthUri"
                        :href="props.otpauthUri"
                        class="mt-3 inline-flex min-h-[44px] items-center gap-2 rounded-[13px] border border-[#d8e4e2] bg-white px-4 py-2.5 text-[12.5px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                        data-testid="mfa-otpauth-link"
                    >
                        <MdiCellphoneKey class="size-[18px] shrink-0" aria-hidden="true" />
                        In Authenticator-App öffnen
                    </a>

                    <div class="mt-3 rounded-[13px] bg-[#f6f9f8] px-3 py-2.5">
                        <p class="text-[11.5px] text-[#9bb0af]">Falls Sie nicht scannen können: Schlüssel manuell eintragen</p>
                        <p class="mt-1 font-mono text-[13px] break-all text-[#10393b]" data-testid="mfa-secret">{{ props.secret }}</p>
                    </div>
                </div>

                <div v-else class="mt-5">
                    <p class="text-[12.5px] text-[#6f8585]">
                        Wir senden den Bestätigungscode an <strong>{{ props.email }}</strong
                        >.
                    </p>
                    <button
                        type="button"
                        class="mt-3 inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-[13px] border border-[#d8e4e2] bg-white px-4 py-2.5 text-[12.5px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                        data-testid="mfa-send-email"
                        @click="sendEmail"
                    >
                        <MdiEmailOutline class="size-[18px] shrink-0" aria-hidden="true" />
                        Code senden
                    </button>
                </div>

                <form class="mt-5 flex flex-col gap-3 border-t border-[#f2f6f5] pt-4" @submit.prevent="submit">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-bold text-[#10393b]">Bestätigungscode</span>
                        <Input
                            v-model="form.code"
                            placeholder="000000"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            class="text-center text-[20px] tracking-[6px]"
                            data-testid="mfa-confirm-code"
                        />
                        <InputError :message="form.errors.code" />
                    </label>

                    <Button type="submit" :disabled="form.processing || form.code.length === 0" data-testid="mfa-confirm"> Aktivieren </Button>
                </form>
            </template>
        </div>
    </div>
</template>

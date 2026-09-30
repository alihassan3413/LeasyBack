<script setup lang="ts">
/**
 * The second step of signing in.
 *
 * The page holds no ticket and no identity — the server keeps both. Everything
 * here is a six-digit code and the two ways of filling it.
 *
 * A recovery code is not six digits, so it keeps a single field: forcing it
 * into the boxed layout would mean lying about its shape.
 */
import InputError from '@/components/InputError.vue';
import OtpInput from '@/components/mfa/OtpInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MdiCellphoneKey from '~icons/mdi/cellphone-key';
import MdiEmailOutline from '~icons/mdi/email-outline';
import MdiLifebuoy from '~icons/mdi/lifebuoy';
import MdiLockCheckOutline from '~icons/mdi/lock-check-outline';

const props = defineProps<{
    method: 'totp' | 'email' | null;
    canUseRecoveryCode: boolean;
    emailCooldownSeconds: number;
    status?: string | null;
}>();

const useRecovery = ref(false);
const cooldown = ref(props.emailCooldownSeconds);
let timer: number | undefined;

const form = useForm({ code: '' });

const isEmail = computed(() => props.method === 'email');

const hint = computed(() => {
    if (useRecovery.value) {
        return 'Geben Sie einen Ihrer Notfallcodes ein. Jeder Code funktioniert genau einmal.';
    }

    return isEmail.value
        ? 'Wir haben Ihnen einen sechsstelligen Code per E-Mail gesendet.'
        : 'Öffnen Sie Ihre Authenticator-App und geben Sie den angezeigten Code ein.';
});

function tick() {
    window.clearInterval(timer);

    if (cooldown.value <= 0) {
        return;
    }

    timer = window.setInterval(() => {
        cooldown.value -= 1;

        if (cooldown.value <= 0) {
            window.clearInterval(timer);
        }
    }, 1000);
}

function submit() {
    form.post(route('mfa.verify.store'), { onError: () => form.reset('code') });
}

function resend() {
    router.post(
        route('mfa.send-email'),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                cooldown.value = 30;
                tick();
            },
        },
    );
}

function toggleRecovery() {
    useRecovery.value = !useRecovery.value;
    form.reset('code');
    form.clearErrors();
}

onMounted(tick);
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <Head title="Bestätigung" />

    <div class="flex min-h-screen items-center justify-center bg-[#f6f9f8] px-4 py-10">
        <div class="w-full max-w-md">
            <div class="rounded-3xl border border-[#ececec] bg-white p-7 max-[560px]:p-5">
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#01b990]/10 text-[#0b7a63]">
                        <MdiLockCheckOutline class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11.5px] font-bold tracking-wide text-[#9bb0af] uppercase">Leasyback · Anmeldung</p>
                        <h1 class="mt-0.5 text-[21px] leading-tight font-extrabold tracking-[-0.4px] text-[#10393b]">
                            {{ useRecovery ? 'Notfallcode eingeben' : 'Bestätigung' }}
                        </h1>
                    </div>
                </div>

                <p class="mt-3 text-[12.5px] leading-[1.5] text-[#6f8585]">{{ hint }}</p>

                <p
                    v-if="props.status"
                    role="status"
                    class="mt-4 rounded-[13px] border border-[#01b990]/25 bg-[#01b990]/5 px-3.5 py-3 text-[12.5px] font-bold text-[#0b7a63]"
                    data-testid="mfa-status"
                >
                    {{ props.status }}
                </p>

                <form class="mt-5" @submit.prevent="submit">
                    <template v-if="useRecovery">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-bold text-[#10393b]">Notfallcode</span>
                            <Input
                                v-model="form.code"
                                placeholder="XXXXX-XXXXX"
                                inputmode="text"
                                autocomplete="off"
                                autocapitalize="characters"
                                :maxlength="13"
                                autofocus
                                class="text-center font-mono text-[16px] tracking-[3px]"
                                :aria-invalid="Boolean(form.errors.code) || undefined"
                                data-testid="mfa-code"
                            />
                        </label>
                    </template>

                    <template v-else>
                        <p class="text-[12.5px] font-bold text-[#10393b]">Bestätigungscode</p>
                        <div class="mt-2.5">
                            <OtpInput
                                v-model="form.code"
                                :invalid="Boolean(form.errors.code)"
                                :disabled="form.processing"
                                autofocus
                                @complete="submit"
                            />
                        </div>
                    </template>

                    <InputError class="mt-2" :message="form.errors.code" />

                    <Button
                        type="submit"
                        class="mt-4 w-full"
                        :loading="form.processing"
                        :disabled="useRecovery ? form.code.length === 0 : form.code.length < 6"
                        data-testid="mfa-submit"
                    >
                        Bestätigen
                    </Button>
                </form>

                <div class="mt-5 flex flex-col gap-1 border-t border-[#f2f6f5] pt-4">
                    <button
                        v-if="isEmail && !useRecovery"
                        type="button"
                        class="inline-flex min-h-[40px] cursor-pointer items-center gap-2 self-start rounded-[9px] px-1 text-[12.5px] font-bold text-[#10393b] transition-colors hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:cursor-default disabled:opacity-50 motion-reduce:transition-none"
                        :disabled="cooldown > 0"
                        data-testid="mfa-resend"
                        @click="resend"
                    >
                        <MdiEmailOutline class="size-[18px] shrink-0" aria-hidden="true" />
                        <span v-if="cooldown > 0">Neuen Code in {{ cooldown }} s anfordern</span>
                        <span v-else>Neuen Code per E-Mail senden</span>
                    </button>

                    <button
                        v-if="props.canUseRecoveryCode"
                        type="button"
                        class="inline-flex min-h-[40px] cursor-pointer items-center gap-2 self-start rounded-[9px] px-1 text-[12.5px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                        data-testid="mfa-toggle-recovery"
                        @click="toggleRecovery"
                    >
                        <component :is="useRecovery ? MdiCellphoneKey : MdiLifebuoy" class="size-[18px] shrink-0" aria-hidden="true" />
                        {{ useRecovery ? 'Zurück zum Bestätigungscode' : 'Notfallcode verwenden' }}
                    </button>
                </div>
            </div>

            <p class="mt-4 text-center text-[11.5px] text-[#9bb0af]">Ihre Zugangsdaten werden niemals per E-Mail abgefragt.</p>
        </div>
    </div>
</template>

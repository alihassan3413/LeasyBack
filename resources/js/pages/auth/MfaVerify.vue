<script setup lang="ts">
/**
 * The second step of signing in.
 *
 * The page holds no ticket and no identity — the server keeps both. Everything
 * here is a six-digit field and the two ways of filling it.
 */
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiCellphoneKey from '~icons/mdi/cellphone-key';
import MdiEmailOutline from '~icons/mdi/email-outline';
import MdiLifebuoy from '~icons/mdi/lifebuoy';

const props = defineProps<{
    method: 'totp' | 'email' | null;
    canUseRecoveryCode: boolean;
    emailCooldownSeconds: number;
    status?: string | null;
}>();

const useRecovery = ref(false);

const form = useForm({ code: '' });

const isEmail = computed(() => props.method === 'email');

const heading = computed(() => {
    if (useRecovery.value) {
        return 'Wiederherstellungscode eingeben';
    }

    return isEmail.value ? 'Code aus Ihrer E-Mail eingeben' : 'Code aus Ihrer Authenticator-App eingeben';
});

function submit() {
    form.post(route('mfa.verify.store'), { onFinish: () => form.reset('code') });
}

function resend() {
    router.post(route('mfa.send-email'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Bestätigung" />

    <div class="flex min-h-screen items-center justify-center bg-[#f6f9f8] px-4 py-10">
        <div class="w-full max-w-md rounded-3xl border border-[#ececec] bg-white p-6">
            <p class="text-[12px] font-bold tracking-wide text-[#9bb0af] uppercase">Leasyback · Anmeldung</p>
            <h1 class="mt-1 text-[22px] font-extrabold tracking-[-0.4px] text-[#10393b]">Zwei-Faktor-Bestätigung</h1>
            <p class="mt-2 text-[13px] text-[#6f8585]">{{ heading }}</p>

            <p
                v-if="props.status"
                role="status"
                class="mt-4 rounded-[13px] border border-[#01b990]/25 bg-[#01b990]/5 px-3 py-2.5 text-[12.5px] font-bold text-[#0b7a63]"
                data-testid="mfa-status"
            >
                {{ props.status }}
            </p>

            <form class="mt-5 flex flex-col gap-4" @submit.prevent="submit">
                <label class="flex flex-col gap-1.5">
                    <span class="text-[12.5px] font-bold text-[#10393b]">
                        {{ useRecovery ? 'Wiederherstellungscode' : 'Bestätigungscode' }}
                    </span>
                    <Input
                        v-model="form.code"
                        :placeholder="useRecovery ? 'XXXXX-XXXXX' : '000000'"
                        :inputmode="useRecovery ? 'text' : 'numeric'"
                        :autocomplete="useRecovery ? 'off' : 'one-time-code'"
                        :maxlength="useRecovery ? 13 : 6"
                        autofocus
                        class="text-center text-[20px] tracking-[6px]"
                        data-testid="mfa-code"
                    />
                    <InputError :message="form.errors.code" />
                </label>

                <Button type="submit" :disabled="form.processing || form.code.length === 0" data-testid="mfa-submit"> Bestätigen </Button>
            </form>

            <div class="mt-5 flex flex-col gap-2 border-t border-[#f2f6f5] pt-4">
                <button
                    v-if="isEmail && !useRecovery"
                    type="button"
                    class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-[13px] px-2 text-[12.5px] font-bold text-[#10393b] transition-colors hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:opacity-50 motion-reduce:transition-none"
                    :disabled="props.emailCooldownSeconds > 0"
                    data-testid="mfa-resend"
                    @click="resend"
                >
                    <MdiEmailOutline class="size-[18px] shrink-0" aria-hidden="true" />
                    <span v-if="props.emailCooldownSeconds > 0"> Neuen Code in {{ props.emailCooldownSeconds }} Sekunden anfordern </span>
                    <span v-else>Neuen Code per E-Mail senden</span>
                </button>

                <button
                    v-if="props.canUseRecoveryCode"
                    type="button"
                    class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-[13px] px-2 text-[12.5px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                    data-testid="mfa-toggle-recovery"
                    @click="useRecovery = !useRecovery"
                >
                    <component :is="useRecovery ? MdiCellphoneKey : MdiLifebuoy" class="size-[18px] shrink-0" aria-hidden="true" />
                    {{ useRecovery ? 'Zurück zum Bestätigungscode' : 'Wiederherstellungscode verwenden' }}
                </button>
            </div>
        </div>
    </div>
</template>

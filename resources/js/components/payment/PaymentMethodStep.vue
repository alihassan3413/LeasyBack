<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { HttpError, http } from '@/lib/http';
import type { SharedData } from '@/types';
import { formatCard, formatCardExpiry, type ConfirmMandateResponse, type PaymentMandateSummary, type SetupIntentResponse } from '@/types/payment';
import { usePage } from '@inertiajs/vue3';
import type { Stripe, StripeElements } from '@stripe/stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import { CheckCircle2, ShieldCheck } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';

/**
 * The single payment-method step, shared by the onboarding wizard and the
 * dashboard return-process modal. Neither host knows anything about Stripe.
 *
 * The step never completes on the browser's own say-so: confirmSetup() only
 * produces a SetupIntent id, which the backend then re-reads from Stripe and
 * verifies before this emits `complete`.
 */
const props = withDefaults(
    defineProps<{
        orderId: string;
        /** Shown on the primary button once a card is ready to submit. */
        submitLabel?: string;
        showBack?: boolean;
    }>(),
    { submitLabel: 'Zahlungsmethode speichern', showBack: false },
);

const emit = defineEmits<{ complete: [summary: PaymentMandateSummary]; back: [] }>();

const page = usePage<SharedData>();
const uid = useId();
const consentId = `${uid}-offsession-consent`;

const phase = ref<'checking' | 'collecting' | 'saved' | 'unavailable'>('checking');
const error = ref<string | null>(null);
const consentError = ref<string | null>(null);
const submitting = ref(false);
const authorized = ref(false);
const authorizationText = ref('');
const summary = ref<PaymentMandateSummary | null>(null);

const cardElementRef = ref<HTMLDivElement | null>(null);
let stripe: Stripe | null = null;
let elements: StripeElements | null = null;
let setupIntentId = '';

const savedCard = computed(() => formatCard(summary.value?.card ?? null));
const savedCardExpiry = computed(() => formatCardExpiry(summary.value?.card ?? null));

/**
 * Strict `=== true` throughout, never truthiness: reka's CheckboxRoot models
 * `boolean | 'indeterminate'` and resolves "checked" to a configurable
 * `trueValue`, so a truthy-but-not-true value could otherwise satisfy the
 * consent gate.
 */
const hasConsent = computed(() => authorized.value === true);
const canSubmit = computed(() => phase.value === 'collecting' && hasConsent.value && !submitting.value);

function setConsent(value: unknown): void {
    authorized.value = value === true;
}

onMounted(async () => {
    try {
        const existing = await http.get<PaymentMandateSummary>(route('payments.method.show', props.orderId));

        // Already saved, verified and authorized — do not ask again.
        if (existing.usable) {
            summary.value = existing;
            phase.value = 'saved';
            emit('complete', existing);

            return;
        }

        await mountCardField();
    } catch (e) {
        failWith(e, 'Die Zahlungsmethode konnte nicht geladen werden.');
    }
});

onBeforeUnmount(() => elements?.getElement('payment')?.destroy());

async function mountCardField(): Promise<void> {
    const publishableKey = page.props.stripe?.key;

    if (!publishableKey) {
        phase.value = 'unavailable';
        error.value = 'Zahlungen sind derzeit nicht verfügbar. Bitte wenden Sie sich an Ihren Ansprechpartner.';

        return;
    }

    const intent = await http.post<SetupIntentResponse>(route('payments.method.intent', props.orderId));

    setupIntentId = intent.setup_intent_id;
    authorizationText.value = intent.authorization_text;

    stripe = await loadStripe(publishableKey);

    if (!stripe) {
        phase.value = 'unavailable';
        error.value = 'Zahlungen konnten nicht initialisiert werden. Bitte laden Sie die Seite neu.';

        return;
    }

    elements = stripe.elements({
        clientSecret: intent.client_secret,
        appearance: { theme: 'stripe', variables: { colorPrimary: '#01b990', borderRadius: '5px' } },
    });

    phase.value = 'collecting';

    // Mounted after the phase flip so the target div exists in the DOM.
    await nextRender();
    elements.create('payment', { fields: { billingDetails: 'auto' } }).mount(cardElementRef.value!);
}

function nextRender(): Promise<void> {
    return new Promise((resolve) => requestAnimationFrame(() => resolve()));
}

async function submit(): Promise<void> {
    consentError.value = null;
    error.value = null;

    // Checked before Stripe is touched: confirming the SetupIntent attaches the
    // card to the Stripe customer, which must not happen without consent even
    // though the backend would refuse to record a mandate for it.
    if (!hasConsent.value) {
        consentError.value = 'Bitte bestätigen Sie die Autorisierung, um fortzufahren.';

        return;
    }

    if (!stripe || !elements) {
        return;
    }

    submitting.value = true;

    try {
        const result = await stripe.confirmSetup({ elements, redirect: 'if_required' });

        if (result.error) {
            error.value = result.error.message ?? 'Die Zahlungsmethode konnte nicht hinterlegt werden.';

            return;
        }

        // Deliberately not trusting result.setupIntent.status: the backend
        // re-reads the intent from Stripe and checks customer and metadata
        // before anything is stored.
        const confirmed = await http.post<ConfirmMandateResponse>(route('payments.method.confirm', props.orderId), {
            setup_intent_id: result.setupIntent?.id ?? setupIntentId,
            // The real value, never a literal: the server's `accepted` rule is
            // the authority on consent, and hardcoding true here made it
            // unreachable and left the client guard as the only enforcement.
            offsession_authorized: hasConsent.value,
        });

        summary.value = {
            status: confirmed.status,
            usable: true,
            verified: true,
            authorized: true,
            card: confirmed.card,
        };
        phase.value = 'saved';
        emit('complete', summary.value);
    } catch (e) {
        failWith(e, 'Die Zahlungsmethode konnte nicht bestätigt werden. Bitte versuchen Sie es erneut.');
    } finally {
        submitting.value = false;
    }
}

function failWith(e: unknown, fallback: string): void {
    error.value = (e instanceof HttpError ? e.serverMessage() : null) ?? fallback;

    if (phase.value === 'checking') {
        phase.value = 'unavailable';
    }
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex items-start gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-emerald-600" aria-hidden="true" />
            <div class="space-y-1">
                <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-300">Jetzt werden 0,00 € abgebucht.</p>
                <p class="text-sm text-emerald-900/90 dark:text-emerald-300/90">
                    Es wird jetzt keine Zahlung durchgeführt. Ihre Zahlungsmethode wird nur sicher für den Prozess und mögliche spätere
                    Reparaturkosten hinterlegt.
                </p>
            </div>
        </div>

        <p v-if="phase === 'checking'" class="text-muted-foreground text-sm">Zahlungsmethode wird geprüft…</p>

        <div v-else-if="phase === 'saved'" class="flex items-center gap-3 rounded-md border p-4">
            <CheckCircle2 class="size-5 shrink-0 text-emerald-600" aria-hidden="true" />
            <div>
                <p class="text-sm font-semibold text-black dark:text-white">{{ savedCard || 'Zahlungsmethode hinterlegt' }}</p>
                <p v-if="savedCardExpiry" class="text-muted-foreground text-xs">Gültig bis {{ savedCardExpiry }}</p>
            </div>
        </div>

        <template v-else-if="phase === 'collecting'">
            <div ref="cardElementRef" class="rounded-md border p-3" />

            <div class="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                <Label :for="consentId" class="flex items-start space-x-2 text-sm font-normal">
                    <Checkbox :id="consentId" :model-value="authorized" class="mt-0.5" @update:model-value="setConsent" />
                    <span>{{ authorizationText }}</span>
                </Label>
                <InputError :message="consentError" class="mt-2" />
            </div>
        </template>

        <InputError :message="error" />

        <div v-if="phase === 'collecting' || showBack" class="flex flex-col-reverse items-center gap-3 border-t pt-5 sm:flex-row sm:justify-end">
            <Button
                v-if="showBack"
                type="button"
                class="bg-brand-orange hover:bg-brand-orange/90 w-full rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none sm:w-auto"
                @click="emit('back')"
            >
                Zurück
            </Button>
            <Button
                v-if="phase === 'collecting'"
                type="button"
                :disabled="!canSubmit"
                :loading="submitting"
                class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none sm:w-auto"
                @click="submit"
            >
                {{ submitting ? 'Wird gespeichert…' : props.submitLabel }}
            </Button>
        </div>
    </div>
</template>

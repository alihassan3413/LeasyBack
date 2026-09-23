<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { HttpError, http } from '@/lib/http';
import type { SharedData } from '@/types';
import { formatCard, formatEuro, type PaymentCheckoutSession, type PaymentObligationState } from '@/types/payment';
import { usePage } from '@inertiajs/vue3';
import type { Stripe, StripeElements } from '@stripe/stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import { CheckCircle2, CreditCard } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Settling one obligation the automatic off-session attempt could not finish —
 * a repair charge, or a cancellation fee.
 *
 * Purpose-agnostic on purpose: the two are settled the same way, and the only
 * difference the browser ever sees is which pair of endpoints it calls.
 *
 * The counterpart to PaymentMethodStep.vue and built the same way: the browser
 * confirms with Stripe, but nothing is called done until the server has re-read
 * the intent from Stripe itself. `stripe.confirmPayment()` reporting success is
 * a hint, not the fact.
 *
 * Which of the two gestures is shown is the server's decision, not this
 * component's — see RepairCheckoutSession.mode.
 */
const props = withDefaults(defineProps<{ orderId: string; purpose?: 'repair' | 'cancellation-fee' }>(), { purpose: 'repair' });

/** The route names differ only by the purpose segment. */
const routes = computed(() => ({
    show: `payments.${props.purpose}.show`,
    intent: `payments.${props.purpose}.intent`,
    sync: `payments.${props.purpose}.sync`,
}));

const heading = computed(() => (props.purpose === 'cancellation-fee' ? 'Stornogebühr' : 'Reparaturkosten'));

const emit = defineEmits<{ paid: [] }>();

const page = usePage<SharedData>();

const phase = ref<'checking' | 'authenticate' | 'collecting' | 'paid' | 'unavailable'>('checking');
const error = ref<string | null>(null);
const submitting = ref(false);
const state = ref<PaymentObligationState | null>(null);

const cardElementRef = ref<HTMLDivElement | null>(null);
let stripe: Stripe | null = null;
let elements: StripeElements | null = null;
let clientSecret = '';

const amountLabel = computed(() => (state.value ? formatEuro(state.value.amount) : ''));
const savedCard = computed(() => formatCard(state.value?.card ?? null));

onMounted(async () => {
    try {
        await start();
    } catch (e) {
        failWith(e, 'Die Zahlung konnte nicht geladen werden.');
    }
});

onBeforeUnmount(() => elements?.getElement('payment')?.destroy());

async function start(): Promise<void> {
    const current = await http.get<PaymentObligationState>(route(routes.value.show, props.orderId));

    state.value = current;

    if (current.settled) {
        phase.value = 'paid';

        return;
    }

    if (!current.payable) {
        phase.value = 'unavailable';
        error.value = current.label ?? 'Für diesen Auftrag ist derzeit keine Zahlung offen.';

        return;
    }

    const publishableKey = page.props.stripe?.key;

    if (!publishableKey) {
        phase.value = 'unavailable';
        error.value = 'Zahlungen sind derzeit nicht verfügbar. Bitte wenden Sie sich an Ihren Ansprechpartner.';

        return;
    }

    // The server picks the intent and the gesture. No intent id is sent up:
    // it is resolved from the order's own repair payment.
    const session = await http.post<PaymentCheckoutSession>(route(routes.value.intent, props.orderId));

    clientSecret = session.client_secret;
    stripe = await loadStripe(publishableKey);

    if (!stripe) {
        phase.value = 'unavailable';
        error.value = 'Zahlungen konnten nicht initialisiert werden. Bitte laden Sie die Seite neu.';

        return;
    }

    if (session.mode === 'authenticate') {
        phase.value = 'authenticate';

        return;
    }

    elements = stripe.elements({
        clientSecret: session.client_secret,
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
    if (!stripe) {
        return;
    }

    error.value = null;
    submitting.value = true;

    try {
        const result =
            phase.value === 'authenticate'
                ? await stripe.handleNextAction({ clientSecret })
                : await stripe.confirmPayment({ elements: elements!, redirect: 'if_required' });

        if (result.error) {
            error.value = result.error.message ?? 'Die Zahlung konnte nicht abgeschlossen werden.';
        }

        // Synced even when Stripe reported an error: the intent may still have
        // moved, and the server's reading of it is what the portal shows.
        await settle();
    } catch (e) {
        failWith(e, 'Die Zahlung konnte nicht abgeschlossen werden. Bitte versuchen Sie es erneut.');
    } finally {
        submitting.value = false;
    }
}

async function settle(): Promise<void> {
    const synced = await http.post<PaymentObligationState>(route(routes.value.sync, props.orderId));

    state.value = synced;

    if (synced.settled) {
        phase.value = 'paid';
        error.value = null;
        emit('paid');

        return;
    }

    error.value ??= synced.label ?? 'Die Zahlung ist noch nicht abgeschlossen.';
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
        <div v-if="state?.exists" class="flex items-start gap-3 rounded-md border p-4">
            <CreditCard class="mt-0.5 size-5 shrink-0 text-[#01b990]" aria-hidden="true" />
            <div class="space-y-0.5">
                <p class="text-sm font-semibold text-black dark:text-white">{{ heading }}: {{ amountLabel }}</p>
                <p v-if="savedCard" class="text-muted-foreground text-sm">Hinterlegte Zahlungsmethode: {{ savedCard }}</p>
            </div>
        </div>

        <p v-if="phase === 'checking'" class="text-muted-foreground text-sm">Zahlung wird geprüft…</p>

        <div v-else-if="phase === 'paid'" class="flex items-center gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-4">
            <CheckCircle2 class="size-5 shrink-0 text-emerald-600" aria-hidden="true" />
            <p class="text-sm font-semibold text-emerald-900">
                {{
                    props.purpose === 'cancellation-fee'
                        ? 'Zahlung erfolgreich. Die Stornogebühr ist beglichen.'
                        : 'Zahlung erfolgreich. Ihr Fahrzeug kann jetzt übergeben werden.'
                }}
            </p>
        </div>

        <p v-else-if="phase === 'authenticate'" class="text-sm text-black dark:text-white">
            Ihre Bank verlangt eine Bestätigung dieser Zahlung. Ihre hinterlegte Karte bleibt unverändert — Sie müssen keine neuen Kartendaten
            eingeben.
        </p>

        <div v-else-if="phase === 'collecting'" ref="cardElementRef" class="rounded-md border p-3" />

        <InputError :message="error" />

        <div v-if="phase === 'authenticate' || phase === 'collecting'" class="flex justify-end border-t pt-5">
            <Button
                type="button"
                :disabled="submitting"
                :loading="submitting"
                class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none sm:w-auto"
                @click="submit"
            >
                <template v-if="submitting">Wird verarbeitet…</template>
                <template v-else-if="phase === 'authenticate'">Zahlung bestätigen</template>
                <template v-else>{{ amountLabel }} jetzt bezahlen</template>
            </Button>
        </div>
    </div>
</template>

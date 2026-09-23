export interface PaymentCardSummary {
    brand: string | null;
    last4: string | null;
    exp_month: number | null;
    exp_year: number | null;
}

export interface PaymentMandateSummary {
    status: string;
    /** saved + verified + authorized. The only state that skips the card form. */
    usable: boolean;
    verified: boolean;
    authorized: boolean;
    card: PaymentCardSummary | null;
}

export interface SetupIntentResponse {
    client_secret: string;
    setup_intent_id: string;
    /** Rendered verbatim; the server stores a hash of this exact string. */
    authorization_text: string;
}

export interface ConfirmMandateResponse {
    status: string;
    card: PaymentCardSummary;
}

/** What is owed on one obligation, and whether the customer can act on it. */
export interface PaymentObligationState {
    purpose: 'repair' | 'cancellation_fee';
    exists: boolean;
    status: string | null;
    /** German, server-rendered — the client never maps a status to wording. */
    label: string | null;
    amount_cents: number;
    amount: string;
    currency: string;
    payable: boolean;
    settled: boolean;
    card: PaymentCardSummary | null;
}

/**
 * `authenticate` completes a challenge on an intent that already has a card:
 * no card field is shown, because asking for one would invite a second card
 * for a charge that is already authorized. `collect` needs a card.
 */
export type PaymentCheckoutMode = 'authenticate' | 'collect';

export interface PaymentCheckoutSession {
    mode: PaymentCheckoutMode;
    client_secret: string;
    payment_intent_id: string;
    amount_cents: number;
    currency: string;
}

export function formatEuro(amount: string | number): string {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(amount));
}

const BRAND_LABELS: Record<string, string> = {
    visa: 'Visa',
    mastercard: 'Mastercard',
    amex: 'American Express',
    discover: 'Discover',
    diners: 'Diners Club',
    jcb: 'JCB',
    unionpay: 'UnionPay',
};

/** e.g. "Visa •••• 4242" — the only card detail LeasyBack ever holds. */
export function formatCard(card: PaymentCardSummary | null): string {
    if (!card?.last4) {
        return '';
    }

    const brand = card.brand ? (BRAND_LABELS[card.brand] ?? card.brand) : 'Karte';

    return `${brand} •••• ${card.last4}`;
}

export function formatCardExpiry(card: PaymentCardSummary | null): string {
    if (!card?.exp_month || !card?.exp_year) {
        return '';
    }

    return `${String(card.exp_month).padStart(2, '0')}/${card.exp_year}`;
}

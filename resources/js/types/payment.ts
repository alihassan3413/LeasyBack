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

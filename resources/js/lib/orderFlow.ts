export interface OrderFlowStep {
    key: string;
    label: string;
    state: 'done' | 'current' | 'upcoming';
}

/**
 * Simplified linear "happy path" for the order-status timeline, matching
 * docs/B2C_ADMIN_STATUS_MATRIX.md §1's recommended transition table.
 * `order_requested`/`order_placed` collapse into a single "Gebucht" step
 * (Firmenkunde orders pass through both; Privatkunde orders skip straight
 * to `order_placed`) and `reworkshop` collapses into the "Werkstatt" step
 * rather than getting its own dot — the exact reinspection/reworkshop
 * cycle count isn't meaningful to show to a customer.
 */
const TIMELINE_STAGES: { key: string; label: string; statuses: string[] }[] = [
    { key: 'booked', label: 'Gebucht', statuses: ['order_requested', 'order_placed'] },
    { key: 'confirmed', label: 'Bestätigt', statuses: ['confirmed'] },
    { key: 'inspected', label: 'Begutachtet', statuses: ['inspected'] },
    { key: 'workshop', label: 'Werkstatt', statuses: ['workshop', 'reworkshop'] },
    { key: 'reinspection', label: 'Nachbegutachtung', statuses: ['reinspection'] },
    { key: 'delivered', label: 'Abgeschlossen', statuses: ['delivered'] },
];

const OFF_RAMP_LABELS: Record<string, string> = {
    cancelled: 'Storniert',
    discarded: 'Verworfen',
};

export function isTerminalOffRamp(status: string): boolean {
    return status in OFF_RAMP_LABELS;
}

export function getOffRampLabel(status: string): string | undefined {
    return OFF_RAMP_LABELS[status];
}

export function getOrderFlowSteps(status: string): OrderFlowStep[] {
    const currentIndex = TIMELINE_STAGES.findIndex((stage) => stage.statuses.includes(status));

    return TIMELINE_STAGES.map((stage, index) => ({
        key: stage.key,
        label: stage.label,
        state: currentIndex === -1 ? 'upcoming' : index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'upcoming',
    }));
}

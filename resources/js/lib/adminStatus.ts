export interface StatusPillStyle {
    label: string;
    background: string;
    color: string;
}

const ADMIN_DASHBOARD_STATUS_STYLES: Record<string, StatusPillStyle> = {
    order_placed: { label: 'Angefragt', background: 'rgba(239, 132, 80, 0.12)', color: '#c0622e' },
    confirmed: { label: 'Bestätigt', background: 'rgba(99, 102, 241, 0.12)', color: '#4f46e5' },
    inspected: { label: 'Geprüft', background: 'rgba(1, 185, 144, 0.12)', color: '#00856a' },
    workshop: { label: 'In Werkstatt', background: 'rgba(245, 158, 11, 0.12)', color: '#b45309' },
    reinspection: { label: 'Nachprüfung', background: 'rgba(124, 58, 237, 0.12)', color: '#6d28d9' },
    reworkshop: { label: 'Erneut in Werkstatt', background: 'rgba(234, 88, 12, 0.12)', color: '#c2410c' },
    delivered: { label: 'Geliefert', background: 'rgba(16, 57, 59, 0.09)', color: '#10393b' },
    completed: { label: 'Abgeschlossen', background: 'rgba(1, 185, 144, 0.12)', color: '#00856a' },
    discarded: { label: 'Verworfen', background: 'rgba(107, 114, 128, 0.12)', color: '#374151' },
    cancelled: { label: 'Storniert', background: 'rgba(220, 38, 38, 0.10)', color: '#991b1b' },
};

export function getAdminDashboardStatus(status: string | null | undefined): StatusPillStyle {
    if (!status) {
        return { label: 'Kein Status', background: 'rgba(0, 0, 0, 0.05)', color: '#6f8585' };
    }

    return ADMIN_DASHBOARD_STATUS_STYLES[status] ?? { label: status, background: 'rgba(0, 0, 0, 0.05)', color: '#6f8585' };
}

export const ADMIN_ORDER_STATUS_FILTERS: { value: string; label: string }[] = [
    { value: '', label: 'Alle' },
    { value: 'order_requested', label: 'Anfrage gesendet' },
    { value: 'order_placed', label: 'Bestellt' },
    { value: 'confirmed', label: 'Bestätigt' },
    { value: 'inspected', label: 'Geprüft' },
    { value: 'workshop', label: 'In Werkstatt' },
    { value: 'reinspection', label: 'Nachprüfung' },
    { value: 'reworkshop', label: 'Erneut in Werkstatt' },
    { value: 'delivered', label: 'Geliefert' },
    { value: 'completed', label: 'Abgeschlossen' },
    { value: 'discarded', label: 'Verworfen' },
    { value: 'cancelled', label: 'Storniert' },
];

import AdminOrderTasksCard from '@/components/admin/AdminOrderTasksCard.vue';
import AdminRepairBillingCard from '@/components/admin/AdminRepairBillingCard.vue';
import type { AdminOrderDetachedTask, AdminOrderTaskPriority, AdminOrderTasks } from '@/types/admin';
import { createApp, h, ref } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);

function detachedTask(key: string): AdminOrderDetachedTask {
    const titles: Record<string, string> = {
        call_customer_about_pending_offer: 'Kunden zum offenen Angebot anrufen',
        call_customer_about_pending_payment: 'Kunden zur offenen Zahlung anrufen',
    };

    return {
        key,
        title: titles[key] ?? key,
        description: 'Follow-up seit über 48 Stunden offen.',
        state: 'open',
        actor: 'admin',
        date: '2026-09-01T09:00:00+02:00',
        date_label: 'Versendet am',
        priority_date: '2026-09-01T09:00:00+02:00',
        section: 'status',
        action: null,
        priority: 'immediate_red',
    };
}

const tasks = ref<AdminOrderTasks>({
    next: {
        key: 'await_repair_payment',
        title: 'Zahlungseingang abwarten',
        description: 'Die Reparaturkosten sind noch nicht bezahlt.',
        state: 'waiting',
        actor: 'customer',
        date: '2026-09-01T09:00:00+02:00',
        date_label: 'Abholbereit seit',
        priority_date: '2026-09-01T09:00:00+02:00',
        section: 'status',
        action: null,
    },
    history: [],
    is_closed: false,
    closed_status: null,
    priority: (params.get('priority') ?? 'neutral') as AdminOrderTaskPriority,
    detached: (params.get('detached') ?? '')
        .split(',')
        .filter((key) => key !== '')
        .map(detachedTask),
});

const billing = params.get('billing');

const BILLING_FIXTURES: Record<string, Record<string, unknown>> = {
    awaiting: {
        invoice: {
            voucher_number: 'RE-2026-118',
            status: 'documented',
            invoiced_at: '2026-09-01T09:00:00+02:00',
            documented_at: '2026-09-01T09:00:00+02:00',
        },
        payment: {
            purpose: 'repair',
            status: 'pending',
            amount_cents: 71400,
            currency: 'eur',
            paid_at: null,
            payment_link_url: 'https://pay.stripe.test/plink_1',
            payment_link_created_at: '2026-09-01T09:00:00+02:00',
            blocks_pickup: true,
        },
        stage: 'awaiting_payment',
    },
    paid: {
        invoice: {
            voucher_number: 'RE-2026-118',
            status: 'documented',
            invoiced_at: '2026-09-01T09:00:00+02:00',
            documented_at: '2026-09-01T09:00:00+02:00',
        },
        payment: {
            purpose: 'repair',
            status: 'paid',
            amount_cents: 71400,
            currency: 'eur',
            paid_at: '2026-09-02T11:30:00+02:00',
            payment_link_url: 'https://pay.stripe.test/plink_1',
            payment_link_created_at: '2026-09-01T09:00:00+02:00',
            blocks_pickup: false,
        },
        stage: 'payment_settled',
    },
    review: {
        invoice: { voucher_number: null, status: 'needs_reconciliation', invoiced_at: null, documented_at: null },
        payment: null,
        stage: 'none',
    },
};

createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'flex flex-col gap-4 bg-[#EFEFEF] p-4' }, [
                h(AdminOrderTasksCard, { tasks: tasks.value }),
                ...(billing && BILLING_FIXTURES[billing] ? [h(AdminRepairBillingCard, BILLING_FIXTURES[billing] as never)] : []),
            ]);
    },
}).mount('#app');

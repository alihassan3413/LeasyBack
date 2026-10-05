import WorkshopDocumentActions from '@/components/workshop/WorkshopDocumentActions.vue';
import { createApp, h } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const pdfUrl = params.get('pdfUrl') ?? '/werkstatt/angebot/tokentokentoken/pdf';

createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#f6f9f8] p-4' }, [
                h('div', { class: 'mx-auto max-w-3xl rounded-3xl border border-[#ececec] bg-white p-6' }, [
                    h('h1', { class: 'mb-2 text-[22px] font-extrabold text-[#10393b]' }, 'Angebot abgeben'),
                    h(WorkshopDocumentActions, {
                        pdfUrl,
                        // Stands in for the quotation form: an unsent price on one position.
                        draft: () => ({ items: [{ appraisal_position_id: 'pos-1', amount_net: '924.00', not_repairable: false }] }),
                    }),
                ]),
            ]);
    },
}).mount('#app');

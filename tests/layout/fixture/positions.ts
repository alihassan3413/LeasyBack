import AdminAppraisalPositionsCard from '@/components/admin/AdminAppraisalPositionsCard.vue';
import type { AdminAppraisalPosition, AdminReportDocument } from '@/types/admin';
import { createApp, h } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const COLORS = ['#c0392b', '#2f9e77', '#1a4a9c'];

function imageUrl(index: number): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="${COLORS[index % COLORS.length]}"/></svg>`;

    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

function reportDocument(id: string, isImage: boolean, title: string | null): AdminReportDocument {
    return {
        id,
        auftragsnummer: 'AUF-00000001',
        vehicle_id: 'vehicle-1',
        document_type: 'gutachten',
        document_title: title,
        path: `vehicle-reports/AUF-00000001/${id}.${isImage ? 'jpg' : 'pdf'}`,
        published: false,
        signed_url: null,
        is_image: isImage,
        created_at: '2026-09-01T09:00:00+02:00',
        updated_at: '2026-09-01T09:00:00+02:00',
    };
}

function position(id: string, sortOrder: number, component: string, imageIds: string[]): AdminAppraisalPosition {
    return {
        id,
        sort_order: sortOrder,
        component,
        damage_description: null,
        original_amount_net: '500.00',
        chargeable_amount_net: null,
        effective_amount_net: '500.00',
        repair_method: null,
        source: 'manual',
        damage_image_document_ids: imageIds,
    };
}

const reportDocuments = [
    reportDocument('report-pdf', false, 'Erstgutachten'),
    reportDocument('image-1', true, 'Stoßfänger vorne'),
    reportDocument('image-2', true, null),
    reportDocument('image-3', true, null),
];

const urls = new Map(reportDocuments.filter((document) => document.is_image).map((document, index) => [document.id, imageUrl(index)]));

function route(name: string, id?: string): string {
    return name === 'admin.vehicles.reports.image' && id ? (urls.get(id) ?? '') : '/';
}

Object.assign(window, { route });

const positions = [position('position-1', 0, 'Stoßfänger vorne', ['image-1', 'report-pdf']), position('position-2', 1, 'Kotflügel links', [])];

const app = createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#EFEFEF] p-4' }, [
                h(AdminAppraisalPositionsCard, {
                    orderId: 'order-1',
                    positions,
                    totals: { count: 2, original_total_net: '1000.00', chargeable_total_net: '1000.00' },
                    reportDocuments,
                    editable: params.get('editable') !== '0',
                }),
            ]);
    },
});

app.config.globalProperties.route = route as never;
app.mount('#app');

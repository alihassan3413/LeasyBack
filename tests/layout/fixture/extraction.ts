import AdminAppraisalExtractionCard from '@/components/admin/AdminAppraisalExtractionCard.vue';
import type { AdminAppraisalExtraction, AdminAppraisalExtractionLine, AdminAppraisalExtractionStatus, AdminReportDocument } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import './app.css';

router.reload = (() => undefined) as never;

const params = new URLSearchParams(location.search);
const state = params.get('state') ?? 'idle';
const documentCount = Number.parseInt(params.get('documents') ?? '1', 10);
const editable = params.get('editable') !== '0';

const PHOTO_COLORS: Record<string, string> = { 'photo-1': '#c0392b', 'photo-2': '#2f9e77', 'photo-3': '#1a4a9c' };

function photoUrl(id: string): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="${PHOTO_COLORS[id] ?? '#10393b'}"/></svg>`;

    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

function route(name: string, id?: string): string {
    if (name === 'admin.vehicles.reports.image' && id) {
        return photoUrl(id);
    }

    return name === 'admin.orders.appraisal-extractions.store' ? '/admin/orders/order-1/appraisal-extractions' : '/';
}

Object.assign(window, { route });

function reportDocument(id: string, type: string, title: string, isPdf: boolean): AdminReportDocument {
    return {
        id,
        auftragsnummer: 'AUF-00000001',
        vehicle_id: 'vehicle-1',
        document_type: type,
        document_title: title,
        path: `vehicle-reports/AUF-00000001/${id}.${isPdf ? 'pdf' : 'jpg'}`,
        published: false,
        signed_url: null,
        is_image: !isPdf,
        is_pdf: isPdf,
        created_at: '2026-09-01T09:00:00+02:00',
        updated_at: '2026-09-01T09:00:00+02:00',
    };
}

const reportDocuments = [
    reportDocument('photo-1', 'gutachten', 'Schadenbild 1', false),
    reportDocument('photo-2', 'gutachten', 'Schadenbild 2', false),
    reportDocument('photo-3', 'gutachten', 'Schadenbild 3', false),
    ...(documentCount > 0 ? [reportDocument('doc-1', 'gutachten', 'Erstgutachten', true)] : []),
    ...(documentCount > 1 ? [reportDocument('doc-2', 'nachgutachten', 'Nachgutachten', true)] : []),
];

function line(overrides: Partial<AdminAppraisalExtractionLine> = {}): AdminAppraisalExtractionLine {
    return {
        component: 'Stoßfänger hinten',
        damage_description: 'verkratzt / verschürft',
        original_amount_net: '120.00',
        chargeable_amount_net: null,
        repair_method: 'Smart Repair',
        page_number: 3,
        source_text: '2 Stossfänger hinten - verkratzt / verschürft - 120,00 €',
        confidence: 0.9,
        damage_number: 1,
        suggested_images: [{ document_id: 'photo-1', url: photoUrl('photo-1'), strategy: 'damage_number' }],
        ...overrides,
    };
}

const PROPOSAL_LINES: AdminAppraisalExtractionLine[] = [
    line(),
    line({
        component: 'Heckdeckel, Innenverkleidung',
        damage_description: 'Abrieb',
        original_amount_net: '80.00',
        chargeable_amount_net: '60.00',
        source_text: '1 Heckdeckel, Innenverkleidung - Abrieb - 80,00 €',
        confidence: 0.45,
        damage_number: 2,
        suggested_images: [
            { document_id: 'photo-2', url: photoUrl('photo-2'), strategy: 'text' },
            { document_id: 'photo-3', url: photoUrl('photo-3'), strategy: 'text' },
        ],
    }),
    line({
        component: 'Kotflügel links',
        damage_description: 'Delle',
        original_amount_net: '200.00',
        page_number: 4,
        source_text: null,
        damage_number: 3,
        suggested_images: [],
    }),
];

function extraction(status: AdminAppraisalExtractionStatus, overrides: Partial<AdminAppraisalExtraction> = {}): AdminAppraisalExtraction {
    return {
        id: `extraction-${status}`,
        status,
        source: status === 'ready' ? 'parser' : null,
        source_document_id: 'doc-1',
        extractor_version: 'pdf-gutachten-1/pdftotext-layout-1/502fce3a',
        attempts: 1,
        line_count: 0,
        total_net: null,
        appraisal_number: null,
        appraisal_date: null,
        lines: [],
        warnings: [],
        error_code: null,
        error_message: null,
        requested_by_user_id: 1,
        applied_at: null,
        applied_by_name: null,
        created_at: '2026-09-22T10:00:00+02:00',
        started_at: null,
        completed_at: null,
        failed_at: null,
        ...overrides,
    };
}

const READY = extraction('ready', {
    lines: PROPOSAL_LINES,
    line_count: 12,
    total_net: '2416.13',
    appraisal_number: '42772146',
    appraisal_date: '2025-12-02',
    completed_at: '2026-09-22T10:02:00+02:00',
    warnings: [
        { code: 'missing_positions', message: 'Die erkannten Positionen liegen 583.87 unter der Gesamtsumme 3000.00.' },
        { code: 'vin_mismatch', message: 'Die FIN im Gutachten weicht vom Fahrzeug ab.' },
        { code: 'low_confidence', message: 'Position 4 wurde mit geringer Sicherheit erkannt.' },
        { code: 'duplicate_component', message: 'Bauteil kommt mehrfach vor.' },
    ],
});

const STATES: Record<string, AdminAppraisalExtraction[]> = {
    idle: [],
    pending: [extraction('pending')],
    processing: [extraction('processing', { started_at: '2026-09-22T10:01:00+02:00' })],
    ready: [READY],
    failed: [
        extraction('failed', {
            error_code: 'unsupported_document',
            error_message: 'The PDF carries no usable text layer.',
            failed_at: '2026-09-22T10:01:30+02:00',
            attempts: 1,
        }),
    ],
    applied: [
        extraction('applied', {
            lines: PROPOSAL_LINES,
            line_count: 3,
            total_net: '400.00',
            completed_at: '2026-09-22T10:02:00+02:00',
            applied_at: '2026-09-22T10:05:00+02:00',
            applied_by_name: 'Admin Person',
        }),
    ],
    history: [
        READY,
        extraction('failed', { id: 'extraction-old-1', error_code: 'unsupported_document', created_at: '2026-09-21T08:00:00+02:00' }),
        extraction('discarded', { id: 'extraction-old-2', created_at: '2026-09-20T08:00:00+02:00' }),
    ],
};

const app = createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#EFEFEF] p-4' }, [
                h(AdminAppraisalExtractionCard, {
                    orderId: 'order-1',
                    extractions: STATES[state] ?? [],
                    reportDocuments,
                    editable,
                }),
            ]);
    },
});

app.config.globalProperties.route = route as never;
app.mount('#app');

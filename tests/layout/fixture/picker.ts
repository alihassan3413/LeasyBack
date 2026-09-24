import DamageImagePicker from '@/components/admin/DamageImagePicker.vue';
import type { AdminReportDocument } from '@/types/admin';
import { createApp, h, ref } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const imageCount = Number.parseInt(params.get('images') ?? '3', 10);
const COLORS = ['#c0392b', '#2f9e77', '#1a4a9c', '#9a5b00', '#10393b', '#6f8585'];

function imageUrl(index: number, width = 1200, height = 800): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="${width}" height="${height}" fill="${COLORS[index % COLORS.length]}"/></svg>`;

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

const documents: AdminReportDocument[] = [
    reportDocument('report-pdf', false, 'Erstgutachten'),
    ...Array.from({ length: imageCount }, (_, index) => reportDocument(`image-${index + 1}`, true, index === 0 ? 'Stoßfänger vorne' : null)),
];

const urls = new Map(
    documents
        .filter((document) => document.is_image)
        .map((document, index) => [document.id, { full: imageUrl(index), thumbnail: imageUrl(index, 400, 267) }]),
);

/**
 * Mirrors Ziggy's signature: the picker asks for the full image with a bare id
 * and for the derived thumbnail with a parameter object.
 */
function route(name: string, params: string | { documentId: string; size?: string }): string {
    if (name !== 'admin.vehicles.reports.image') {
        return '';
    }

    const entry = urls.get(typeof params === 'string' ? params : params.documentId);

    if (entry === undefined) {
        return '';
    }

    return typeof params !== 'string' && params.size === 'thumb' ? entry.thumbnail : entry.full;
}

Object.assign(window, { route });

const selected = ref<string[]>((params.get('selected') ?? '').split(',').filter((id) => id !== ''));
const disabled = params.get('disabled') === '1';

const app = createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#f6f9f8] p-4' }, [
                h('div', { class: 'content-card' }, [
                    h(DamageImagePicker, {
                        modelValue: selected.value,
                        'onUpdate:modelValue': (value: string[]) => {
                            selected.value = value;
                        },
                        documents,
                        disabled,
                        label: 'Schadenbilder Position 1',
                    }),
                ]),
                h('output', { id: 'model' }, JSON.stringify(selected.value)),
            ]);
    },
});

app.config.globalProperties.route = route as never;
app.mount('#app');

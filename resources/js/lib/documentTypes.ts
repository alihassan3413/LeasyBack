import type { SelectFieldOption } from '@/components/form/SelectField.vue';

/**
 * Canonical lowercase document_type values. customerOrderFlow.ts matches on
 * these exact strings to decide which timeline step a document belongs to, so
 * they must never be free text.
 */
export const DOCUMENT_TYPE_LABELS: Record<string, string> = {
    gutachten: 'Gutachten',
    nachgutachten: 'Nachgutachten',
    rechnung: 'Rechnung',
    leasingvertrag: 'Leasingvertrag',
    vorschaden: 'Vorschaden',
    sonstiges: 'Sonstiges',
};

export const REPORT_DOCUMENT_TYPES: SelectFieldOption[] = [
    { value: 'gutachten', label: 'Gutachten' },
    { value: 'nachgutachten', label: 'Nachgutachten' },
    { value: 'sonstiges', label: 'Sonstiges' },
];

export const INVOICE_DOCUMENT_TYPE = 'rechnung';

export function labelForDocumentType(type: string | null | undefined): string {
    if (!type) {
        return 'Dokument';
    }

    return DOCUMENT_TYPE_LABELS[type.trim().toLowerCase()] ?? type;
}

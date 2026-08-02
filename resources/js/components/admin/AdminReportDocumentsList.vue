<script setup lang="ts">
/**
 * Admin-only list of an order's report/invoice documents with their release
 * state, shown inside VehicleExpandedPanel's Fahrzeugdokumente card.
 *
 * The panel's own grouped list deliberately hides Gutachten/Nachgutachten
 * (they surface as download links on the timeline instead) and never shows a
 * draft, because the customer only ever receives published documents. That
 * left Admin with no way to release an upload without opening the vehicle
 * detail page — which matters now that uploads default to draft.
 *
 * Extracted rather than inlined because the panel keeps separate desktop and
 * mobile markup, and this would otherwise be written twice.
 */
import type { OrderReportDocumentData } from '@/types/vehicle';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface AdminPanelReportDocument extends OrderReportDocumentData {
    auftragsnummer: string;
}

withDefaults(defineProps<{ documents: AdminPanelReportDocument[]; compact?: boolean }>(), { compact: false });

const publishingId = ref<string | null>(null);

function togglePublished(document: AdminPanelReportDocument) {
    publishingId.value = document.id;

    router.patch(
        route('admin.vehicles.reports.publish', document.id),
        { published: !document.published },
        { preserveScroll: true, onFinish: () => (publishingId.value = null) },
    );
}

function label(document: AdminPanelReportDocument): string {
    return document.document_title || document.document_type || 'Dokument';
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div>
            <p class="text-[16px] font-semibold text-[#000000] uppercase">Gutachten &amp; Rechnungen</p>
            <div class="mt-2 h-px bg-gray-200"></div>
        </div>

        <p v-if="!documents.length" class="text-[14px] text-[#b7c2c2]">Keine Gutachten oder Rechnungen vorhanden</p>

        <div v-for="document in documents" :key="document.id" class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between gap-3">
                <span class="min-w-0 flex-1 truncate text-[14px] font-normal text-[#475569]" :title="label(document)">
                    {{ label(document) }}
                </span>

                <a
                    v-if="document.url"
                    :href="document.url"
                    target="_blank"
                    rel="noopener"
                    class="flex-shrink-0 text-[#01b990] hover:opacity-70"
                    title="Herunterladen"
                >
                    <IconMaterialSymbolsDownload class="size-[18.5px] shrink-0" />
                </a>
            </div>

            <div class="flex items-center justify-between gap-2">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10.5px] font-bold"
                    :class="document.published ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#f4f7f6] text-[#9bb0af]'"
                >
                    <span class="h-[4px] w-[4px] rounded-full bg-current"></span>
                    {{ document.published ? 'Veröffentlicht' : 'Entwurf' }}
                </span>

                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-bold transition-all disabled:opacity-40"
                    :class="[
                        compact ? 'text-[10.5px]' : 'text-[11px]',
                        document.published
                            ? 'border-[#ececec] text-[#6f8585] hover:border-[#EF4444] hover:text-[#EF4444]'
                            : 'border-[#01B990] text-[#00856a] hover:bg-[#01B990] hover:text-white',
                    ]"
                    :title="document.published ? 'Dokument wieder als Entwurf verbergen' : 'Für den Kunden freigeben — der Kunde wird benachrichtigt'"
                    :disabled="publishingId === document.id"
                    @click.stop="togglePublished(document)"
                >
                    <IconMdiEyeOffOutline v-if="document.published" class="size-[13px]" />
                    <IconMdiEyeOutline v-else class="size-[13px]" />
                    {{ document.published ? 'Zurückziehen' : 'Freigeben' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>

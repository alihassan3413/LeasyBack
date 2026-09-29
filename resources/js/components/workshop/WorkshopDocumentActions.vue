<script setup lang="ts">
/**
 * Download and print actions for the workshop's own copy of the quotation.
 *
 * One server-rendered document serves both: `?download=1` returns it as an
 * attachment, the bare URL opens it inline so the browser's own print dialog
 * handles it. Deliberately plain links — no fetch, no blob, no second
 * print-only page, and nothing that touches the submission form's state.
 */
import MdiFilePdfBox from '~icons/mdi/file-pdf-box';
import MdiPrinterOutline from '~icons/mdi/printer-outline';

const props = defineProps<{ pdfUrl: string }>();

const action =
    'inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-[13px] border border-[#d8e4e2] bg-white px-4 py-2.5 ' +
    'text-[13px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none';
</script>

<template>
    <div class="flex flex-wrap gap-2" data-testid="quotation-document-actions">
        <a :href="`${props.pdfUrl}?download=1`" :class="action" data-testid="quotation-pdf-download">
            <MdiFilePdfBox class="size-5 shrink-0" aria-hidden="true" />
            PDF herunterladen
        </a>

        <a :href="props.pdfUrl" target="_blank" rel="noopener" :class="action" data-testid="quotation-pdf-print">
            <MdiPrinterOutline class="size-5 shrink-0" aria-hidden="true" />
            Drucken
            <span class="sr-only">(öffnet das PDF in einem neuen Tab)</span>
        </a>
    </div>
</template>

<script setup lang="ts">
/**
 * Download and print actions for the workshop's own copy of the quotation.
 *
 * The PDF is built from what the form currently holds — prices typed but not
 * yet sent included — so it is posted rather than linked: a half-filled form
 * does not fit in a URL. The server stores nothing from this request. Print
 * opens the returned document in a new tab so the browser's own PDF viewer
 * handles printing; the tab is opened inside the click so it is not blocked.
 */
import { ref } from 'vue';
import MdiFilePdfBox from '~icons/mdi/file-pdf-box';
import MdiPrinterOutline from '~icons/mdi/printer-outline';

const props = defineProps<{
    pdfUrl: string;
    /** The form's current values, read at the moment a button is pressed. */
    draft: () => Record<string, unknown>;
}>();

const busy = ref(false);
const error = ref<string | null>(null);

const action =
    'inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-[13px] border border-[#d8e4e2] bg-white px-4 py-2.5 ' +
    'text-[13px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none ' +
    'disabled:cursor-wait disabled:opacity-60';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function fetchPdf(download: boolean): Promise<{ blob: Blob; filename: string }> {
    const response = await fetch(download ? `${props.pdfUrl}?download=1` : props.pdfUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/pdf, application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(props.draft()),
    });

    if (!response.ok) {
        throw new Error(
            response.status === 422
                ? 'Bitte prüfen Sie Ihre Eingaben — einige Werte können nicht gedruckt werden.'
                : 'Das PDF konnte nicht erstellt werden. Bitte versuchen Sie es erneut.',
        );
    }

    const disposition = response.headers.get('Content-Disposition') ?? '';
    const filename = disposition.match(/filename="([^"]+)"/)?.[1] ?? 'LeasyBack-Werkstattangebot.pdf';

    return { blob: await response.blob(), filename };
}

async function run(download: boolean) {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    // Opened synchronously, inside the click, or the browser blocks it.
    const tab = download ? null : window.open('', '_blank');

    try {
        const { blob, filename } = await fetchPdf(download);
        const url = URL.createObjectURL(blob);

        if (tab) {
            tab.location.href = url;
        } else {
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            link.click();
        }

        setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (exception) {
        tab?.close();
        error.value = exception instanceof Error ? exception.message : 'Das PDF konnte nicht erstellt werden.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div data-testid="quotation-document-actions">
        <div class="flex flex-wrap gap-2">
            <button type="button" :class="action" :disabled="busy" data-testid="quotation-pdf-download" @click="run(true)">
                <MdiFilePdfBox class="size-5 shrink-0" aria-hidden="true" />
                PDF herunterladen
            </button>

            <button type="button" :class="action" :disabled="busy" data-testid="quotation-pdf-print" @click="run(false)">
                <MdiPrinterOutline class="size-5 shrink-0" aria-hidden="true" />
                Drucken
                <span class="sr-only">(öffnet das PDF in einem neuen Tab)</span>
            </button>
        </div>

        <p class="mt-2 text-[12px] text-[#6f8585]">Das PDF enthält Ihre aktuellen Eingaben, auch wenn sie noch nicht abgesendet sind.</p>
        <p v-if="error" role="alert" class="mt-2 text-[12.5px] font-semibold text-[#c0392b]">{{ error }}</p>
    </div>
</template>

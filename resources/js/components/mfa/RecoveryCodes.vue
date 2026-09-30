<script setup lang="ts">
/**
 * The codes are shown exactly once, so this screen is a save workflow rather
 * than a list: download, copy or print, then confirm before the way out opens.
 *
 * The file is built in the browser from the codes already on the page — no
 * second request, and nothing extra for the server to hold.
 */
import { Button } from '@/components/ui/button';
import { ref } from 'vue';
import MdiAlertOutline from '~icons/mdi/alert-outline';
import MdiCheck from '~icons/mdi/check';
import MdiContentCopy from '~icons/mdi/content-copy';
import MdiDownload from '~icons/mdi/download';
import MdiPrinter from '~icons/mdi/printer';

const props = defineProps<{ codes: string[]; email: string }>();

const emit = defineEmits<{ (e: 'acknowledged'): void }>();

const saved = ref(false);
const copied = ref(false);
const downloaded = ref(false);

function fileContents(): string {
    return [
        'Leasyback — Notfallcodes zur Zwei-Faktor-Authentifizierung',
        `Konto: ${props.email}`,
        `Erstellt am: ${new Date().toLocaleString('de-DE')}`,
        '',
        'Jeder Code funktioniert genau einmal. Bewahren Sie diese Datei sicher auf.',
        '',
        ...props.codes.map((code, index) => `${String(index + 1).padStart(2, '0')}. ${code}`),
        '',
    ].join('\n');
}

function download() {
    const blob = new Blob([fileContents()], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = 'leasyback-notfallcodes.txt';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);

    downloaded.value = true;
}

async function copy() {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

function print() {
    // Its own window, so the surrounding application chrome is not printed and
    // no print stylesheet has to be maintained for one screen.
    const sheet = window.open('', '_blank', 'width=600,height=700');

    if (!sheet) {
        return;
    }

    sheet.document.write(
        `<title>Leasyback Notfallcodes</title><pre style="font:14px ui-monospace,Menlo,monospace;padding:24px">${fileContents()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')}</pre>`,
    );
    sheet.document.close();
    sheet.focus();
    sheet.print();
}
</script>

<template>
    <div data-testid="mfa-recovery-codes">
        <div class="flex items-start gap-2.5 rounded-[13px] border border-[#d9a441] bg-[#fffaf0] p-4">
            <MdiAlertOutline class="mt-0.5 size-[18px] shrink-0 text-[#a9741b]" aria-hidden="true" />
            <div class="min-w-0">
                <p class="text-[13px] font-extrabold text-[#a9741b]">Diese Codes werden nur einmal angezeigt.</p>
                <p class="mt-1 text-[12.5px] leading-[1.5] text-[#7a5310]">
                    Jeder Code funktioniert genau einmal und ersetzt Ihren zweiten Faktor, falls Sie keinen Zugriff mehr darauf haben.
                </p>
            </div>
        </div>

        <ul class="mt-4 grid grid-cols-2 gap-2 max-[420px]:grid-cols-1">
            <li
                v-for="(code, index) in props.codes"
                :key="code"
                class="flex items-center gap-2 rounded-[11px] border border-[#e9efee] bg-[#f6f9f8] px-3 py-2.5"
            >
                <span class="text-[11px] font-bold text-[#9bb0af] tabular-nums">{{ String(index + 1).padStart(2, '0') }}</span>
                <span class="font-mono text-[13px] tracking-wider text-[#10393b] select-all">{{ code }}</span>
            </li>
        </ul>

        <div class="mt-4 flex flex-wrap gap-2">
            <Button type="button" class="flex-1 max-[480px]:w-full" data-testid="mfa-download-codes" @click="download">
                <MdiDownload class="size-[18px] shrink-0" aria-hidden="true" />
                Codes herunterladen
            </Button>

            <button
                type="button"
                class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-full border border-[#d8e4e2] bg-white px-4 text-[12.5px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                data-testid="mfa-copy-codes"
                @click="copy"
            >
                <component :is="copied ? MdiCheck : MdiContentCopy" class="size-[18px] shrink-0" aria-hidden="true" />
                {{ copied ? 'Kopiert' : 'Alle kopieren' }}
            </button>

            <button
                type="button"
                class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-full border border-[#d8e4e2] bg-white px-4 text-[12.5px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                data-testid="mfa-print-codes"
                @click="print"
            >
                <MdiPrinter class="size-[18px] shrink-0" aria-hidden="true" />
                Drucken
            </button>
        </div>

        <label
            class="mt-4 flex cursor-pointer items-start gap-2.5 rounded-[13px] border border-[#e9efee] bg-white px-3 py-3 transition-colors hover:border-[#01b990] motion-reduce:transition-none"
        >
            <input
                v-model="saved"
                type="checkbox"
                class="mt-0.5 size-4 shrink-0 accent-[#01b990]"
                data-testid="mfa-codes-saved"
                :aria-describedby="downloaded ? 'mfa-download-hint' : undefined"
            />
            <span class="text-[12.5px] leading-[1.5] font-bold text-[#10393b]">
                Ich habe meine Notfallcodes gespeichert.
                <span v-if="downloaded" id="mfa-download-hint" class="block font-normal text-[#0b7a63]">
                    Datei heruntergeladen — bewahren Sie sie an einem sicheren Ort auf.
                </span>
            </span>
        </label>

        <Button type="button" class="mt-3 w-full" :disabled="!saved" data-testid="mfa-codes-continue" @click="emit('acknowledged')">
            Weiter zum Portal
        </Button>
    </div>
</template>

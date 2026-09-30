<script setup lang="ts">
/**
 * An Überführung's Übergabeprotokoll (transfer protocol). Saving it — as a
 * link OR as a PDF, one is enough — completes the relocation (traffic-light
 * spec, 30 September 2026). Once saved, the card shows what was saved.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { formatPortalDateTime } from '@/lib/portalDate';
import type { TransferProtocolData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MdiClipboardTextOutline from '~icons/mdi/clipboard-text-outline';

const props = defineProps<{
    orderId: string;
    protocol: TransferProtocolData | null;
    /** True once the relocation is scheduled and not yet completed. */
    editable: boolean;
}>();

const mode = ref<'link' | 'pdf'>('link');

const form = useForm<{ url: string; file: File | null }>({ url: '', file: null });

function onFile(event: Event) {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit() {
    if (!props.editable) {
        return;
    }

    form.transform((data) => (mode.value === 'link' ? { url: data.url } : { file: data.file })).post(
        route('admin.orders.transfer-protocol', props.orderId),
        { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() },
    );
}

const canSubmit = () => (mode.value === 'link' ? form.url.trim() !== '' : form.file !== null);
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#00856a]">
                <MdiClipboardTextOutline class="size-[17px]" />
            </span>
            <div>
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Übergabeprotokoll</h2>
                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">Mit dem Protokoll ist die Überführung abgeschlossen.</p>
            </div>
        </div>

        <!-- Saved -->
        <div v-if="protocol" class="flex flex-col gap-2 rounded-[13px] bg-[#f6f9f8] px-4 py-3">
            <p class="text-[12.5px] font-bold text-[#10393b]">
                {{ protocol.format === 'pdf' ? 'PDF gespeichert' : 'Link gespeichert' }}
                <span class="font-medium text-[#9bb0af]">· {{ formatPortalDateTime(protocol.saved_at) }}</span>
            </p>
            <a
                v-if="protocol.format === 'link' && protocol.url"
                :href="protocol.url"
                target="_blank"
                rel="noopener"
                class="truncate text-[12.5px] font-bold text-[#00856a] hover:underline"
            >
                {{ protocol.url }}
            </a>
            <a
                v-else-if="protocol.format === 'pdf' && protocol.file_url"
                :href="protocol.file_url"
                target="_blank"
                rel="noopener"
                class="truncate text-[12.5px] font-bold text-[#00856a] hover:underline"
            >
                {{ protocol.file_name || 'Übergabeprotokoll.pdf' }}
            </a>
            <p v-else class="text-[12px] text-[#6f8585]">{{ protocol.file_name || 'Übergabeprotokoll.pdf' }}</p>
        </div>

        <!-- Not yet possible -->
        <p v-else-if="!editable" class="rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            Das Übergabeprotokoll kann hinzugefügt werden, sobald die Überführung terminiert ist.
        </p>

        <!-- Entry -->
        <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
            <div class="inline-flex w-fit overflow-hidden rounded-full border border-[#e9efee]">
                <button
                    type="button"
                    class="px-4 py-1.5 text-[12px] font-bold"
                    :class="mode === 'link' ? 'bg-[#10393b] text-white' : 'text-[#6f8585]'"
                    @click="mode = 'link'"
                >
                    Link
                </button>
                <button
                    type="button"
                    class="px-4 py-1.5 text-[12px] font-bold"
                    :class="mode === 'pdf' ? 'bg-[#10393b] text-white' : 'text-[#6f8585]'"
                    @click="mode = 'pdf'"
                >
                    PDF hochladen
                </button>
            </div>

            <div v-if="mode === 'link'" class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Link zum Übergabeprotokoll</label>
                <Input v-model="form.url" type="url" placeholder="https://…" />
                <InputError :message="form.errors.url" />
            </div>

            <div v-else class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">PDF-Datei</label>
                <input type="file" accept="application/pdf,.pdf" class="text-[12.5px]" @change="onFile" />
                <InputError :message="form.errors.file" />
            </div>

            <button
                type="submit"
                :disabled="form.processing || !canSubmit()"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Speichert...' : 'Speichern und Überführung abschließen' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
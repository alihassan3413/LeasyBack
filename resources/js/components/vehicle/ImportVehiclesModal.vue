<script setup lang="ts">
/**
 * Bulk vehicle import (§5). Uploads an .xlsx or .csv and renders the
 * per-row outcome the server returns.
 *
 * The result panel is the point of the phase: §5 requires errors per row
 * without discarding valid rows, so a partial import must be shown as a
 * partial import — never summarised as a success.
 */
import InputError from '@/components/InputError.vue';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { SharedData } from '@/types';
import type { VehicleImportResult } from '@/types/vehicle';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const page = usePage<SharedData>();

const form = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);
const result = ref<VehicleImportResult | null>(null);

const fileName = computed(() => form.file?.name ?? '');

const hasRejections = computed(() => (result.value?.rejected ?? 0) > 0);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        result.value = null;

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    },
);

function onFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
    form.clearErrors('file');
}

function submit() {
    if (!form.file) {
        return;
    }

    form.post(route('vehicles.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            result.value = (page.props.flash?.vehicle_import as VehicleImportResult | null) ?? null;
            form.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function close() {
    emit('update:open', false);

    // The dashboard list is only refreshed once, on close, so the user reads
    // the per-row report before the page moves under them.
    if ((result.value?.imported ?? 0) > 0) {
        router.reload({ only: ['vehicles', 'pagination', 'analytics'] });
    }
}
</script>

<template>
    <AppModal
        :open="open"
        title="Fahrzeuge importieren"
        description="Laden Sie eine Excel-Datei (.xlsx) oder eine CSV-Datei hoch. Gültige Zeilen werden importiert, auch wenn einzelne Zeilen fehlerhaft sind."
        :width="820"
        @update:open="(value) => (value ? emit('update:open', true) : close())"
    >
        <div class="space-y-5 px-2">
            <div class="rounded-[12px] border border-gray-200 bg-[#f8fafa] p-4">
                <p class="text-sm font-medium text-[#10393b]">Spaltenvorlage</p>
                <p class="mt-1 text-xs text-[#6f8585]">
                    Die Datei muss eine Kopfzeile mit einer Spalte „Kennzeichen" enthalten. Alle weiteren Spalten sind optional.
                </p>
                <a
                    :href="route('vehicles.import.template')"
                    class="mt-3 inline-flex items-center gap-2 rounded-full border border-emerald-500 px-4 py-1.5 text-sm font-medium text-emerald-600 transition-colors hover:bg-emerald-50"
                >
                    Vorlage herunterladen
                </a>
            </div>

            <div>
                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-[12px] border-2 border-dashed border-gray-300 px-4 py-8 text-center transition-colors hover:border-emerald-400"
                >
                    <input ref="fileInput" type="file" accept=".xlsx,.csv" class="sr-only" @change="onFileChange" />
                    <span class="text-sm font-medium text-[#10393b]">
                        {{ fileName || 'Datei auswählen' }}
                    </span>
                    <span class="text-xs text-[#6f8585]">.xlsx oder .csv, maximal 5 MB</span>
                </label>
                <InputError :message="form.errors.file" class="mt-2" />
            </div>

            <div v-if="result" class="space-y-4">
                <div class="flex flex-wrap gap-3">
                    <div class="flex-1 rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <p class="text-2xl font-semibold text-emerald-700">{{ result.imported }}</p>
                        <p class="text-xs text-emerald-700">importiert</p>
                    </div>
                    <div
                        class="flex-1 rounded-[12px] border px-4 py-3"
                        :class="hasRejections ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-gray-50'"
                    >
                        <p class="text-2xl font-semibold" :class="hasRejections ? 'text-red-700' : 'text-gray-500'">
                            {{ result.rejected }}
                        </p>
                        <p class="text-xs" :class="hasRejections ? 'text-red-700' : 'text-gray-500'">abgelehnt</p>
                    </div>
                </div>

                <p v-if="result.truncated" class="rounded-[12px] bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    Die Datei enthält mehr Zeilen als verarbeitet werden können. Es wurden nur die ersten Zeilen importiert.
                </p>

                <p v-if="result.ignored_columns.length" class="rounded-[12px] bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    Nicht zugeordnete Spalten wurden ignoriert: {{ result.ignored_columns.join(', ') }}
                </p>

                <div v-if="hasRejections" class="overflow-hidden rounded-[12px] border border-gray-200">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs text-[#6f8585]">
                            <tr>
                                <th class="w-20 px-3 py-2 font-medium">Zeile</th>
                                <th class="w-40 px-3 py-2 font-medium">Kennzeichen</th>
                                <th class="px-3 py-2 font-medium">Fehler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="error in result.errors" :key="error.row" class="border-t border-gray-100">
                                <td class="px-3 py-2 align-top text-[#10393b]">{{ error.row }}</td>
                                <td class="px-3 py-2 align-top text-[#10393b]">{{ error.license_plate || '—' }}</td>
                                <td class="px-3 py-2 align-top text-red-700">
                                    <span v-for="(message, index) in error.messages" :key="index" class="block">
                                        {{ message }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <template #footer>
            <AppModalButton v-if="result" variant="secondary" @click="close">Schließen</AppModalButton>
            <AppModalButton :disabled="form.processing || !form.file" @click="submit">
                {{ form.processing ? 'Wird importiert...' : 'Import starten' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

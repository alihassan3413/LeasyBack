<script setup lang="ts">
/**
 * Upload a vehicle document, with a drag-and-drop drop zone and the
 * duplicate-type "replace" confirm flow ported from the legacy frontend.
 * The current backend allows multiple documents of the same type to coexist
 * silently (unlike the legacy backend, which rejected same-type re-uploads
 * outright) — so the duplicate check here is entirely client-side, using
 * the vehicle's already-loaded document list, not a server error.
 */
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import InputError from '@/components/InputError.vue';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { VehicleDocumentData } from '@/types/vehicle';
import { router, useForm } from '@inertiajs/vue3';
import { FileText, Upload } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    vehicleId: string;
    documents: VehicleDocumentData[];
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const documentTypeOptions: SelectFieldOption[] = [
    { label: 'Leasingvertrag', value: 'Leasingvertrag' },
    { label: 'Vorschaden', value: 'vorschaden' },
];

const isDraggingOver = ref(false);
const duplicateType = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

// Function form on purpose — see CreateOfferModal: an object literal would
// make reset() restore the last uploaded document instead of clearing.
const form = useForm<{ file: File | null; document_type: string }>(() => ({
    file: null,
    document_type: '',
}));

watch([() => form.file, () => form.document_type], () => {
    duplicateType.value = null;
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.reset();
        form.clearErrors();
        duplicateType.value = null;
    },
);

const existingDocumentOfSelectedType = computed(() => props.documents.filter((doc) => doc.document_type === form.document_type));

function pickFile() {
    fileInput.value?.click();
}

function onFileInputChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
}

function onDrop(event: DragEvent) {
    isDraggingOver.value = false;
    form.file = event.dataTransfer?.files?.[0] ?? null;
}

function close() {
    emit('update:open', false);
}

function performUpload() {
    form.post(route('vehicles.documents.store', props.vehicleId), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: close,
    });
}

function attemptUpload() {
    if (existingDocumentOfSelectedType.value.length > 0) {
        duplicateType.value = form.document_type;
        return;
    }
    performUpload();
}

function cancelReplace() {
    duplicateType.value = null;
}

function replaceExisting() {
    deleteSequentially([...existingDocumentOfSelectedType.value], () => {
        duplicateType.value = null;
        performUpload();
    });
}

function deleteSequentially(documents: VehicleDocumentData[], onDone: () => void) {
    const [next, ...rest] = documents;

    if (!next) {
        onDone();
        return;
    }

    router.delete(route('vehicles.documents.destroy', [props.vehicleId, next.document_id]), {
        preserveScroll: true,
        onSuccess: () => deleteSequentially(rest, onDone),
    });
}
</script>

<template>
    <AppModal
        :open="open"
        title="Dokument hochladen"
        description="PDF, JPG oder PNG · max. 10 MB"
        @update:open="(value) => emit('update:open', value)"
    >
        <form @submit.prevent="attemptUpload">
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 px-2">
                <div
                    class="relative flex h-[110px] w-full cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed transition-colors"
                    :class="isDraggingOver ? 'border-emerald-500 bg-emerald-50' : 'border-gray-300 bg-gray-50'"
                    @click="pickFile"
                    @dragover.prevent="isDraggingOver = true"
                    @dragleave.prevent="isDraggingOver = false"
                    @drop.prevent="onDrop"
                >
                    <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onFileInputChange" />
                    <FileText v-if="form.file" class="size-8 text-gray-400" aria-hidden="true" />
                    <Upload v-else class="size-8 text-gray-400" aria-hidden="true" />
                    <p class="mt-2 text-sm font-medium text-black">{{ form.file ? form.file.name : 'Datei hierher ziehen' }}</p>
                    <p class="text-xs font-light text-[#00000080]">{{ form.file ? 'Andere Datei wählen' : 'oder klicken zum Auswählen' }}</p>
                </div>
                <InputError :message="form.errors.file" />

                <FormField v-slot="{ id, describedBy, invalid }" label="Dokumententyp" required :error="form.errors.document_type">
                    <SelectField
                        :id="id"
                        v-model="form.document_type"
                        :options="documentTypeOptions"
                        placeholder="Typ wählen"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <div v-if="duplicateType" class="rounded-2xl bg-gray-50 p-4 text-sm">
                    <p class="text-[#00000080]">
                        Ein Dokument vom Typ „{{ duplicateType }}" existiert bereits für dieses Fahrzeug. Möchten Sie das vorhandene Dokument
                        ersetzen?
                    </p>
                    <div class="mt-3 flex justify-end gap-3">
                        <button type="button" class="text-sm font-semibold text-gray-500 hover:text-gray-700" @click="cancelReplace">
                            Abbrechen
                        </button>
                        <button type="button" class="text-sm font-semibold text-red-500 hover:text-red-600" @click="replaceExisting">Ersetzen</button>
                    </div>
                </div>

                <div v-if="documents.length > 0" class="space-y-1">
                    <p class="text-sm font-semibold text-black">Vorhandene Dokumente</p>
                    <ul class="space-y-1 text-sm">
                        <li v-for="doc in documents" :key="doc.document_id" class="flex items-center gap-2 text-[#00000080]">
                            <FileText class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                            <span>{{ doc.document_type }} — {{ doc.original_file_name }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="!form.file || !form.document_type || !!duplicateType || form.processing" @click="attemptUpload">
                {{ form.processing ? 'Lädt...' : 'Hochladen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

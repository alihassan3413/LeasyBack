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
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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

const form = useForm<{ file: File | null; document_type: string }>({
    file: null,
    document_type: '',
});

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
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="attemptUpload">
                <DialogHeader>
                    <DialogTitle>Dokument hochladen</DialogTitle>
                    <DialogDescription>PDF, JPG oder PNG · max. 10 MB</DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div
                        class="hover:border-primary/50 rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                        :class="isDraggingOver ? 'border-primary bg-accent' : 'border-input'"
                        @dragover.prevent="isDraggingOver = true"
                        @dragleave.prevent="isDraggingOver = false"
                        @drop.prevent="onDrop"
                    >
                        <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onFileInputChange" />
                        <FileText v-if="form.file" class="text-muted-foreground mx-auto size-8" aria-hidden="true" />
                        <Upload v-else class="text-muted-foreground mx-auto size-8" aria-hidden="true" />
                        <p class="mt-2 text-sm font-medium">{{ form.file ? form.file.name : 'Datei hierher ziehen' }}</p>
                        <Button type="button" variant="outline" size="sm" class="mt-3" @click="pickFile">
                            {{ form.file ? 'Andere Datei wählen' : 'Datei auswählen' }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.file" />

                    <FormField
                        id="document_type"
                        v-slot="{ id, describedBy, invalid }"
                        label="Dokumententyp"
                        required
                        :error="form.errors.document_type"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.document_type"
                            :options="documentTypeOptions"
                            placeholder="Typ wählen"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>

                    <div
                        v-if="duplicateType"
                        class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10"
                    >
                        <p class="text-amber-900 dark:text-amber-400">
                            Ein Dokument vom Typ „{{ duplicateType }}" existiert bereits für dieses Fahrzeug. Möchten Sie das vorhandene Dokument
                            ersetzen?
                        </p>
                        <div class="mt-3 flex justify-end gap-2">
                            <Button type="button" variant="ghost" size="sm" @click="cancelReplace">Abbrechen</Button>
                            <Button type="button" variant="destructive" size="sm" @click="replaceExisting">Ersetzen</Button>
                        </div>
                    </div>

                    <div v-if="documents.length > 0" class="space-y-1">
                        <p class="text-muted-foreground text-sm">Vorhandene Dokumente</p>
                        <ul class="space-y-1 text-sm">
                            <li v-for="doc in documents" :key="doc.document_id" class="flex items-center gap-2">
                                <FileText class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                <span>{{ doc.document_type }} — {{ doc.original_file_name }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">Abbrechen</Button>
                    <Button type="submit" :disabled="!form.file || !form.document_type || !!duplicateType" :loading="form.processing">
                        Hochladen
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

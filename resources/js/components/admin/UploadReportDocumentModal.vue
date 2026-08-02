<script setup lang="ts">
/**
 * Upload a report/invoice document for one of a vehicle's orders. Simpler
 * than the customer-facing UploadDocumentModal.vue (no duplicate-type
 * "replace" flow — the backend already rejects an exact filename repeat
 * for the same vehicle+auftragsnummer with a clean 409, surfaced here as a
 * normal form error).
 */
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps<{
    open: boolean;
    vehicleId: string;
    auftragsnummerOptions: SelectFieldOption[];
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const form = useForm<{
    auftragsnummer: string;
    document_type: string;
    document_title: string;
    published: boolean;
    file: File | null;
}>({
    auftragsnummer: '',
    document_type: '',
    document_title: '',
    published: false,
    file: null,
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.reset();
        form.clearErrors();
    },
);

function close() {
    emit('update:open', false);
}

function onFileChange(event: Event) {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit() {
    form.post(route('admin.vehicles.reports.upload', props.vehicleId), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal
        :open="open"
        title="Report-Dokument hochladen"
        description="Gutachten oder Rechnung für einen Auftrag hochladen."
        @update:open="(value) => emit('update:open', value)"
    >
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 px-2">
                <FormField id="auftragsnummer" v-slot="{ id, describedBy, invalid }" label="Auftrag" required :error="form.errors.auftragsnummer">
                    <SelectField
                        :id="id"
                        v-model="form.auftragsnummer"
                        :options="auftragsnummerOptions"
                        placeholder="Auftrag wählen"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <div class="grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
                    <FormField id="document_type" v-slot="{ id, describedBy, invalid }" label="Dokumententyp" :error="form.errors.document_type">
                        <Input
                            :id="id"
                            v-model="form.document_type"
                            placeholder="z. B. Gutachten, Rechnung"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField id="document_title" v-slot="{ id, describedBy, invalid }" label="Titel" :error="form.errors.document_title">
                        <Input :id="id" v-model="form.document_title" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>
                </div>

                <div class="grid gap-1">
                    <Label for="file" class="text-sm font-semibold text-black">Datei</Label>
                    <input id="file" type="file" class="text-sm" @change="onFileChange" />
                    <p v-if="form.errors.file" class="text-xs text-red-500">{{ form.errors.file }}</p>
                </div>

                <Label for="published" class="flex items-center space-x-2 text-sm font-normal text-black">
                    <Checkbox id="published" v-model="form.published" />
                    <span>Sofort veröffentlichen (für den Kunden sichtbar)</span>
                </Label>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="!form.auftragsnummer || !form.file || form.processing" @click="submit">
                {{ form.processing ? 'Lädt...' : 'Hochladen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

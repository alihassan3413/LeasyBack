<script setup lang="ts">
import UploadDocumentDropzone from '@/components/admin/UploadDocumentDropzone.vue';
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { INVOICE_DOCUMENT_TYPE, REPORT_DOCUMENT_TYPES, labelForDocumentType } from '@/lib/documentTypes';
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        vehicleId: string;
        auftragsnummerOptions: SelectFieldOption[];
        defaultAuftragsnummer?: string;
        defaultDocumentType?: string;
        title?: string;
        description?: string;
    }>(),
    {
        defaultAuftragsnummer: '',
        defaultDocumentType: 'gutachten',
        title: '',
        description: '',
    },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const uid = useId();
const publishedId = `${uid}-published`;

const isInvoice = computed(() => (props.defaultDocumentType || 'gutachten') === INVOICE_DOCUMENT_TYPE);
const defaultType = computed(() => props.defaultDocumentType || 'gutachten');

const heading = computed(() => props.title || (isInvoice.value ? 'Rechnung hochladen' : 'Gutachten hochladen'));
const subheading = computed(
    () => props.description || 'Laden Sie ein neues Dokument hoch – ziehen Sie die Datei auf die Fläche oder wählen Sie sie aus.',
);

/**
 * Function form on purpose: useForm() adopts the submitted values as its new
 * defaults on success, so with an object literal `form.reset()` below would
 * restore the *previous* upload — including the File object, leaving the last
 * document still attached when the modal is reopened.
 */
const form = useForm<{
    auftragsnummer: string;
    document_type: string;
    document_title: string;
    published: boolean;
    file: File | null;
}>(() => ({
    auftragsnummer: '',
    document_type: 'gutachten',
    document_title: 'Gutachten',
    // Uploads start as drafts, for reports and invoices alike — the v1 admin
    // modals both post published=false and leave releasing it to a separate,
    // deliberate step. Publishing is what notifies the customer, so defaulting
    // this on would silently push every upload (including a mistaken one)
    // straight to them.
    published: false,
    file: null,
}));

watch(
    () => form.document_type,
    (type) => (form.document_title = labelForDocumentType(type)),
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        form.document_type = defaultType.value;
        form.document_title = labelForDocumentType(defaultType.value);

        form.auftragsnummer = props.defaultAuftragsnummer || (props.auftragsnummerOptions.length === 1 ? props.auftragsnummerOptions[0].value : '');
    },
);

function close() {
    emit('update:open', false);
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
    <AppModal :open="open" :title="heading" :description="subheading" @update:open="(value) => emit('update:open', value)">
        <form id="admin-upload-report-form" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 px-2">
                <FormField v-slot="{ id, describedBy, invalid }" label="Auftrag" required :error="form.errors.auftragsnummer">
                    <SelectField
                        :id="id"
                        v-model="form.auftragsnummer"
                        :options="auftragsnummerOptions"
                        placeholder="Auftrag wählen"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <FormField v-slot="{ id, describedBy, invalid }" label="Dokumententyp" required :error="form.errors.document_type">
                    <SelectField
                        v-if="!isInvoice"
                        :id="id"
                        v-model="form.document_type"
                        :options="REPORT_DOCUMENT_TYPES"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                    <div
                        v-else
                        class="border-input flex h-10 w-full items-center rounded-full border bg-gray-100 px-4 text-sm font-medium text-gray-700"
                    >
                        Rechnung
                    </div>
                </FormField>

                <div class="flex flex-col gap-1">
                    <span class="text-sm font-semibold text-black">{{ isInvoice ? 'Rechnung' : 'Gutachten' }}</span>
                    <UploadDocumentDropzone v-model="form.file" />
                    <p v-if="form.errors.file" class="text-xs text-red-500">{{ form.errors.file }}</p>
                </div>

                <div class="rounded-2xl bg-gray-50 p-4">
                    <Label :for="publishedId" class="flex cursor-pointer items-start gap-2 font-normal">
                        <Checkbox
                            :id="publishedId"
                            v-model="form.published"
                            class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                        />
                        <span class="text-xs leading-[1.45] text-[#00000099]">
                            <span class="font-bold text-black">Sofort für den Kunden freigeben</span><br />
                            Standardmäßig wird das Dokument als Entwurf gespeichert und ist im Kundenkonto nicht sichtbar. Mit der Freigabe – jetzt
                            oder später – wird der Kunde benachrichtigt.
                        </span>
                    </Label>
                </div>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="!form.auftragsnummer || !form.file || form.processing" @click="submit">
                {{ form.processing ? 'Lädt...' : 'Hochladen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

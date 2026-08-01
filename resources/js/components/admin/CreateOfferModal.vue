<script setup lang="ts">
/**
 * Admin "create a draft offer for this order" — mirrors
 * UploadReportDocumentModal.vue's form/dialog pattern. Publishing/cancelling
 * an existing offer is a row action on AdminOffersCard, not part of this
 * modal.
 */
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

interface OfferFormFields {
    repair_cost_net: string;
    repair_cost_gross: string;
    depreciation_value_net: string;
    depreciation_value_gross: string;
    workshop_repair_quote_net: string;
    workshop_repair_quote_gross: string;
    missing_parts_cost_net: string;
    missing_parts_cost_gross: string;
    additional_notes: string;
}

const props = defineProps<{ open: boolean; orderId: string }>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const form = useForm<OfferFormFields>({
    repair_cost_net: '',
    repair_cost_gross: '',
    depreciation_value_net: '',
    depreciation_value_gross: '',
    workshop_repair_quote_net: '',
    workshop_repair_quote_gross: '',
    missing_parts_cost_net: '',
    missing_parts_cost_gross: '',
    additional_notes: '',
});

type CostField = Exclude<keyof OfferFormFields, 'additional_notes'>;

const costFields: { key: CostField; label: string }[] = [
    { key: 'repair_cost_net', label: 'Reparaturkosten (netto)' },
    { key: 'repair_cost_gross', label: 'Reparaturkosten (brutto)' },
    { key: 'depreciation_value_net', label: 'Wertminderung (netto)' },
    { key: 'depreciation_value_gross', label: 'Wertminderung (brutto)' },
    { key: 'workshop_repair_quote_net', label: 'Werkstattkosten (netto)' },
    { key: 'workshop_repair_quote_gross', label: 'Werkstattkosten (brutto)' },
    { key: 'missing_parts_cost_net', label: 'Fehlteile (netto)' },
    { key: 'missing_parts_cost_gross', label: 'Fehlteile (brutto)' },
];

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

function submit() {
    form.post(route('admin.orders.offers.store', props.orderId), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Angebot erstellen</DialogTitle>
                    <DialogDescription>Neues Entwurfs-Angebot für diesen Auftrag.</DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid grid-cols-2 gap-4">
                        <FormField
                            v-for="field in costFields"
                            :id="field.key"
                            :key="field.key"
                            v-slot="{ id, describedBy, invalid }"
                            :label="field.label"
                            required
                            :error="form.errors[field.key]"
                        >
                            <Input
                                :id="id"
                                v-model="form[field.key]"
                                type="number"
                                step="0.01"
                                min="0"
                                :aria-invalid="invalid"
                                :aria-describedby="describedBy"
                            />
                        </FormField>
                    </div>

                    <FormField
                        id="additional_notes"
                        v-slot="{ id, describedBy, invalid }"
                        label="Anmerkungen"
                        :error="form.errors.additional_notes"
                    >
                        <Input :id="id" v-model="form.additional_notes" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">Abbrechen</Button>
                    <Button type="submit" :loading="form.processing"> Erstellen </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

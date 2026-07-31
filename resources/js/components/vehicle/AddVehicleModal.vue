<script setup lang="ts">
/**
 * Create or edit a vehicle. Reuses the same reusable form components as the
 * Profile pages (FormField, SelectField, LicensePlateInput) — nothing here
 * is a native <select> or a one-off dropdown.
 */
import FormField from '@/components/form/FormField.vue';
import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import SelectField from '@/components/form/SelectField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { VEHICLE_BRAND_OPTIONS } from '@/lib/vehicleBrands';
import type { VehicleData } from '@/types/vehicle';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    vehicle?: VehicleData | null;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const isEditMode = computed(() => !!props.vehicle);

const leasingEndUnknown = ref(false);
const leasinggeberUnknown = ref(false);

const form = useForm({
    license_plate: '',
    make: '',
    model: '',
    vin: '',
    leasing_end_date: '',
    leasinggeber: '',
});

// Re-seed every time the modal opens, from the (possibly changed) vehicle prop.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.license_plate = props.vehicle?.license_plate ?? '';
        form.make = props.vehicle?.make ?? '';
        form.model = props.vehicle?.model ?? '';
        form.vin = props.vehicle?.vin ?? '';
        form.leasing_end_date = props.vehicle?.leasing_end_date ?? '';
        form.leasinggeber = props.vehicle?.leasinggeber ?? '';
        leasingEndUnknown.value = !props.vehicle?.leasing_end_date;
        leasinggeberUnknown.value = !props.vehicle?.leasinggeber;
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    form.transform(() => ({
        license_plate: form.license_plate,
        make: form.make || null,
        model: form.model || null,
        vin: form.vin || null,
        leasing_end_date: leasingEndUnknown.value ? null : form.leasing_end_date || null,
        leasinggeber: leasinggeberUnknown.value ? null : form.leasinggeber || null,
    }));

    const options = { preserveScroll: true, onSuccess: close };

    if (isEditMode.value && props.vehicle) {
        form.patch(route('vehicles.update', props.vehicle.vehicle_id), options);
    } else {
        form.post(route('vehicles.store'), options);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogScrollContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ isEditMode ? 'Fahrzeug bearbeiten' : 'Neues Fahrzeug anlegen' }}</DialogTitle>
                    <DialogDescription>
                        {{
                            isEditMode
                                ? 'Aktualisieren Sie die Daten Ihres Fahrzeugs.'
                                : 'Erfassen Sie Ihr Fahrzeug, um mit einer Bewertung zu starten.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <InputError :message="form.errors.vehicle" />

                    <FormField label="Kennzeichen" required :error="form.errors.license_plate">
                        <LicensePlateInput v-model="form.license_plate" :disabled="isEditMode" />
                    </FormField>

                    <FormField id="make" v-slot="{ id, describedBy, invalid }" label="Marke" required :error="form.errors.make">
                        <SelectField
                            :id="id"
                            v-model="form.make"
                            :options="VEHICLE_BRAND_OPTIONS"
                            placeholder="Marke wählen"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>

                    <FormField id="model" v-slot="{ id, describedBy, invalid }" label="Modell" :error="form.errors.model">
                        <Input :id="id" v-model="form.model" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>

                    <FormField id="vin" v-slot="{ id, describedBy, invalid }" label="Fahrgestellnummer (VIN)" required hint="Genau 17 Zeichen." :error="form.errors.vin">
                        <Input :id="id" v-model="form.vin" maxlength="17" class="uppercase" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>

                    <div class="space-y-2">
                        <FormField
                            id="leasing_end_date"
                            v-slot="{ id, describedBy, invalid }"
                            label="Leasingende"
                            :error="form.errors.leasing_end_date"
                        >
                            <Input
                                :id="id"
                                v-model="form.leasing_end_date"
                                type="date"
                                :disabled="leasingEndUnknown"
                                :aria-invalid="invalid"
                                :aria-describedby="describedBy"
                            />
                        </FormField>
                        <Label for="leasing_end_unknown" class="flex items-center space-x-2 text-sm font-normal">
                            <Checkbox id="leasing_end_unknown" v-model:checked="leasingEndUnknown" />
                            <span>Ich weiß es nicht</span>
                        </Label>
                    </div>

                    <div class="space-y-2">
                        <FormField id="leasinggeber" v-slot="{ id, describedBy, invalid }" label="Leasinggeber" :error="form.errors.leasinggeber">
                            <Input
                                :id="id"
                                v-model="form.leasinggeber"
                                :disabled="leasinggeberUnknown"
                                :aria-invalid="invalid"
                                :aria-describedby="describedBy"
                            />
                        </FormField>
                        <Label for="leasinggeber_unknown" class="flex items-center space-x-2 text-sm font-normal">
                            <Checkbox id="leasinggeber_unknown" v-model:checked="leasinggeberUnknown" />
                            <span>Ich weiß es nicht</span>
                        </Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">Abbrechen</Button>
                    <Button type="submit" :loading="form.processing">{{ isEditMode ? 'Speichern' : 'Fahrzeug anlegen' }}</Button>
                </DialogFooter>
            </form>
        </DialogScrollContent>
    </Dialog>
</template>

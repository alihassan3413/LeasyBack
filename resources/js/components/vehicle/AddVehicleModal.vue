<script setup lang="ts">
/**
 * Create or edit a vehicle. Reuses the same reusable form components as the
 * Profile pages (FormField, SearchableSelectField, LicensePlateInput) — nothing here
 * is a native <select> or a one-off dropdown.
 */
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import FormField from '@/components/form/FormField.vue';
import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import SearchableSelectField from '@/components/form/SearchableSelectField.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { VEHICLE_BRAND_OPTIONS } from '@/lib/vehicleBrands';
import type { VehicleData } from '@/types/vehicle';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';

const uid = useId();
const leasingEndUnknownId = `${uid}-leasing-end-unknown`;
const leasinggeberUnknownId = `${uid}-leasinggeber-unknown`;

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
        leasingEndUnknown.value = false;
        leasinggeberUnknown.value = false;
    },
);

watch(leasingEndUnknown, (unknown) => {
    if (unknown) {
        form.leasing_end_date = '';
    }
});

watch(leasinggeberUnknown, (unknown) => {
    if (unknown) {
        form.leasinggeber = '';
    }
});

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
    <AppModal
        :open="open"
        :title="isEditMode ? 'Fahrzeug bearbeiten' : 'Neues Fahrzeug anlegen'"
        :description="
            isEditMode ? 'Aktualisieren Sie die Daten Ihres Fahrzeugs.' : 'Erfassen Sie Ihr Fahrzeug, um mit einer Bewertung zu starten.'
        "
        @update:open="(value) => emit('update:open', value)"
    >
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 px-2 md:grid-cols-2">
                <InputError class="md:col-span-2" :message="form.errors.vehicle" />

                <LicensePlateInput v-model="form.license_plate" :disabled="isEditMode" :server-error="form.errors.license_plate" />

                <FormField
                    v-slot="{ id, describedBy, invalid }"
                    label="FIN"
                    label-hint="* (siehe Fahrzeugschein – Feld E)"
                    :error="form.errors.vin"
                >
                    <Input
                        :id="id"
                        v-model="form.vin"
                        maxlength="17"
                        placeholder="FIN eingeben"
                        class="uppercase"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField v-slot="{ id, describedBy, invalid }" label="Marke" required :error="form.errors.make">
                    <SearchableSelectField
                        :id="id"
                        v-model="form.make"
                        :options="VEHICLE_BRAND_OPTIONS"
                        placeholder="Marke wählen"
                        search-placeholder="Marke suchen..."
                        empty-label="Keine Marke gefunden"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <FormField v-slot="{ id, describedBy, invalid }" label="Modell" :error="form.errors.model">
                    <Input :id="id" v-model="form.model" placeholder="Modell eingeben" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <div>
                    <FormField v-slot="{ id, describedBy, invalid }" label="Leasingende" :error="form.errors.leasing_end_date">
                        <CalendarDateField
                            :id="id"
                            v-model="form.leasing_end_date"
                            allow-past
                            :disabled="leasingEndUnknown"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>
                    <Label :for="leasingEndUnknownId" class="mt-1.5 flex cursor-pointer items-start gap-2 font-normal">
                        <Checkbox
                            :id="leasingEndUnknownId"
                            v-model="leasingEndUnknown"
                            class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                        />
                        <span class="text-xs leading-[1.45] font-normal text-[#00000099]">
                            Das genaue Datum des Leasingendes liegt mir aktuell nicht vor. Ich werde Ihnen diese Information zeitnah nachreichen.
                        </span>
                    </Label>
                </div>

                <div>
                    <FormField v-slot="{ id, describedBy, invalid }" label="Leasinggeber" label-hint="*" :error="form.errors.leasinggeber">
                        <Input
                            :id="id"
                            v-model="form.leasinggeber"
                            placeholder="Leasinggeber eingeben"
                            :disabled="leasinggeberUnknown"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                    <Label :for="leasinggeberUnknownId" class="mt-1.5 flex cursor-pointer items-start gap-2 font-normal">
                        <Checkbox
                            :id="leasinggeberUnknownId"
                            v-model="leasinggeberUnknown"
                            class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                        />
                        <span class="text-xs leading-[1.45] font-normal text-[#00000099]">
                            Der Name des Leasinggebers liegt mir aktuell nicht vor. Ich werde Ihnen diese Information zeitnah nachreichen.
                        </span>
                    </Label>
                </div>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Wird gespeichert...' : isEditMode ? 'Speichern' : 'Fahrzeug anlegen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

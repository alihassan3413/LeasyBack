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
import { useB2bPermissions } from '@/composables/useB2bPermissions';
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

const { isCompanyUser, can } = useB2bPermissions();

const showFleetFields = computed(() => {
    if (!isCompanyUser.value) {
        return false;
    }

    if (isEditMode.value) {
        return props.vehicle?.vehicle_belongs === 'B2B' && can('vehicles.update');
    }

    return can('vehicles.create');
});

const leasingEndUnknown = ref(false);
const leasinggeberUnknown = ref(false);

const emptyCollectionAddress = () => ({
    street: '',
    number: '',
    additional_address: '',
    zip_code: '',
    city: '',
    country: '',
});

const form = useForm({
    license_plate: '',
    make: '',
    model: '',
    vin: '',
    leasing_end_date: '',
    leasinggeber: '',
    mileage: '',
    contract_number: '',
    cost_centre: '',
    driver_name: '',
    driver_contact: '',
    collection_address: emptyCollectionAddress(),
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
        form.mileage = props.vehicle?.mileage != null ? String(props.vehicle.mileage) : '';
        form.contract_number = props.vehicle?.contract_number ?? '';
        form.cost_centre = props.vehicle?.cost_centre ?? '';
        form.driver_name = props.vehicle?.driver_name ?? '';
        form.driver_contact = props.vehicle?.driver_contact ?? '';
        form.collection_address = {
            street: props.vehicle?.collection_address?.street ?? '',
            number: props.vehicle?.collection_address?.number ?? '',
            additional_address: props.vehicle?.collection_address?.additional_address ?? '',
            zip_code: props.vehicle?.collection_address?.zip_code ?? '',
            city: props.vehicle?.collection_address?.city ?? '',
            country: props.vehicle?.collection_address?.country ?? '',
        };
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
        ...(showFleetFields.value
            ? {
                  mileage: form.mileage === '' ? null : Number(form.mileage),
                  contract_number: form.contract_number || null,
                  cost_centre: form.cost_centre || null,
                  driver_name: form.driver_name || null,
                  driver_contact: form.driver_contact || null,
                  collection_address: form.collection_address,
              }
            : {}),
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

                <template v-if="showFleetFields">
                    <div class="mt-2 flex items-center gap-3 md:col-span-2">
                        <span class="text-[10.5px] font-bold tracking-[0.16em] text-[#9CB3B4] uppercase">Flottendaten</span>
                        <span class="h-px flex-1 bg-[#EDF2F2]" />
                    </div>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Kilometerstand" :error="form.errors.mileage">
                        <Input
                            :id="id"
                            v-model="form.mileage"
                            type="number"
                            min="0"
                            placeholder="z. B. 45000"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Vertragsnummer" :error="form.errors.contract_number">
                        <Input
                            :id="id"
                            v-model="form.contract_number"
                            maxlength="100"
                            placeholder="Vertragsnummer eingeben"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Kostenstelle" :error="form.errors.cost_centre">
                        <Input
                            :id="id"
                            v-model="form.cost_centre"
                            maxlength="100"
                            placeholder="Kostenstelle eingeben"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Fahrer / Ansprechpartner" :error="form.errors.driver_name">
                        <Input
                            :id="id"
                            v-model="form.driver_name"
                            maxlength="255"
                            placeholder="Name eingeben"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ id, describedBy, invalid }"
                        label="Kontakt des Fahrers"
                        label-hint="(E-Mail oder Telefon)"
                        :error="form.errors.driver_contact"
                        class="md:col-span-2"
                    >
                        <Input
                            :id="id"
                            v-model="form.driver_contact"
                            maxlength="255"
                            placeholder="E-Mail oder Telefonnummer"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <div class="mt-2 flex items-center gap-3 md:col-span-2">
                        <span class="text-[10.5px] font-bold tracking-[0.16em] text-[#9CB3B4] uppercase">Abholadresse</span>
                        <span class="h-px flex-1 bg-[#EDF2F2]" />
                    </div>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Straße" :error="form.errors['collection_address.street']">
                        <Input
                            :id="id"
                            v-model="form.collection_address.street"
                            maxlength="255"
                            placeholder="Straße eingeben"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Hausnummer" :error="form.errors['collection_address.number']">
                        <Input
                            :id="id"
                            v-model="form.collection_address.number"
                            maxlength="50"
                            placeholder="Nr."
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ id, describedBy, invalid }"
                        label="Adresszusatz"
                        :error="form.errors['collection_address.additional_address']"
                        class="md:col-span-2"
                    >
                        <Input
                            :id="id"
                            v-model="form.collection_address.additional_address"
                            maxlength="255"
                            placeholder="Halle, Tor, Etage …"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField v-slot="{ id, describedBy, invalid }" label="PLZ" :error="form.errors['collection_address.zip_code']">
                        <Input
                            :id="id"
                            v-model="form.collection_address.zip_code"
                            maxlength="20"
                            placeholder="PLZ"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField v-slot="{ id, describedBy, invalid }" label="Ort" :error="form.errors['collection_address.city']">
                        <Input
                            :id="id"
                            v-model="form.collection_address.city"
                            maxlength="100"
                            placeholder="Ort"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ id, describedBy, invalid }"
                        label="Land"
                        :error="form.errors['collection_address.country']"
                        class="md:col-span-2"
                    >
                        <Input
                            :id="id"
                            v-model="form.collection_address.country"
                            maxlength="100"
                            placeholder="Deutschland"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </template>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Wird gespeichert...' : isEditMode ? 'Speichern' : 'Fahrzeug anlegen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import FormField from '@/components/form/FormField.vue';
import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import SearchableSelectField from '@/components/form/SearchableSelectField.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { VEHICLE_BRAND_OPTIONS } from '@/lib/vehicleBrands';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';

export interface OnboardingVehicle {
    vehicle_id: string;
    license_plate: string;
    make: string | null;
    model: string | null;
    vin: string | null;
    leasing_end_date: string | null;
    leasinggeber: string | null;
}

const props = defineProps<{ vehicle: OnboardingVehicle | null }>();
const emit = defineEmits<{ next: []; back: [] }>();

const isEditMode = computed(() => !!props.vehicle);

const uid = useId();
const leasingEndUnknownId = `${uid}-leasing-end-unknown`;
const leasinggeberUnknownId = `${uid}-leasinggeber-unknown`;

// Matches the leasyback_web design: green "Weiter" (continue), orange "Zurück" (back).
const nextButtonClass = 'rounded-[5px] px-10 py-2 text-sm font-bold text-white shadow-none bg-brand-green hover:bg-brand-green/90';
const backButtonClass = 'rounded-[5px] px-10 py-2 text-sm font-bold text-white shadow-none bg-brand-orange hover:bg-brand-orange/90';

const fieldClass = 'text-sm';

const leasingEndUnknown = ref(false);
const leasinggeberUnknown = ref(false);

const VIN_MAX_LENGTH = 17;

function sanitizeVin(value: string | number): string {
    return String(value)
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, VIN_MAX_LENGTH);
}

const form = useForm({
    license_plate: '',
    leasing_end_date: '',
    leasinggeber: '',
    vin: '',
    make: '',
    model: '',
});

// Seeded from whatever is already saved so the step stays editable when the
// user navigates back through the wizard.
watch(
    () => props.vehicle,
    (vehicle) => {
        form.license_plate = vehicle?.license_plate ?? '';
        form.leasing_end_date = vehicle?.leasing_end_date ?? '';
        form.leasinggeber = vehicle?.leasinggeber ?? '';
        form.vin = vehicle?.vin ?? '';
        form.make = vehicle?.make ?? '';
        form.model = vehicle?.model ?? '';
        leasingEndUnknown.value = false;
        leasinggeberUnknown.value = false;
    },
    { immediate: true, deep: true },
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

function submit() {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('next') };

    // The plate is immutable once the vehicle exists, so it's dropped from the
    // update payload entirely (UpdateVehicleRequest doesn't accept it).
    form.transform(({ license_plate, ...data }) => ({
        ...(isEditMode.value ? {} : { license_plate }),
        ...data,
        leasing_end_date: leasingEndUnknown.value ? null : data.leasing_end_date || null,
        leasinggeber: leasinggeberUnknown.value ? null : data.leasinggeber || null,
        vin: data.vin || null,
        model: data.model || null,
    }));

    if (isEditMode.value) {
        form.patch(route('onboarding.vehicle.update'), options);

        return;
    }

    form.post(route('onboarding.vehicle.store'), options);
}
</script>

<template>
    <OnboardingCard title="Fahrzeugdaten" description="Erfassen Sie das Fahrzeug, das Sie zurückgeben möchten.">
        <form novalidate @submit.prevent="submit">
            <InputError :message="form.errors.vehicle" />

            <div class="grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
                <LicensePlateInput v-model="form.license_plate" :disabled="isEditMode" :server-error="form.errors.license_plate" />

                <FormField v-slot="{ id, describedBy, invalid }" label="FIN" label-hint="* (siehe Fahrzeugschein – Feld E)" :error="form.errors.vin">
                    <Input
                        :id="id"
                        :model-value="form.vin"
                        maxlength="17"
                        placeholder="FIN eingeben"
                        :class="[fieldClass, 'uppercase']"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                        @update:model-value="(value) => (form.vin = sanitizeVin(value))"
                    />
                </FormField>

                <FormField v-slot="{ id, describedBy, invalid }" label="Marke" :error="form.errors.make">
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
                    <Input
                        :id="id"
                        v-model="form.model"
                        placeholder="Modell eingeben"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div>
                    <CalendarDateField
                        v-model="form.leasing_end_date"
                        label="Leasingende"
                        allow-past
                        :disabled="leasingEndUnknown"
                        :error="form.errors.leasing_end_date"
                    />
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
                            :class="fieldClass"
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

            <div class="flex justify-end gap-3 pt-6">
                <Button type="button" :class="backButtonClass" @click="emit('back')">Zurück</Button>
                <Button type="submit" :class="nextButtonClass" :loading="form.processing">Weiter</Button>
            </div>
        </form>
    </OnboardingCard>
</template>

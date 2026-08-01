<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FormField from '@/components/form/FormField.vue';
import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import SelectField from '@/components/form/SelectField.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { VEHICLE_BRAND_OPTIONS } from '@/lib/vehicleBrands';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface OnboardingVehicle {
    vehicle_id: string;
    license_plate: string;
    make: string | null;
    model: string | null;
}

defineProps<{ vehicle: OnboardingVehicle | null }>();
const emit = defineEmits<{ next: []; back: [] }>();

// Matches the leasyback_web design: green "Weiter" (continue), orange "Zurück" (back).
const nextButtonClass = 'rounded-[5px] px-10 py-2 text-sm font-bold text-white shadow-none bg-brand-green hover:bg-brand-green/90';
const backButtonClass = 'rounded-[5px] px-10 py-2 text-sm font-bold text-white shadow-none bg-brand-orange hover:bg-brand-orange/90';

const leasingEndUnknown = ref(true);

const VIN_MAX_LENGTH = 17;

function sanitizeVin(value: string | number): string {
    return String(value)
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, VIN_MAX_LENGTH);
}

const form = useForm({
    license_plate: '',
    first_registration_date: '',
    leasing_end_date: '',
    vin: '',
    make: '',
    model: '',
});

function submit() {
    form.transform((data) => ({
        ...data,
        first_registration_date: data.first_registration_date || null,
        leasing_end_date: leasingEndUnknown.value ? null : data.leasing_end_date || null,
        vin: data.vin || null,
        model: data.model || null,
    })).post(route('onboarding.vehicle.store'), {
        preserveScroll: true,
        onSuccess: () => emit('next'),
    });
}
</script>

<template>
    <OnboardingCard title="Fahrzeugdaten" description="Erfassen Sie das Fahrzeug, das Sie zurückgeben möchten.">
        <template v-if="vehicle">
            <dl class="text-sm">
                <dt class="text-muted-foreground">Fahrzeug</dt>
                <dd class="font-medium">
                    {{ vehicle.license_plate }} — {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Ohne Marke/Modell' }}
                </dd>
            </dl>

            <div class="mt-6 flex items-center justify-between border-t pt-5">
                <Button type="button" :class="backButtonClass" @click="emit('back')">Zurück</Button>
                <Button type="button" :class="nextButtonClass" @click="emit('next')">Weiter</Button>
            </div>
        </template>

        <form v-else novalidate class="space-y-5" @submit.prevent="submit">
            <InputError :message="form.errors.vehicle" />

            <FormField label="Kennzeichen" required :error="form.errors.license_plate">
                <LicensePlateInput v-model="form.license_plate" />
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    id="first_registration_date"
                    v-slot="{ id, describedBy, invalid }"
                    label="Erstzulassungsdatum"
                    hint="Siehe Fahrzeugschein."
                    :error="form.errors.first_registration_date"
                >
                    <Input :id="id" v-model="form.first_registration_date" type="date" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <div class="space-y-2">
                    <FormField
                        id="leasing_end_date"
                        v-slot="{ id, describedBy, invalid }"
                        label="Leasingende"
                        hint="Siehe Leasingvertrag."
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
            </div>

            <FormField
                id="vin"
                v-slot="{ id, describedBy, invalid }"
                label="Fahrgestellnummer (VIN)"
                hint="Genau 17 Zeichen, siehe Feld E."
                :error="form.errors.vin"
            >
                <Input
                    :id="id"
                    :model-value="form.vin"
                    maxlength="17"
                    class="uppercase"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                    @update:model-value="(value) => (form.vin = sanitizeVin(value))"
                />
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField id="make" v-slot="{ id, describedBy, invalid }" label="Marke" :error="form.errors.make">
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
                    <Input
                        :id="id"
                        v-model="form.model"
                        placeholder="z. B. 3er oder Sonstige"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>
            </div>

            <div class="flex items-center justify-between gap-3 border-t pt-5">
                <Button type="button" :class="backButtonClass" @click="emit('back')">Zurück</Button>
                <Button type="submit" :class="nextButtonClass" :loading="form.processing">Weiter</Button>
            </div>
        </form>
    </OnboardingCard>
</template>

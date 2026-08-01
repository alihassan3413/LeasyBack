<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import CalendarDateField from '@/components/form/CalendarDateField.vue';
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

const fieldClass = 'rounded-[5px] border-brand-green-gray text-sm';
const selectTriggerClass = 'h-[34px] rounded-[5px] border-brand-green-gray bg-white px-3 text-sm shadow-none focus:border-brand-green focus:ring-0';

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

            <div class="mt-6 flex justify-end gap-3">
                <Button type="button" :class="backButtonClass" @click="emit('back')">Zurück</Button>
                <Button type="button" :class="nextButtonClass" @click="emit('next')">Weiter</Button>
            </div>
        </template>

        <form v-else novalidate @submit.prevent="submit">
            <InputError :message="form.errors.vehicle" />

            <div class="max-w-85 space-y-2">
                <div>
                    <p class="text-brand-black mb-1.5 text-sm font-bold">
                        Kennzeichen
                        <span class="text-brand-green-gray ml-1 text-[10px] font-normal">*(Format: ABC DE 12H)</span>
                    </p>
                    <LicensePlateInput v-model="form.license_plate" variant="eu" :server-error="form.errors.license_plate" />
                </div>

                <FormField
                    id="first_registration_date"
                    v-slot="{ id, describedBy, invalid }"
                    label="Erstzulassungsdatum"
                    hint="*(seh. Fahrzeugschein)"
                    :error="form.errors.first_registration_date"
                >
                    <Input
                        :id="id"
                        v-model="form.first_registration_date"
                        type="date"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div class="space-y-1.5">
                    <FormField
                        id="leasing_end_date"
                        v-slot="{ id, describedBy, invalid }"
                        label="Leasingende"
                        hint="*(seh. Leasingvertrag)"
                        :error="form.errors.leasing_end_date"
                    >
                        <Input
                            :id="id"
                            v-model="form.leasing_end_date"
                            type="date"
                            :disabled="leasingEndUnknown"
                            :class="fieldClass"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                    <Label for="leasing_end_unknown" class="text-brand-black flex items-center gap-2 text-xs font-normal">
                        <Checkbox id="leasing_end_unknown" v-model:checked="leasingEndUnknown" />
                        <span>Ich weiß es nicht</span>
                    </Label>
                </div>

                <FormField id="vin" v-slot="{ id, describedBy, invalid }" label="FIN" hint="(siehe Fahrzeugschein – Feld E)" :error="form.errors.vin">
                    <Input
                        :id="id"
                        :model-value="form.vin"
                        maxlength="17"
                        placeholder="Fahrzeugidentifikationsnummer"
                        :class="[fieldClass, 'uppercase']"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                        @update:model-value="(value) => (form.vin = sanitizeVin(value))"
                    />
                </FormField>

                <FormField id="make" v-slot="{ id, describedBy, invalid }" label="Marke" :error="form.errors.make">
                    <SelectField
                        :id="id"
                        v-model="form.make"
                        :options="VEHICLE_BRAND_OPTIONS"
                        :class="selectTriggerClass"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <FormField id="model" v-slot="{ id, describedBy, invalid }" label="Modell" :error="form.errors.model">
                    <Input
                        :id="id"
                        v-model="form.model"
                        placeholder="z.B. 3er oder Sonstige"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>
            </div>

            <div class="flex justify-end gap-3 pt-6">
                <Button type="button" :class="backButtonClass" @click="emit('back')">Zurück</Button>
                <Button type="submit" :class="nextButtonClass" :loading="form.processing">Weiter</Button>
            </div>
        </form>
    </OnboardingCard>
</template>

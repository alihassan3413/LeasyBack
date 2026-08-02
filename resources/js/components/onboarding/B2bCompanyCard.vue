<script setup lang="ts">
/**
 * First half of the Firmenkunde registration form: the company's own master
 * data. Mirrors leasyback_web's company/Register.vue, rebuilt on this app's
 * shared form components (FormField, SelectField, AddressAutocompleteField)
 * so it looks and behaves identically to the B2C onboarding wizard.
 */
import AddressAutocompleteField from '@/components/form/AddressAutocompleteField.vue';
import FormField from '@/components/form/FormField.vue';
import LogoUploadField from '@/components/form/LogoUploadField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import { Input } from '@/components/ui/input';
import type { ResolvedPlaceAddress } from '@/composables/useGooglePlaces';
import type { B2bRegistrationForm } from '@/types/b2b';
import { computed } from 'vue';

const props = defineProps<{
    form: B2bRegistrationForm;
    /** Logo already stored for this company, shown until it's replaced. */
    existingLogoUrl?: string | null;
}>();

const countryOptions: SelectFieldOption[] = [
    { label: 'Deutschland', value: 'Deutschland' },
    { label: 'Österreich', value: 'Österreich' },
    { label: 'Schweiz', value: 'Schweiz' },
];

// German ZIP codes are 5 digits, Austria/Switzerland use 4 — same rule the
// B2C ProfileStep applies, and the one B2bRegistrationRequest enforces.
const zipMaxLength = computed(() => (props.form.address.country === 'Deutschland' ? 5 : 4));

function onZipInput(value: string | number) {
    props.form.address.zip_code = String(value).replace(/\D+/g, '').slice(0, zipMaxLength.value);
}

// USt-IdNr. is a country code followed by alphanumerics, conventionally
// written uppercase and without separators.
function onVatIdInput(value: string | number) {
    props.form.vat_id = String(value)
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 14);
}

function applyResolvedAddress(resolved: ResolvedPlaceAddress) {
    if (resolved.street) props.form.address.street = resolved.street;
    if (resolved.number) props.form.address.number = resolved.number;
    if (resolved.zip_code) props.form.address.zip_code = resolved.zip_code;
    if (resolved.city) props.form.address.city = resolved.city;
}

// A newly picked file supersedes a pending removal, and vice versa.
function onLogoRemoved() {
    props.form.remove_logo = true;
}

const logoModel = computed({
    get: () => props.form.logo,
    set: (file: File | null) => {
        props.form.logo = file;

        if (file) {
            props.form.remove_logo = false;
        }
    },
});
</script>

<template>
    <OnboardingCard title="Registrierung" description="Bitte hinterlegen Sie die Daten Ihres Unternehmens.">
        <div class="space-y-3">
            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
                <FormField
                    id="company_name"
                    v-slot="{ id, describedBy, invalid }"
                    label="Firmenname (lt. HGB/Gewerbeeintrag)"
                    required
                    :error="form.errors.company_name"
                >
                    <Input
                        :id="id"
                        v-model="form.company_name"
                        maxlength="255"
                        placeholder="HWT GmbH"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField id="vat_id" v-slot="{ id, describedBy, invalid }" label="USt-IdNr." :error="form.errors.vat_id">
                    <Input
                        :id="id"
                        :model-value="form.vat_id"
                        maxlength="14"
                        placeholder="DE123456789"
                        autocapitalize="characters"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                        @update:model-value="onVatIdInput"
                    />
                </FormField>
            </div>

            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-[1fr_100px_1fr]">
                <FormField id="company_street" v-slot="{ id, describedBy, invalid }" label="Straße" required :error="form.errors['address.street']">
                    <AddressAutocompleteField
                        :id="id"
                        v-model="form.address.street"
                        :invalid="invalid"
                        :described-by="describedBy"
                        @resolved="applyResolvedAddress"
                    />
                </FormField>

                <FormField id="company_number" v-slot="{ id, describedBy, invalid }" label="Nr." required :error="form.errors['address.number']">
                    <Input :id="id" v-model="form.address.number" maxlength="50" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField
                    id="company_additional_address"
                    v-slot="{ id, describedBy, invalid }"
                    label="Zusätzliche Anschrift"
                    :error="form.errors['address.additional_address']"
                >
                    <Input
                        :id="id"
                        v-model="form.address.additional_address"
                        maxlength="255"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>
            </div>

            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-[100px_1fr_1fr]">
                <FormField id="company_zip_code" v-slot="{ id, describedBy, invalid }" label="PLZ" required :error="form.errors['address.zip_code']">
                    <Input
                        :id="id"
                        :model-value="form.address.zip_code"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        :maxlength="zipMaxLength"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                        @update:model-value="onZipInput"
                    />
                </FormField>

                <FormField id="company_city" v-slot="{ id, describedBy, invalid }" label="Ort" required :error="form.errors['address.city']">
                    <Input :id="id" v-model="form.address.city" maxlength="100" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField id="company_country" v-slot="{ id, describedBy, invalid }" label="Land" required :error="form.errors['address.country']">
                    <SelectField :id="id" v-model="form.address.country" :options="countryOptions" :invalid="invalid" :described-by="describedBy" />
                </FormField>
            </div>

            <FormField label="Laden Sie Ihr Logo hoch" :error="form.errors.logo">
                <LogoUploadField
                    v-model="logoModel"
                    :existing-url="form.remove_logo ? null : existingLogoUrl"
                    :disabled="form.processing"
                    @remove="onLogoRemoved"
                />
            </FormField>
        </div>
    </OnboardingCard>
</template>

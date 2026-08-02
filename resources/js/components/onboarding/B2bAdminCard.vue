<script setup lang="ts">
/**
 * Second half of the Firmenkunde registration form: the person who
 * administers the company's LeasyBack account. Mirrors leasyback_web's
 * company/AdminRegistration.vue; the phone rows reuse the same repeatable
 * PhoneNumberFieldset the B2C onboarding uses, so a company can register
 * more than the one number the old form allowed.
 */
import FormField from '@/components/form/FormField.vue';
import PhoneNumberFieldset from '@/components/form/PhoneNumberFieldset.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import { Input } from '@/components/ui/input';
import type { B2bRegistrationForm } from '@/types/b2b';
import { computed } from 'vue';

const props = defineProps<{ form: B2bRegistrationForm }>();

const salutationOptions: SelectFieldOption[] = [
    { label: 'Herr', value: 'Herr' },
    { label: 'Frau', value: 'Frau' },
    { label: 'Divers', value: 'Divers' },
];

// Laravel reports per-row failures as `phones.0.phone_number` — surfaced on
// the single fieldset rather than lost, since the rows share one label.
const phoneError = computed(() => {
    const errors = props.form.errors as Record<string, string | undefined>;

    if (errors.phones) {
        return errors.phones;
    }

    const rowKey = Object.keys(errors).find((key) => key.startsWith('phones.'));

    return rowKey ? errors[rowKey] : undefined;
});
</script>

<template>
    <OnboardingCard title="Admin für LeasyBack" description="z. B. Fuhrparkleiter oder Geschäftsführer.">
        <div class="space-y-3">
            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-3">
                <FormField
                    id="admin_salutation"
                    v-slot="{ id, describedBy, invalid }"
                    label="Anrede"
                    required
                    :error="form.errors['contact.salutation']"
                >
                    <SelectField
                        :id="id"
                        v-model="form.contact.salutation"
                        :options="salutationOptions"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <FormField
                    id="admin_first_name"
                    v-slot="{ id, describedBy, invalid }"
                    label="Vorname"
                    required
                    :error="form.errors['contact.first_name']"
                >
                    <Input :id="id" v-model="form.contact.first_name" maxlength="100" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField
                    id="admin_last_name"
                    v-slot="{ id, describedBy, invalid }"
                    label="Nachname"
                    required
                    :error="form.errors['contact.last_name']"
                >
                    <Input :id="id" v-model="form.contact.last_name" maxlength="100" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>
            </div>

            <FormField
                id="admin_email"
                v-slot="{ id, describedBy, invalid }"
                label="E-Mail-Adresse für Anfragen"
                required
                :error="form.errors.contact_email"
            >
                <Input
                    :id="id"
                    v-model="form.contact_email"
                    type="email"
                    maxlength="255"
                    autocomplete="email"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </FormField>

            <FormField label="Tel. für Anfragen" required :error="phoneError">
                <PhoneNumberFieldset v-model="form.phones" />
            </FormField>
        </div>

        <div v-if="$slots.actions" class="mt-3 flex flex-col-reverse items-center gap-3 border-t pt-5 sm:flex-row sm:justify-end">
            <slot name="actions" />
        </div>
    </OnboardingCard>
</template>

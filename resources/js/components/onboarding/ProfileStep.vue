<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FormField from '@/components/form/FormField.vue';
import PhoneNumberFieldset from '@/components/form/PhoneNumberFieldset.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { UserProfileData } from '@/types/profile';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ profile: UserProfileData | null }>();
const emit = defineEmits<{ next: [] }>();

const isComplete = computed(() => !!props.profile?.address && !!props.profile?.contact);

const salutationOptions: SelectFieldOption[] = [
    { label: 'Herr', value: 'Herr' },
    { label: 'Frau', value: 'Frau' },
    { label: 'Divers', value: 'Divers' },
];

const countryOptions: SelectFieldOption[] = [
    { label: 'Deutschland', value: 'Deutschland' },
    { label: 'Österreich', value: 'Österreich' },
    { label: 'Schweiz', value: 'Schweiz' },
];

const form = useForm({
    address: {
        street: '',
        number: '',
        additional_address: '',
        zip_code: '',
        city: '',
        country: 'Deutschland',
    },
    contact: {
        salutation: '',
        first_name: '',
        last_name: '',
    },
    phones: [{ international_prefix: '+49', phone_number: '' }],
});

// German ZIP codes are 5 digits, Austria/Switzerland use 4 — the field only
// ever accepts digits, capped to whichever length is valid for the chosen
// country.
const zipMaxLength = computed(() => (form.address.country === 'Deutschland' ? 5 : 4));

function onZipInput(value: string | number) {
    form.address.zip_code = String(value).replace(/\D+/g, '').slice(0, zipMaxLength.value);
}

function submit() {
    form.post(route('onboarding.profile.store'), {
        preserveScroll: true,
        onSuccess: () => emit('next'),
    });
}
</script>

<template>
    <OnboardingCard title="Kundendaten" description="Bitte ergänzen Sie Ihre Kundendaten oder Jetzt überspringen.">
        <template v-if="isComplete">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Name</dt>
                    <dd class="font-medium">
                        {{ profile!.contact!.salutation }} {{ profile!.contact!.first_name }} {{ profile!.contact!.last_name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Adresse</dt>
                    <dd class="font-medium">
                        {{ profile!.address!.street }} {{ profile!.address!.number }}, {{ profile!.address!.zip_code }}
                        {{ profile!.address!.city }}
                    </dd>
                </div>
            </dl>

            <div class="mt-6 flex justify-end border-t pt-5">
                <Button
                    type="button"
                    class="bg-brand-green hover:bg-brand-green/90 rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none"
                    @click="emit('next')"
                >
                    Weiter
                </Button>
            </div>
        </template>

        <form v-else novalidate class="space-y-3" @submit.prevent="submit">
            <InputError :message="form.errors.profile" />

            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-3">
                <FormField id="salutation" v-slot="{ id, describedBy, invalid }" label="Anrede" required :error="form.errors['contact.salutation']">
                    <SelectField
                        :id="id"
                        v-model="form.contact.salutation"
                        :options="salutationOptions"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <FormField id="first_name" v-slot="{ id, describedBy, invalid }" label="Vorname" required :error="form.errors['contact.first_name']">
                    <Input :id="id" v-model="form.contact.first_name" maxlength="100" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField id="last_name" v-slot="{ id, describedBy, invalid }" label="Nachname" required :error="form.errors['contact.last_name']">
                    <Input :id="id" v-model="form.contact.last_name" maxlength="100" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>
            </div>

            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-[1fr_100px_1fr]">
                <FormField id="street" v-slot="{ id, describedBy, invalid }" label="Straße" required :error="form.errors['address.street']">
                    <Input :id="id" v-model="form.address.street" maxlength="255" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField id="number" v-slot="{ id, describedBy, invalid }" label="Nr." required :error="form.errors['address.number']">
                    <Input :id="id" v-model="form.address.number" maxlength="50" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField
                    id="additional_address"
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
                <FormField id="zip_code" v-slot="{ id, describedBy, invalid }" label="PLZ" required :error="form.errors['address.zip_code']">
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

                <FormField id="city" v-slot="{ id, describedBy, invalid }" label="Ort" required :error="form.errors['address.city']">
                    <Input :id="id" v-model="form.address.city" maxlength="100" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <FormField id="country" v-slot="{ id, describedBy, invalid }" label="Land" required :error="form.errors['address.country']">
                    <SelectField :id="id" v-model="form.address.country" :options="countryOptions" :invalid="invalid" :described-by="describedBy" />
                </FormField>
            </div>

            <FormField label="Telefonnummer" required :error="form.errors.phones">
                <PhoneNumberFieldset v-model="form.phones" />
            </FormField>

            <div class="mt-3 flex flex-col-reverse items-center gap-3 border-t pt-5 sm:flex-row sm:justify-end">
                <Link
                    :href="route('dashboard')"
                    class="bg-brand-orange hover:bg-brand-orange/90 w-full rounded-[5px] px-8 py-2.5 text-center text-sm font-bold text-white transition sm:w-auto"
                >
                    Jetzt überspringen
                </Link>

                <Button
                    type="submit"
                    :loading="form.processing"
                    class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none sm:w-auto"
                >
                    Weiter
                </Button>
            </div>
        </form>
    </OnboardingCard>
</template>

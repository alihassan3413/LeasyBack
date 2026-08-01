<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import PhoneNumberFieldset from '@/components/form/PhoneNumberFieldset.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import type { UserProfileData } from '@/types/profile';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ profile: UserProfileData | null }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Address & contact', href: '/settings/address' }];

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

const creating = computed(() => props.profile?.address === null || props.profile === null);
const editing = ref(false);

function defaultPhones() {
    if (props.profile?.phones.length) {
        return props.profile.phones.map((phone) => ({
            international_prefix: phone.international_prefix,
            phone_number: phone.phone_number,
        }));
    }

    return [{ international_prefix: '+49', phone_number: '' }];
}

const form = useForm({
    address_id: props.profile?.address?.address_id ?? '',
    contact_id: props.profile?.contact?.contact_id ?? '',
    address: {
        street: props.profile?.address?.street ?? '',
        number: props.profile?.address?.number ?? '',
        additional_address: props.profile?.address?.additional_address ?? '',
        zip_code: props.profile?.address?.zip_code ?? '',
        city: props.profile?.address?.city ?? '',
        country: props.profile?.address?.country ?? 'Deutschland',
    },
    contact: {
        salutation: props.profile?.contact?.salutation ?? '',
        first_name: props.profile?.contact?.first_name ?? '',
        last_name: props.profile?.contact?.last_name ?? '',
    },
    phones: defaultPhones(),
});

function startEditing() {
    editing.value = true;
}

function cancelEditing() {
    form.reset();
    form.clearErrors();
    editing.value = false;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = false) };

    if (creating.value) {
        form.post(route('address.store'), options);
    } else {
        form.put(route('address.update'), options);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Address & contact" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall title="Address & contact" description="Your address, contact person, and phone numbers" />

                <form @submit.prevent="submit">
                    <SettingsCard
                        title="Address & contact"
                        description="Used for correspondence about your vehicles and orders"
                        :editing="editing"
                        :creating="creating"
                        :processing="form.processing"
                        @edit="startEditing"
                        @cancel="cancelEditing"
                    >
                        <template #read>
                            <div v-if="profile?.contact && profile.address" class="space-y-4 text-sm">
                                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-muted-foreground">Name</dt>
                                        <dd>{{ profile.contact.salutation }} {{ profile.contact.first_name }} {{ profile.contact.last_name }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">Address</dt>
                                        <dd>
                                            {{ profile.address.street }} {{ profile.address.number }}
                                            <span v-if="profile.address.additional_address">, {{ profile.address.additional_address }}</span
                                            ><br />
                                            {{ profile.address.zip_code }} {{ profile.address.city }}, {{ profile.address.country }}
                                        </dd>
                                    </div>
                                </dl>
                                <div>
                                    <p class="text-muted-foreground">Phone numbers</p>
                                    <ul>
                                        <li v-for="phone in profile.phones" :key="phone.phone_id">
                                            {{ phone.international_prefix }} {{ phone.phone_number }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <p v-else class="text-muted-foreground text-sm">You haven't added your address and contact details yet.</p>
                        </template>

                        <template #edit>
                            <div class="space-y-6">
                                <InputError :message="form.errors.address" />

                                <div class="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        id="salutation"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Salutation"
                                        required
                                        :error="form.errors['contact.salutation']"
                                    >
                                        <SelectField
                                            :id="id"
                                            v-model="form.contact.salutation"
                                            :options="salutationOptions"
                                            placeholder="Bitte wählen"
                                            :invalid="invalid"
                                            :described-by="describedBy"
                                        />
                                    </FormField>

                                    <FormField
                                        id="first_name"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="First name"
                                        required
                                        :error="form.errors['contact.first_name']"
                                    >
                                        <Input :id="id" v-model="form.contact.first_name" :aria-invalid="invalid" :aria-describedby="describedBy" />
                                    </FormField>

                                    <FormField
                                        id="last_name"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Last name"
                                        required
                                        :error="form.errors['contact.last_name']"
                                    >
                                        <Input :id="id" v-model="form.contact.last_name" :aria-invalid="invalid" :aria-describedby="describedBy" />
                                    </FormField>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        id="street"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Street"
                                        required
                                        :error="form.errors['address.street']"
                                    >
                                        <Input :id="id" v-model="form.address.street" :aria-invalid="invalid" :aria-describedby="describedBy" />
                                    </FormField>

                                    <FormField
                                        id="number"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="House number"
                                        required
                                        :error="form.errors['address.number']"
                                    >
                                        <Input :id="id" v-model="form.address.number" :aria-invalid="invalid" :aria-describedby="describedBy" />
                                    </FormField>
                                </div>

                                <FormField
                                    id="additional_address"
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Additional address line"
                                    :error="form.errors['address.additional_address']"
                                >
                                    <Input
                                        :id="id"
                                        v-model="form.address.additional_address"
                                        :aria-invalid="invalid"
                                        :aria-describedby="describedBy"
                                    />
                                </FormField>

                                <div class="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        id="zip_code"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="ZIP code"
                                        required
                                        :error="form.errors['address.zip_code']"
                                    >
                                        <Input :id="id" v-model="form.address.zip_code" :aria-invalid="invalid" :aria-describedby="describedBy" />
                                    </FormField>

                                    <FormField
                                        id="city"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="City"
                                        required
                                        :error="form.errors['address.city']"
                                    >
                                        <Input :id="id" v-model="form.address.city" :aria-invalid="invalid" :aria-describedby="describedBy" />
                                    </FormField>

                                    <FormField
                                        id="country"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Country"
                                        required
                                        :error="form.errors['address.country']"
                                    >
                                        <SelectField
                                            :id="id"
                                            v-model="form.address.country"
                                            :options="countryOptions"
                                            :invalid="invalid"
                                            :described-by="describedBy"
                                        />
                                    </FormField>
                                </div>

                                <FormField label="Phone numbers" required :error="form.errors.phones">
                                    <PhoneNumberFieldset v-model="form.phones" />
                                </FormField>
                            </div>
                        </template>
                    </SettingsCard>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>

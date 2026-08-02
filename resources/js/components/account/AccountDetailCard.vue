<script setup lang="ts">
import AddressAutocompleteField from '@/components/form/AddressAutocompleteField.vue';
import AddressMapPicker from '@/components/form/AddressMapPicker.vue';
import PhoneNumberFieldset from '@/components/form/PhoneNumberFieldset.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { isValidDeCoord, type ResolvedPlaceAddress } from '@/composables/useGooglePlaces';
import type { UserProfileData } from '@/types/profile';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ profile: UserProfileData | null; email: string }>();

const salutationOptions: SelectFieldOption[] = [
    { label: 'Herr', value: 'Herr' },
    { label: 'Frau', value: 'Frau' },
    { label: 'Divers', value: 'Divers' },
];

const isCreateMode = computed(() => !props.profile?.address || !props.profile?.contact);
const isEditMode = ref(false);

function initialPhones() {
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
        latitude: props.profile?.address?.latitude ?? null,
        longitude: props.profile?.address?.longitude ?? null,
    },
    contact: {
        salutation: props.profile?.contact?.salutation ?? '',
        first_name: props.profile?.contact?.first_name ?? '',
        last_name: props.profile?.contact?.last_name ?? '',
    },
    phones: initialPhones(),
});

const needsPhoneInput = computed(() => !props.profile?.phones.length);

const fullNameLine = computed(
    () =>
        [props.profile?.contact?.salutation, props.profile?.contact?.first_name, props.profile?.contact?.last_name].filter(Boolean).join(' ') || '—',
);

const addressLine1 = computed(() => [props.profile?.address?.street, props.profile?.address?.number].filter(Boolean).join(' ') || '—');
const addressLine2 = computed(() => [props.profile?.address?.zip_code, props.profile?.address?.city].filter(Boolean).join(' ') || '—');

// Street + city are the minimum for an unambiguous geocode: a lone street name
// resolves to a famous default (e.g. "Leopoldstraße" → München).
const addressQuery = computed(() => {
    const address = form.address;

    if (!address.street || !address.city) {
        return '';
    }

    const streetLine = [address.street, address.number].filter(Boolean).join(' ');
    const cityLine = [address.zip_code, address.city].filter(Boolean).join(' ');

    return [streetLine, cityLine, 'Deutschland'].filter(Boolean).join(', ');
});

function applyResolvedAddress(resolved: ResolvedPlaceAddress) {
    if (resolved.street) form.address.street = resolved.street;
    if (resolved.number) form.address.number = resolved.number;
    if (resolved.zip_code) form.address.zip_code = resolved.zip_code;
    if (resolved.city) form.address.city = resolved.city;

    form.address.latitude = resolved.latitude;
    form.address.longitude = resolved.longitude;
}

function onMapResolved(resolved: ResolvedPlaceAddress) {
    if (!isEditMode.value) {
        return;
    }

    applyResolvedAddress(resolved);
}

function enterEditMode() {
    form.clearErrors();
    isEditMode.value = true;
}

function cancelEdit() {
    form.reset();
    form.clearErrors();
    isEditMode.value = false;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            isEditMode.value = false;
        },
    };

    // Coordinates are only sent once Places/the map has actually resolved them —
    // the backend leaves any stored pair untouched when they're absent.
    const addressPayload = ({ latitude, longitude, ...address }: typeof form.address) =>
        isValidDeCoord(latitude, longitude) ? { ...address, latitude, longitude } : address;

    if (isCreateMode.value) {
        form.transform(({ address, contact, phones }) => ({ address: addressPayload(address), contact, phones })).post(
            route('address.store'),
            options,
        );

        return;
    }

    form.transform((data) => ({ ...data, address: addressPayload(data.address) })).put(route('address.update'), options);
}

const labelClass = 'text-sm font-bold text-black';
const dtClass = 'text-[10px] sm:text-[10.5px] font-bold uppercase tracking-[0.16em] text-[#9CB3B4]';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#EDF2F2] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-5">
            <div>
                <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Kontodaten</h2>
                <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">Persönliche Angaben und Anschrift</p>
            </div>

            <div class="flex shrink-0">
                <button
                    v-if="!isEditMode"
                    type="button"
                    class="hover:border-brand-green hover:text-brand-green flex items-center gap-1.5 rounded-lg border border-[#D1DCDC] bg-white px-3 py-2 text-sm font-semibold text-[#10393B] transition-all hover:bg-[#F0FBF8]"
                    @click="enterEditMode"
                >
                    <IconMdiPencilOutline class="size-4" />
                    {{ isCreateMode ? 'Profil erstellen' : 'Bearbeiten' }}
                </button>
                <button
                    v-else
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-[#7A9699] transition-colors hover:text-[#10393B]"
                    @click="cancelEdit"
                >
                    <IconMdiClose class="size-4" />
                    Abbrechen
                </button>
            </div>
        </div>

        <div v-if="isCreateMode && !isEditMode" class="px-4 py-10 text-center sm:px-8 sm:py-12">
            <IconMdiAccountCircleOutline class="mx-auto mb-3 size-10 text-[#9CB3B4] sm:size-12" />
            <p class="text-[14px] font-semibold text-[#10393B] sm:text-[15px]">Noch kein Profil hinterlegt</p>
            <p class="mt-1 text-[12px] text-[#7A9699] sm:text-[13px]">Klicken Sie auf „Profil erstellen", um Ihre Daten zu hinterlegen.</p>
        </div>

        <div v-else-if="!isEditMode" class="px-4 py-6 sm:px-5 sm:py-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
                <dl class="grid min-w-0 flex-1 grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 sm:gap-x-10 sm:gap-y-7">
                <div class="min-w-0">
                    <dt :class="dtClass">Name</dt>
                    <dd class="mt-1.5 text-[14px] font-semibold wrap-break-word text-[#10393B] sm:text-[15px]">{{ fullNameLine }}</dd>
                </div>

                <div class="min-w-0">
                    <dt :class="dtClass">E-Mail</dt>
                    <dd class="mt-1.5 text-[14px] font-semibold break-all text-[#10393B] sm:text-[15px]">{{ email || '—' }}</dd>
                </div>

                <div class="min-w-0 sm:col-span-2 lg:col-span-1">
                    <dt :class="dtClass">Anschrift</dt>
                    <dd class="mt-1.5 text-[14px] leading-relaxed font-semibold wrap-break-word text-[#10393B] sm:text-[15px]">
                        {{ addressLine1 }}<br />
                        <template v-if="profile?.address?.additional_address"> {{ profile.address.additional_address }}<br /> </template>
                        {{ addressLine2 }}
                    </dd>
                </div>

                    <div class="min-w-0">
                        <dt :class="dtClass">Land</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold text-[#10393B] sm:text-[15px]">
                            {{ profile?.address?.country || 'Deutschland' }}
                        </dd>
                    </div>
                </dl>

                <div class="h-[240px] w-full shrink-0 overflow-hidden rounded-2xl border border-[#D1DCDC] sm:h-[300px] lg:w-[400px]">
                    <AddressMapPicker
                        :latitude="form.address.latitude"
                        :longitude="form.address.longitude"
                        :address="addressQuery"
                        :interactive="false"
                    />
                </div>
            </div>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="space-y-6 px-4 py-6 sm:px-8 sm:py-7">
                <div class="flex min-w-0 flex-1 flex-wrap gap-x-5 gap-y-4 sm:gap-x-[30px]">
                    <div class="w-full shrink-0 sm:w-[128px]">
                        <Label for="account_salutation" :class="labelClass">Anrede</Label>
                        <SelectField
                            id="account_salutation"
                            v-model="form.contact.salutation"
                            :options="salutationOptions"
                            placeholder="Anrede"
                            class="mt-0.5"
                            :invalid="!!form.errors['contact.salutation']"
                        />
                        <p v-if="form.errors['contact.salutation']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.salutation'] }}</p>
                    </div>
                    <div class="min-w-[140px] flex-1">
                        <Label for="account_first_name" :class="labelClass">Vorname</Label>
                        <Input id="account_first_name" v-model="form.contact.first_name" placeholder="Vorname" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['contact.first_name']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.first_name'] }}</p>
                    </div>
                    <div class="min-w-[140px] flex-1">
                        <Label for="account_last_name" :class="labelClass">Nachname</Label>
                        <Input id="account_last_name" v-model="form.contact.last_name" placeholder="Nachname" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['contact.last_name']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.last_name'] }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span :class="dtClass">Anschrift</span>
                    <span class="h-px flex-1 bg-[#EDF2F2]" />
                </div>
                <p class="mt-1.5! text-[12px] text-[#7A9699] sm:text-[13px]">
                    {{ isCreateMode ? 'Bitte geben Sie Ihre Daten ein, um Ihr Profil zu erstellen.' : 'Adresse eingeben.' }}
                </p>

                <div class="flex flex-col gap-6 lg:flex-row">
                    <div class="grid min-w-0 flex-1 grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-[2fr_1fr] sm:gap-x-[30px]">
                        <div>
                            <Label for="account_street" :class="labelClass">Straße</Label>
                            <AddressAutocompleteField
                                id="account_street"
                                v-model="form.address.street"
                                placeholder="Straße eingeben…"
                                class="mt-0.5 text-sm text-black"
                                :invalid="!!form.errors['address.street']"
                                @resolved="applyResolvedAddress"
                            />
                            <p v-if="form.errors['address.street']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.street'] }}</p>
                        </div>
                    <div>
                        <Label for="account_number" :class="labelClass">Nr.</Label>
                        <Input id="account_number" v-model="form.address.number" placeholder="Nr." class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['address.number']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.number'] }}</p>
                    </div>
                    <div>
                        <Label for="account_additional" :class="labelClass">Zusätzliche Anschrift</Label>
                        <Input
                            id="account_additional"
                            v-model="form.address.additional_address"
                            placeholder="Adresszusatz"
                            class="mt-0.5 text-sm text-black"
                        />
                        <p v-if="form.errors['address.additional_address']" class="text-brand-orange mt-1 text-xs">
                            {{ form.errors['address.additional_address'] }}
                        </p>
                    </div>
                    <div>
                        <Label for="account_zip" :class="labelClass">PLZ</Label>
                        <Input id="account_zip" v-model="form.address.zip_code" placeholder="PLZ" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['address.zip_code']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.zip_code'] }}</p>
                    </div>
                    <div>
                        <Label for="account_city" :class="labelClass">Ort</Label>
                        <Input id="account_city" v-model="form.address.city" placeholder="Ort" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['address.city']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.city'] }}</p>
                    </div>
                        <div class="flex flex-col">
                            <span class="mb-1.5 text-sm font-semibold text-[#10393B]">Land</span>
                            <span class="py-2 text-[14px] font-semibold text-[#10393B] sm:text-[15px]">
                                {{ form.address.country || 'Deutschland' }}
                            </span>
                        </div>
                    </div>

                    <div class="h-[220px] w-full shrink-0 overflow-hidden rounded-2xl border border-[#D1DCDC] sm:h-[260px] lg:w-[380px]">
                        <AddressMapPicker
                            :latitude="form.address.latitude"
                            :longitude="form.address.longitude"
                            :address="addressQuery"
                            :interactive="true"
                            @resolved="onMapResolved"
                        />
                    </div>
                </div>

                <div v-if="needsPhoneInput" class="space-y-2">
                    <div class="flex items-center gap-3">
                        <span :class="dtClass">Telefon</span>
                        <span class="h-px flex-1 bg-[#EDF2F2]" />
                    </div>
                    <PhoneNumberFieldset v-model="form.phones" />
                    <p v-if="form.errors.phones" class="text-brand-orange text-xs">{{ form.errors.phones }}</p>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-[#EDF2F2] bg-[#F8FAFB] px-4 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-8">
                <p v-if="form.errors.address" class="text-sm text-red-500 sm:mr-auto">{{ form.errors.address }}</p>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-[#7A9699] transition-colors hover:text-[#10393B]"
                    @click="cancelEdit"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="bg-brand-green h-[38px] w-full rounded-lg px-6 text-sm font-semibold text-white transition-all hover:bg-[#019d7a] disabled:opacity-60 sm:w-auto"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Wird gespeichert…' : isCreateMode ? 'Profil erstellen' : 'Änderungen speichern' }}
                </button>
            </div>
        </form>
    </div>
</template>

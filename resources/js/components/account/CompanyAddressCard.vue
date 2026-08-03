<script setup lang="ts">
/**
 * "Kontodaten" on Mein Konto for a company account: the company's registered
 * address and the numbers enquiries go to. Both come straight from company
 * registration — a Firmenkunde has no separate personal address.
 */
import AddressAutocompleteField from '@/components/form/AddressAutocompleteField.vue';
import AddressMapPicker from '@/components/form/AddressMapPicker.vue';
import PhoneNumberFieldset from '@/components/form/PhoneNumberFieldset.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCompanyCard } from '@/composables/useCompanyCard';
import type { ResolvedPlaceAddress } from '@/composables/useGooglePlaces';
import type { B2bCompanyData } from '@/types/b2b';
import { computed, toRef } from 'vue';

const props = defineProps<{ company: B2bCompanyData; email: string; canManage: boolean }>();

const { form, isEditing, startEditing, cancelEditing, submit } = useCompanyCard(toRef(props, 'company'));

const countryOptions: SelectFieldOption[] = [
    { label: 'Deutschland', value: 'Deutschland' },
    { label: 'Österreich', value: 'Österreich' },
    { label: 'Schweiz', value: 'Schweiz' },
];

// German ZIP codes are 5 digits, Austria/Switzerland 4 — the rule
// B2bRegistrationRequest enforces server-side.
const zipMaxLength = computed(() => (form.address.country === 'Deutschland' ? 5 : 4));

function onZipInput(value: string | number) {
    form.address.zip_code = String(value).replace(/\D+/g, '').slice(0, zipMaxLength.value);
}

const addressLine1 = computed(() => [props.company.address?.street, props.company.address?.number].filter(Boolean).join(' ') || '—');
const addressLine2 = computed(() => [props.company.address?.zip_code, props.company.address?.city].filter(Boolean).join(' ') || '—');

const hasAddress = computed(() => Boolean(props.company.address?.street && props.company.address?.city));

const phoneLines = computed(() =>
    (props.company.contact?.phone_numbers ?? [])
        .map((phone) => [phone.international_prefix, phone.phone_number].filter(Boolean).join(' ').trim())
        .filter(Boolean),
);

// Street + city are the minimum for an unambiguous geocode: a lone street name
// resolves to a famous default (e.g. "Leopoldstraße" → München).
const addressQuery = computed(() => {
    const address = form.address;

    if (!address.street || !address.city) {
        return '';
    }

    const streetLine = [address.street, address.number].filter(Boolean).join(' ');
    const cityLine = [address.zip_code, address.city].filter(Boolean).join(' ');

    return [streetLine, cityLine, address.country || 'Deutschland'].filter(Boolean).join(', ');
});

function applyResolvedAddress(resolved: ResolvedPlaceAddress) {
    if (resolved.street) form.address.street = resolved.street;
    if (resolved.number) form.address.number = resolved.number;
    if (resolved.zip_code) form.address.zip_code = resolved.zip_code;
    if (resolved.city) form.address.city = resolved.city;
}

// Company addresses store no coordinates (B2BService writes none), so the map
// always works from the address line rather than a stored pair.
function onMapResolved(resolved: ResolvedPlaceAddress) {
    if (!isEditing.value) {
        return;
    }

    applyResolvedAddress(resolved);
}

const phoneError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    if (errors.phones) {
        return errors.phones;
    }

    const rowKey = Object.keys(errors).find((key) => key.startsWith('phones.'));

    return rowKey ? errors[rowKey] : undefined;
});

const labelClass = 'text-sm font-bold text-black';
const dtClass = 'text-[10px] sm:text-[10.5px] font-bold uppercase tracking-[0.16em] text-[#9CB3B4]';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#EDF2F2] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-5">
            <div>
                <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Kontodaten</h2>
                <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">Anschrift und Erreichbarkeit des Unternehmens</p>
            </div>

            <div v-if="canManage" class="flex shrink-0">
                <button
                    v-if="!isEditing"
                    type="button"
                    class="hover:border-brand-green hover:text-brand-green flex items-center gap-1.5 rounded-lg border border-[#D1DCDC] bg-white px-3 py-2 text-sm font-semibold text-[#10393B] transition-all hover:bg-[#F0FBF8]"
                    @click="startEditing"
                >
                    <IconMdiPencilOutline class="size-4" />
                    Bearbeiten
                </button>
                <button
                    v-else
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-[#7A9699] transition-colors hover:text-[#10393B]"
                    @click="cancelEditing"
                >
                    <IconMdiClose class="size-4" />
                    Abbrechen
                </button>
            </div>
        </div>

        <div v-if="!isEditing" class="px-4 py-6 sm:px-5 sm:py-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
                <dl class="grid min-w-0 flex-1 grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 sm:gap-x-10 sm:gap-y-7">
                    <div class="min-w-0">
                        <dt :class="dtClass">Firma</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold wrap-break-word text-[#10393B] sm:text-[15px]">
                            {{ company.company_name || '—' }}
                        </dd>
                    </div>

                    <div class="min-w-0">
                        <dt :class="dtClass">E-Mail</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold break-all text-[#10393B] sm:text-[15px]">{{ email || '—' }}</dd>
                    </div>

                    <div class="min-w-0">
                        <dt :class="dtClass">Telefon</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold text-[#10393B] sm:text-[15px]">
                            <template v-if="phoneLines.length">
                                <span v-for="line in phoneLines" :key="line" class="block">{{ line }}</span>
                            </template>
                            <template v-else>—</template>
                        </dd>
                    </div>

                    <div class="min-w-0">
                        <dt :class="dtClass">Land</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold text-[#10393B] sm:text-[15px]">
                            {{ company.address?.country || 'Deutschland' }}
                        </dd>
                    </div>

                    <div class="min-w-0 sm:col-span-2">
                        <dt :class="dtClass">Anschrift</dt>
                        <dd class="mt-1.5 text-[14px] leading-relaxed font-semibold wrap-break-word text-[#10393B] sm:text-[15px]">
                            {{ addressLine1 }}<br />
                            <template v-if="company.address?.additional_address"> {{ company.address.additional_address }}<br /> </template>
                            {{ addressLine2 }}
                        </dd>
                    </div>
                </dl>

                <div class="h-[240px] w-full shrink-0 overflow-hidden rounded-2xl border border-[#D1DCDC] sm:h-[300px] lg:w-[400px]">
                    <AddressMapPicker v-if="hasAddress" :latitude="null" :longitude="null" :address="addressQuery" :interactive="false" />
                    <div v-else class="flex size-full flex-col items-center justify-center gap-1 bg-[#F1F5F5] px-6 text-center">
                        <IconMdiMapMarkerOutline class="size-7 text-[#B7C7C7]" />
                        <p class="text-[12px] font-semibold text-[#7A9699]">Keine Anschrift hinterlegt</p>
                        <p class="text-[11px] text-[#9CB3B4]">Die Firmenadresse erscheint hier auf der Karte.</p>
                    </div>
                </div>
            </div>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="space-y-6 px-4 py-6 sm:px-8 sm:py-7">
                <div class="flex flex-col gap-6 lg:flex-row">
                    <div class="grid min-w-0 flex-1 grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-[2fr_1fr] sm:gap-x-[30px]">
                        <div>
                            <Label for="company_street" :class="labelClass">Straße</Label>
                            <AddressAutocompleteField
                                id="company_street"
                                v-model="form.address.street"
                                placeholder="Straße eingeben…"
                                class="mt-0.5 text-sm text-black"
                                :invalid="!!form.errors['address.street']"
                                @resolved="applyResolvedAddress"
                            />
                            <p v-if="form.errors['address.street']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.street'] }}</p>
                        </div>
                        <div>
                            <Label for="company_number" :class="labelClass">Nr.</Label>
                            <Input id="company_number" v-model="form.address.number" maxlength="50" class="mt-0.5 text-sm text-black" />
                            <p v-if="form.errors['address.number']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.number'] }}</p>
                        </div>
                        <div>
                            <Label for="company_additional" :class="labelClass">Zusätzliche Anschrift</Label>
                            <Input
                                id="company_additional"
                                v-model="form.address.additional_address"
                                maxlength="255"
                                class="mt-0.5 text-sm text-black"
                            />
                            <p v-if="form.errors['address.additional_address']" class="text-brand-orange mt-1 text-xs">
                                {{ form.errors['address.additional_address'] }}
                            </p>
                        </div>
                        <div>
                            <Label for="company_zip" :class="labelClass">PLZ</Label>
                            <Input
                                id="company_zip"
                                :model-value="form.address.zip_code"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                :maxlength="zipMaxLength"
                                class="mt-0.5 text-sm text-black"
                                @update:model-value="onZipInput"
                            />
                            <p v-if="form.errors['address.zip_code']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.zip_code'] }}</p>
                        </div>
                        <div>
                            <Label for="company_city" :class="labelClass">Ort</Label>
                            <Input id="company_city" v-model="form.address.city" maxlength="100" class="mt-0.5 text-sm text-black" />
                            <p v-if="form.errors['address.city']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.city'] }}</p>
                        </div>
                        <div>
                            <Label for="company_country" :class="labelClass">Land</Label>
                            <SelectField
                                id="company_country"
                                v-model="form.address.country"
                                :options="countryOptions"
                                class="mt-0.5"
                                :invalid="!!form.errors['address.country']"
                            />
                            <p v-if="form.errors['address.country']" class="text-brand-orange mt-1 text-xs">{{ form.errors['address.country'] }}</p>
                        </div>
                    </div>

                    <div class="h-[220px] w-full shrink-0 overflow-hidden rounded-2xl border border-[#D1DCDC] sm:h-[260px] lg:w-[380px]">
                        <AddressMapPicker :latitude="null" :longitude="null" :address="addressQuery" :interactive="true" @resolved="onMapResolved" />
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center gap-3">
                        <span :class="dtClass">Tel. für Anfragen</span>
                        <span class="h-px flex-1 bg-[#EDF2F2]" />
                    </div>
                    <PhoneNumberFieldset v-model="form.phones" />
                    <p v-if="phoneError" class="text-brand-orange text-xs">{{ phoneError }}</p>
                </div>
            </div>

            <div
                class="flex flex-col-reverse gap-3 border-t border-[#EDF2F2] bg-[#F8FAFB] px-4 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-8"
            >
                <p v-if="form.errors.company" class="text-sm text-red-500 sm:mr-auto">{{ form.errors.company }}</p>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-[#7A9699] transition-colors hover:text-[#10393B]"
                    @click="cancelEditing"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="bg-brand-green h-[38px] w-full rounded-lg px-6 text-sm font-semibold text-white transition-all hover:bg-[#019d7a] disabled:opacity-60 sm:w-auto"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Wird gespeichert…' : 'Änderungen speichern' }}
                </button>
            </div>
        </form>
    </div>
</template>

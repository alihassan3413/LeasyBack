<script setup lang="ts">
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UserProfileData } from '@/types/profile';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ profile: UserProfileData | null }>();

const salutationOptions: SelectFieldOption[] = [
    { label: 'Herr', value: 'Herr' },
    { label: 'Frau', value: 'Frau' },
    { label: 'Divers', value: 'Divers' },
];

const isEditMode = ref(false);

const canEdit = computed(() => !!props.profile?.address && !!props.profile?.contact);

const form = useForm({
    salutation: props.profile?.contact?.salutation ?? '',
    first_name: props.profile?.contact?.first_name ?? '',
    last_name: props.profile?.contact?.last_name ?? '',
});

const contactLine = computed(
    () =>
        [props.profile?.contact?.salutation, props.profile?.contact?.first_name, props.profile?.contact?.last_name].filter(Boolean).join(' ') ||
        'Noch kein Ansprechpartner hinterlegt',
);

function enterEditMode() {
    form.reset();
    form.clearErrors();
    isEditMode.value = true;
}

function cancelEdit() {
    form.reset();
    form.clearErrors();
    isEditMode.value = false;
}

function submit() {
    const profile = props.profile;

    if (!profile?.address || !profile.contact) {
        return;
    }

    form.transform((data) => ({
        address_id: profile.address!.address_id,
        contact_id: profile.contact!.contact_id,
        address: {
            street: profile.address!.street,
            number: profile.address!.number,
            additional_address: profile.address!.additional_address ?? '',
            zip_code: profile.address!.zip_code,
            city: profile.address!.city,
            country: profile.address!.country,
        },
        contact: {
            salutation: data.salutation,
            first_name: data.first_name,
            last_name: data.last_name,
        },
        phones: profile.phones.map((phone) => ({
            international_prefix: phone.international_prefix,
            phone_number: phone.phone_number,
        })),
    })).put(route('address.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            isEditMode.value = false;
        },
    });
}

const labelClass = 'text-sm font-bold text-black';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#EDF2F2] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-5">
            <div>
                <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Ansprechpartner</h2>
                <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">Für LeasyBack, z. B. Fuhrparkleitung oder Geschäftsführung</p>
            </div>

            <div v-if="canEdit" class="flex shrink-0">
                <button
                    v-if="!isEditMode"
                    type="button"
                    class="hover:border-brand-green hover:text-brand-green flex items-center gap-1.5 rounded-lg border border-[#D1DCDC] bg-white px-3 py-2 text-sm font-semibold text-[#10393B] transition-all hover:bg-[#F0FBF8]"
                    @click="enterEditMode"
                >
                    <IconMdiPencilOutline class="size-4" />
                    Bearbeiten
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

        <div v-if="!isEditMode" class="px-4 py-5 sm:px-8 sm:py-7">
            <div class="flex items-center gap-4">
                <div class="text-brand-green flex size-10 shrink-0 items-center justify-center rounded-full bg-[#EDF6F4] shadow-sm sm:size-12">
                    <IconMdiAccountTieOutline class="size-4 sm:size-5" />
                </div>
                <div class="min-w-0">
                    <p class="truncate text-[14px] font-semibold text-[#10393B] sm:text-[15px]">{{ contactLine }}</p>
                    <p class="mt-0.5 text-[10px] font-bold tracking-[0.12em] text-[#9CB3B4] uppercase sm:text-[11px]">Ansprechpartner</p>
                </div>
            </div>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="flex flex-col gap-y-5 px-4 py-5 sm:flex-row sm:flex-wrap sm:gap-x-[30px] sm:px-8 sm:py-7">
                <div class="w-full shrink-0 sm:w-[128px]">
                    <Label for="contact_salutation" :class="labelClass">Anrede</Label>
                    <SelectField
                        id="contact_salutation"
                        v-model="form.salutation"
                        :options="salutationOptions"
                        placeholder="Anrede"
                        class="mt-0.5"
                        :invalid="!!form.errors['contact.salutation']"
                    />
                    <p v-if="form.errors['contact.salutation']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.salutation'] }}</p>
                </div>
                <div class="min-w-[140px] flex-1">
                    <Label for="contact_first_name" :class="labelClass">Vorname</Label>
                    <Input id="contact_first_name" v-model="form.first_name" placeholder="Vorname" class="mt-0.5 text-sm text-black" />
                    <p v-if="form.errors['contact.first_name']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.first_name'] }}</p>
                </div>
                <div class="min-w-[140px] flex-1">
                    <Label for="contact_last_name" :class="labelClass">Nachname</Label>
                    <Input id="contact_last_name" v-model="form.last_name" placeholder="Nachname" class="mt-0.5 text-sm text-black" />
                    <p v-if="form.errors['contact.last_name']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.last_name'] }}</p>
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
                    {{ form.processing ? 'Wird gespeichert…' : 'Speichern' }}
                </button>
            </div>
        </form>
    </div>
</template>

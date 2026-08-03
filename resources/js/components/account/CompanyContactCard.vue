<script setup lang="ts">
/**
 * "Ansprechpartner" on Mein Konto for a company account: the person who
 * administers the company's LeasyBack account, as entered during company
 * registration.
 */
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCompanyCard } from '@/composables/useCompanyCard';
import type { B2bCompanyData } from '@/types/b2b';
import { computed, toRef } from 'vue';

const props = defineProps<{ company: B2bCompanyData; canManage: boolean }>();

const { form, isEditing, startEditing, cancelEditing, submit } = useCompanyCard(toRef(props, 'company'));

const salutationOptions: SelectFieldOption[] = [
    { label: 'Herr', value: 'Herr' },
    { label: 'Frau', value: 'Frau' },
    { label: 'Divers', value: 'Divers' },
];

const contactLine = computed(
    () =>
        [props.company.contact?.salutation, props.company.contact?.first_name, props.company.contact?.last_name].filter(Boolean).join(' ') ||
        'Noch kein Ansprechpartner hinterlegt',
);

const labelClass = 'text-sm font-bold text-black';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#EDF2F2] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-5">
            <div>
                <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Ansprechpartner</h2>
                <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">Admin für LeasyBack, z. B. Fuhrparkleiter oder Geschäftsführer</p>
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

        <div v-if="!isEditing" class="px-4 py-6 sm:px-8 sm:py-7">
            <p class="text-[14px] font-semibold wrap-break-word text-[#10393B] sm:text-[15px]">{{ contactLine }}</p>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="px-4 py-6 sm:px-8 sm:py-7">
                <div class="flex min-w-0 flex-wrap gap-x-5 gap-y-4 sm:gap-x-[30px]">
                    <div class="w-full shrink-0 sm:w-[128px]">
                        <Label for="company_contact_salutation" :class="labelClass">Anrede</Label>
                        <SelectField
                            id="company_contact_salutation"
                            v-model="form.contact.salutation"
                            :options="salutationOptions"
                            placeholder="Anrede"
                            class="mt-0.5"
                            :invalid="!!form.errors['contact.salutation']"
                        />
                        <p v-if="form.errors['contact.salutation']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.salutation'] }}</p>
                    </div>
                    <div class="min-w-[140px] flex-1">
                        <Label for="company_contact_first_name" :class="labelClass">Vorname</Label>
                        <Input id="company_contact_first_name" v-model="form.contact.first_name" maxlength="100" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['contact.first_name']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.first_name'] }}</p>
                    </div>
                    <div class="min-w-[140px] flex-1">
                        <Label for="company_contact_last_name" :class="labelClass">Nachname</Label>
                        <Input id="company_contact_last_name" v-model="form.contact.last_name" maxlength="100" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors['contact.last_name']" class="text-brand-orange mt-1 text-xs">{{ form.errors['contact.last_name'] }}</p>
                    </div>
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

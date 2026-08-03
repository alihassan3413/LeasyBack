<script setup lang="ts">
/**
 * "Firmendaten" on Mein Konto: the company's own identity — name, USt-IdNr.,
 * the e-mail enquiries go to, and the logo. Populated from what was entered
 * during company registration.
 */
import LogoUploadField from '@/components/form/LogoUploadField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCompanyCard } from '@/composables/useCompanyCard';
import type { B2bCompanyData } from '@/types/b2b';
import { computed, toRef } from 'vue';

const props = defineProps<{ company: B2bCompanyData; canManage: boolean }>();

const { form, isEditing, startEditing, cancelEditing, submit } = useCompanyCard(toRef(props, 'company'));

// USt-IdNr. is a country code followed by alphanumerics, conventionally
// written uppercase and without separators — same normalisation the
// registration form applies.
function onVatIdInput(value: string | number) {
    form.vat_id = String(value)
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 14);
}

function onLogoRemoved() {
    form.remove_logo = true;
}

const logoModel = computed({
    get: () => form.logo,
    set: (file: File | null) => {
        form.logo = file;

        if (file) {
            form.remove_logo = false;
        }
    },
});

const labelClass = 'text-sm font-bold text-black';
const dtClass = 'text-[10px] sm:text-[10.5px] font-bold uppercase tracking-[0.16em] text-[#9CB3B4]';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#EDF2F2] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-5">
            <div>
                <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Firmendaten</h2>
                <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">Angaben zu Ihrem Unternehmen</p>
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

        <div v-if="!isEditing" class="px-4 py-6 sm:px-8 sm:py-8">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                <dl class="grid min-w-0 flex-1 grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 sm:gap-x-10 sm:gap-y-7">
                    <div class="min-w-0">
                        <dt :class="dtClass">Firmenname</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold wrap-break-word text-[#10393B] sm:text-[15px]">
                            {{ company.company_name || '—' }}
                        </dd>
                    </div>

                    <div class="min-w-0">
                        <dt :class="dtClass">USt-IdNr.</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold text-[#10393B] sm:text-[15px]">{{ company.vat_id || '—' }}</dd>
                    </div>

                    <div class="min-w-0 sm:col-span-2">
                        <dt :class="dtClass">E-Mail für Anfragen</dt>
                        <dd class="mt-1.5 text-[14px] font-semibold break-all text-[#10393B] sm:text-[15px]">
                            {{ company.contact_email || '—' }}
                        </dd>
                    </div>
                </dl>

                <div
                    class="flex h-[104px] w-full shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-[#D1DCDC] bg-[#F8FAFB] p-3 sm:w-[180px]"
                >
                    <img v-if="company.logo_url" :src="company.logo_url" alt="Firmenlogo" class="max-h-full max-w-full object-contain" />
                    <div v-else class="flex flex-col items-center gap-1 text-center">
                        <IconMdiImageOutline class="size-6 text-[#B7C7C7]" />
                        <p class="text-[11px] font-semibold text-[#9CB3B4]">Kein Logo</p>
                    </div>
                </div>
            </div>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="space-y-6 px-4 py-6 sm:px-8 sm:py-7">
                <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2 sm:gap-x-[30px]">
                    <div>
                        <Label for="company_name" :class="labelClass">Firmenname (lt. HGB/Gewerbeeintrag)</Label>
                        <Input id="company_name" v-model="form.company_name" maxlength="255" class="mt-0.5 text-sm text-black" />
                        <p v-if="form.errors.company_name" class="text-brand-orange mt-1 text-xs">{{ form.errors.company_name }}</p>
                    </div>

                    <div>
                        <Label for="company_vat_id" :class="labelClass">USt-IdNr.</Label>
                        <Input
                            id="company_vat_id"
                            :model-value="form.vat_id"
                            maxlength="14"
                            placeholder="DE123456789"
                            autocapitalize="characters"
                            class="mt-0.5 text-sm text-black"
                            @update:model-value="onVatIdInput"
                        />
                        <p v-if="form.errors.vat_id" class="text-brand-orange mt-1 text-xs">{{ form.errors.vat_id }}</p>
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="company_contact_email" :class="labelClass">E-Mail-Adresse für Anfragen</Label>
                        <Input
                            id="company_contact_email"
                            v-model="form.contact_email"
                            type="email"
                            maxlength="255"
                            autocomplete="email"
                            class="mt-0.5 text-sm text-black"
                        />
                        <p v-if="form.errors.contact_email" class="text-brand-orange mt-1 text-xs">{{ form.errors.contact_email }}</p>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center gap-3">
                        <span :class="dtClass">Logo</span>
                        <span class="h-px flex-1 bg-[#EDF2F2]" />
                    </div>
                    <LogoUploadField
                        v-model="logoModel"
                        :existing-url="form.remove_logo ? null : company.logo_url"
                        :disabled="form.processing"
                        @remove="onLogoRemoved"
                    />
                    <p v-if="form.errors.logo" class="text-brand-orange text-xs">{{ form.errors.logo }}</p>
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

<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{ email: string }>();

const isEditMode = ref(false);
const successMessage = ref('');

const form = useForm({
    current_password: '',
    password: '',
});

function cancelEdit() {
    form.reset();
    form.clearErrors();
    successMessage.value = '';
    isEditMode.value = false;
}

function enterEditMode() {
    form.reset();
    form.clearErrors();
    successMessage.value = '';
    isEditMode.value = true;
}

function submit() {
    successMessage.value = '';

    form.put(route('profile.password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            successMessage.value = 'Passwort erfolgreich geändert.';
            form.reset();
            isEditMode.value = false;
        },
    });
}

const dtClass = 'text-[10px] sm:text-[10.5px] font-bold uppercase tracking-[0.16em] text-[#9CB3B4]';
const labelClass = 'text-sm font-bold text-black';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#EDF2F2] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-5">
            <div>
                <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Passwort &amp; Anmeldung</h2>
                <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">E-Mail-Adresse und Passwort Ihres Kontos</p>
            </div>

            <div class="flex shrink-0">
                <button
                    v-if="!isEditMode"
                    type="button"
                    class="hover:border-brand-green hover:text-brand-green flex items-center gap-1.5 rounded-lg border border-[#D1DCDC] bg-white px-3 py-2 text-sm font-semibold text-[#10393B] transition-all hover:bg-[#F0FBF8]"
                    @click="enterEditMode"
                >
                    <IconMdiKeyVariant class="size-4" />
                    Passwort ändern
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

        <div v-if="!isEditMode" class="px-4 py-6 sm:px-8 sm:py-8">
            <p v-if="successMessage" class="text-brand-green mb-5 rounded-lg bg-[#F0FBF8] px-4 py-2.5 text-[13px] font-semibold">
                {{ successMessage }}
            </p>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 sm:gap-x-10 sm:gap-y-7">
                <div>
                    <dt :class="dtClass">E-Mail-Adresse</dt>
                    <dd class="mt-1.5 text-[14px] font-semibold break-all text-[#10393B] sm:text-[15px]">{{ email || '—' }}</dd>
                </div>
                <div>
                    <dt :class="dtClass">Passwort</dt>
                    <dd class="mt-1.5 text-lg tracking-[0.3em] text-[#9CB3B4] sm:text-xl">••••••••••</dd>
                </div>
            </dl>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="w-full max-w-[440px] space-y-5 px-4 py-6 sm:px-8 sm:py-7">
                <div>
                    <span :class="dtClass">Angemeldet als</span>
                    <p class="mt-1.5 text-[14px] font-semibold break-all text-[#10393B] sm:text-[15px]">{{ email }}</p>
                </div>

                <div>
                    <Label for="current_password" :class="labelClass">
                        Altes Passwort
                        <span class="text-brand-orange text-sm font-bold">*</span>
                    </Label>
                    <Input
                        id="current_password"
                        v-model="form.current_password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Altes Passwort"
                        class="mt-0.5 text-sm text-black"
                    />
                    <p v-if="form.errors.current_password" class="text-brand-orange mt-1 text-xs">{{ form.errors.current_password }}</p>
                </div>

                <div>
                    <Label for="new_password" :class="labelClass">
                        Neues Passwort
                        <span class="text-brand-orange text-sm font-bold">*</span>
                    </Label>
                    <Input
                        id="new_password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Neues Passwort"
                        class="mt-0.5 text-sm text-black"
                    />
                    <p v-if="form.errors.password" class="text-brand-orange mt-1 text-xs">{{ form.errors.password }}</p>
                </div>

                <p class="text-[12px] text-[#7A9699] sm:text-[13px]">Mindestens 8 Zeichen, mit Groß- und Kleinbuchstaben sowie einer Zahl.</p>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-[#EDF2F2] bg-[#F8FAFB] px-4 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-8">
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
                    {{ form.processing ? 'Wird gespeichert…' : 'Passwort speichern' }}
                </button>
            </div>
        </form>
    </div>
</template>

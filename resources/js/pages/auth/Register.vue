<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import type { UserType } from '@/types/auth';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';

// Labels differ from the stored value for Werkstatt only ("Werksatatt" is
// the backend's real, existing enum value — see docs/AUTH_MODULE.md §4).
const roleOptions: { value: UserType; label: string }[] = [
    { value: 'Privatkunde', label: 'Privatkunde' },
    { value: 'Firmenkunde', label: 'Firmenkunde' },
    { value: 'Werksatatt', label: 'Werkstatt' },
];

const form = useForm({
    user_type: '' as UserType | '',
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password'),
    });
};

// Design match note: consistent with Login/ForgotPassword/etc. — see Login.vue.
const fieldClass = 'h-auto rounded-[5px] border-brand-green-gray bg-white px-3 py-2.5 text-sm';

// Native <select> doesn't go through the shared Input component, so it needs
// its own full styling (border, focus ring, etc.) rather than just the
// design-match overrides above; appearance-none + the manual ChevronDown
// icon below replace the inconsistent native browser dropdown arrow.
const selectClass =
    'h-auto w-full appearance-none rounded-[5px] border border-brand-green-gray bg-white px-3 py-2.5 pr-9 text-sm text-foreground outline-none transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 aria-[invalid=true]:border-destructive';
</script>

<template>
    <AuthBase>
        <Head title="Registrieren" />

        <div class="flex min-h-145 flex-col">
            <p
                class="text-brand-teal mx-auto mt-10 mb-14 max-w-73 text-left text-lg leading-normal font-bold sm:mt-16.25 sm:mb-25 xl:mt-22.75 xl:mb-35 xl:text-xl"
            >
                Sie können sich als Werkstatt, als Firmenkunde oder auch als Privatkunde registrieren
            </p>

            <div class="flex-1" />

            <form novalidate class="space-y-5" @submit.prevent="submit">
                <FormField id="user_type" v-slot="{ id, describedBy, invalid }" label="Jetzt registrieren als" :error="form.errors.user_type">
                    <div class="relative">
                        <select
                            :id="id"
                            v-model="form.user_type"
                            required
                            :class="selectClass"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        >
                            <option value="" disabled>Bitte wählen</option>
                            <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <ChevronDown
                            class="text-muted-foreground pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2"
                            aria-hidden="true"
                        />
                    </div>
                </FormField>

                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="E-Mail-Adresse"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div>
                    <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" :error="form.errors.password">
                        <PasswordInput
                            :id="id"
                            v-model="form.password"
                            required
                            autocomplete="new-password"
                            placeholder="Passwort"
                            :class="fieldClass"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <p class="text-brand-green-gray mt-1.5 text-xs">Mindestens 8 Zeichen.</p>
                </div>

                <div class="pt-6">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                    >
                        {{ form.processing ? 'Registrieren…' : 'Registrieren' }}
                    </Button>
                </div>
            </form>

            <p class="text-brand-black mt-5 text-center text-sm font-medium">
                Sind Sie schon Kunde bei uns?
                <Link :href="route('login')" class="text-brand-orange font-medium">Zum Login</Link>
            </p>
        </div>
    </AuthBase>
</template>

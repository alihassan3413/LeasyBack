<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

// Design match note: overrides the shared Input/PasswordInput's default look
// to match leasyback_web exactly on this page (see Login.vue for the same).
const fieldClass = 'h-auto rounded-[5px] border-brand-green-gray bg-white px-3 py-2.5 text-sm';
</script>

<template>
    <AuthBase>
        <Head title="Registrieren" />

        <div class="flex min-h-145 flex-col">
            <!--
                leasyback_web's original heading here ("Sie können sich als
                Werkstatt, als Firmenkunde oder auch als Privatkunde
                registrieren") describes a role-selector that doesn't exist
                on this backend — public registration always defaults to
                Privatkunde, by design (see docs/AUTH_MODULE.md §4). Adapted
                the copy to match reality rather than carry over a claim
                that's no longer true; kept the exact same visual style/spacing.
            -->
            <p
                class="text-brand-teal mx-auto mt-10 mb-14 max-w-73 text-left text-lg leading-normal font-bold sm:mt-16.25 sm:mb-25 xl:mt-22.75 xl:mb-35 xl:text-xl"
            >
                Erstellen Sie jetzt kostenlos Ihr Konto
            </p>

            <div class="flex-1" />

            <form novalidate class="space-y-5" @submit.prevent="submit">
                <FormField id="name" v-slot="{ id, describedBy, invalid }" label="Name" :error="form.errors.name">
                    <Input
                        :id="id"
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="name"
                        placeholder="Vor- und Nachname"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        required
                        tabindex="2"
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
                            tabindex="3"
                            autocomplete="new-password"
                            placeholder="Passwort"
                            :class="fieldClass"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <p class="text-brand-green-gray mt-1.5 text-xs">Mindestens 8 Zeichen.</p>
                </div>

                <FormField
                    id="password_confirmation"
                    v-slot="{ id, describedBy, invalid }"
                    label="Passwort bestätigen"
                    :error="form.errors.password_confirmation"
                >
                    <PasswordInput
                        :id="id"
                        v-model="form.password_confirmation"
                        required
                        tabindex="4"
                        autocomplete="new-password"
                        placeholder="Passwort wiederholen"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div class="pt-6">
                    <Button
                        type="submit"
                        tabindex="5"
                        :disabled="form.processing"
                        class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                    >
                        {{ form.processing ? 'Registrieren…' : 'Registrieren' }}
                    </Button>
                </div>
            </form>

            <p class="text-brand-black mt-5 text-center text-sm font-medium">
                Sind Sie schon Kunde bei uns?
                <Link :href="route('login')" tabindex="6" class="text-brand-orange font-medium">Zum Login</Link>
            </p>
        </div>
    </AuthBase>
</template>

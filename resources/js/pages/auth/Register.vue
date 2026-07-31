<script setup lang="ts">
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import FormField from '@/components/form/FormField.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

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
</script>

<template>
    <AuthBase title="Konto erstellen" description="Gib deine Daten ein, um ein Konto zu erstellen">
        <Head title="Registrieren" />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6">
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
                        placeholder="name@beispiel.de"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" :error="form.errors.password">
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        required
                        tabindex="3"
                        autocomplete="new-password"
                        placeholder="Passwort"
                        :aria-invalid="invalid"
                        :aria-describedby="[describedBy, 'password-requirements'].filter(Boolean).join(' ')"
                    />
                    <PasswordRequirements id="password-requirements" />
                </FormField>

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
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <Button type="submit" class="mt-2 w-full" tabindex="5" :loading="form.processing"> Konto erstellen </Button>
            </div>

            <div class="text-muted-foreground text-center text-sm">
                Sie haben bereits ein Konto?
                <TextLink :href="route('login')" class="underline underline-offset-4" tabindex="6">Zum Login</TextLink>
            </div>
        </form>
    </AuthBase>
</template>

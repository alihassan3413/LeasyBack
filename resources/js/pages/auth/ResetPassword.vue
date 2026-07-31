<script setup lang="ts">
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    token: string;
    email: string;
}

const props = defineProps<Props>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout title="Neues Passwort festlegen" description="Bitte gib dein neues Passwort ein">
        <Head title="Neues Passwort festlegen" />

        <form @submit.prevent="submit">
            <div class="grid gap-6">
                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        readonly
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Neues Passwort" :error="form.errors.password">
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        autocomplete="new-password"
                        autofocus
                        placeholder="Neues Passwort"
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
                        autocomplete="new-password"
                        placeholder="Passwort wiederholen"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <Button type="submit" class="mt-4 w-full" :loading="form.processing"> Passwort zurücksetzen </Button>
            </div>
        </form>
    </AuthLayout>
</template>

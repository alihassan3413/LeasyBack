<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { PasswordInput } from '@/components/ui/password-input';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <AuthLayout
        title="Passwort bestätigen"
        description="Dies ist ein geschützter Bereich der Anwendung. Bitte bestätige dein Passwort, bevor du fortfährst"
    >
        <Head title="Passwort bestätigen" />

        <form @submit.prevent="submit">
            <div class="space-y-6">
                <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" :error="form.errors.password">
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        autofocus
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div class="flex items-center">
                    <Button type="submit" class="w-full" :loading="form.processing"> Passwort bestätigen </Button>
                </div>
            </div>
        </form>
    </AuthLayout>
</template>

<script setup lang="ts">
import { authButton, authField } from '@/components/auth/authClasses';
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
    <AuthLayout title="Passwort bestätigen" description="Dies ist ein geschützter Bereich. Bitte bestätigen Sie Ihr Passwort, um fortzufahren.">
        <Head title="Passwort bestätigen" />

        <form class="space-y-4" @submit.prevent="submit">
            <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" required :error="form.errors.password">
                <PasswordInput
                    :id="id"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                    :class="authField"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </FormField>

            <div class="pt-4">
                <Button type="submit" :disabled="form.processing" :class="authButton">
                    {{ form.processing ? 'Wird bestätigt…' : 'Passwort bestätigen' }}
                </Button>
            </div>
        </form>
    </AuthLayout>
</template>

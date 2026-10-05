<script setup lang="ts">
import { authButton, authField, authLink } from '@/components/auth/authClasses';
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout
        title="Passwort zurücksetzen"
        description="Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen einen Link, mit dem Sie ein neues Passwort festlegen können."
    >
        <Head title="Passwort zurücksetzen" />

        <AuthStatusMessage v-if="status">{{ status }}</AuthStatusMessage>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" required :error="form.errors.email">
                <Input
                    :id="id"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="email"
                    autofocus
                    :class="authField"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </FormField>

            <div class="pt-4">
                <Button type="submit" :disabled="form.processing" :class="authButton">
                    {{ form.processing ? 'Wird gesendet…' : 'Link anfordern' }}
                </Button>
            </div>
        </form>

        <template #after>
            <Link :href="route('login')" :class="authLink">Zurück zur Anmeldung</Link>
        </template>
    </AuthLayout>
</template>

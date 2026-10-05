<script setup lang="ts">
import { authButton, authLink } from '@/components/auth/authClasses';
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import { Button } from '@/components/ui/button';
import { useSessionGuard } from '@/composables/useSessionGuard';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const { logout } = useSessionGuard();

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout title="E-Mail-Adresse bestätigen" description="Bitte bestätigen Sie Ihre E-Mail-Adresse über den Link, den wir Ihnen gesendet haben.">
        <Head title="E-Mail-Bestätigung" />

        <AuthStatusMessage v-if="status === 'verification-link-sent'">
            Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die Sie bei der Registrierung angegeben haben.
        </AuthStatusMessage>

        <form @submit.prevent="submit">
            <Button type="submit" :disabled="form.processing" :class="authButton">
                {{ form.processing ? 'Wird gesendet…' : 'Bestätigungslink erneut senden' }}
            </Button>
        </form>

        <template #after>
            <button type="button" :class="['cursor-pointer', authLink]" @click="logout('manual')">Abmelden</button>
        </template>
    </AuthLayout>
</template>

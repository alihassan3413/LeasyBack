<script setup lang="ts">
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout title="E-Mail-Adresse bestätigen" description="Bitte bestätige deine E-Mail-Adresse über den Link, den wir dir gerade gesendet haben">
        <Head title="E-Mail-Bestätigung" />

        <AuthStatusMessage v-if="status === 'verification-link-sent'">
            Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die du bei der Registrierung angegeben hast.
        </AuthStatusMessage>

        <form class="space-y-6 text-center" @submit.prevent="submit">
            <Button type="submit" variant="secondary" :loading="form.processing"> Bestätigungslink erneut senden </Button>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm"> Abmelden </TextLink>
        </form>
    </AuthLayout>
</template>

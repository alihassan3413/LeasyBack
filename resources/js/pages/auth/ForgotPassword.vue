<script setup lang="ts">
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import FormField from '@/components/form/FormField.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

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
        description="Gib deine E-Mail-Adresse ein und wir senden dir einen Link zum Zurücksetzen deines Passworts"
    >
        <Head title="Passwort zurücksetzen" />

        <AuthStatusMessage v-if="status">{{ status }}</AuthStatusMessage>

        <div class="space-y-6">
            <form @submit.prevent="submit">
                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        autofocus
                        placeholder="name@beispiel.de"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div class="my-6 flex items-center justify-start">
                    <Button type="submit" class="w-full" :loading="form.processing"> Link zum Zurücksetzen senden </Button>
                </div>
            </form>

            <div class="text-muted-foreground space-x-1 text-center text-sm">
                <span>Oder zurück zum</span>
                <TextLink :href="route('login')">Login</TextLink>
            </div>
        </div>
    </AuthLayout>
</template>

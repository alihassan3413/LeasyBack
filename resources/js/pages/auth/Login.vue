<script setup lang="ts">
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import FormField from '@/components/form/FormField.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Anmelden" description="Gib deine E-Mail-Adresse und dein Passwort ein, um dich anzumelden">
        <Head title="Anmelden" />

        <AuthStatusMessage v-if="status">{{ status }}</AuthStatusMessage>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6">
                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        placeholder="name@beispiel.de"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField id="password" v-slot="{ id, describedBy, invalid }" :error="form.errors.password">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <Label :for="id">Passwort</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" tabindex="5">
                            Passwort vergessen?
                        </TextLink>
                    </div>
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        required
                        tabindex="2"
                        autocomplete="current-password"
                        placeholder="Passwort"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div class="flex items-center justify-between" tabindex="3">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                        <span>Angemeldet bleiben</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" tabindex="4" :loading="form.processing"> Anmelden </Button>
            </div>

            <div class="text-muted-foreground text-center text-sm">
                Sie sind noch kein Kunde bei uns?
                <TextLink :href="route('register')" :tabindex="5">Hier registrieren</TextLink>
            </div>
        </form>
    </AuthBase>
</template>

<script setup lang="ts">
import { authButton, authCheckbox, authField, authLink } from '@/components/auth/authClasses';
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// Remounts the error alert on every failed attempt so a repeated, identical
// message ("Anmeldedaten stimmen nicht …") is announced again.
const failedAttempts = ref(0);

const submit = () => {
    form.post(route('login'), {
        onError: () => failedAttempts.value++,
        onFinish: () => form.reset('password'),
    });
};

/*
 * LoginRequest reports wrong credentials and the rate-limit lockout on the
 * `email` key. Both concern the whole form, not the address itself, so they
 * are shown once above the fields and both fields are marked invalid.
 */
const formError = computed(() => form.errors.email);
const describedBy = (fieldDescribedBy?: string) => [formError.value ? 'login-error' : null, fieldDescribedBy].filter(Boolean).join(' ') || undefined;
</script>

<template>
    <AuthBase title="Anmelden" description="Melden Sie sich mit Ihrem LeasyBack-Konto an.">
        <Head title="Anmelden" />

        <AuthStatusMessage v-if="status">{{ status }}</AuthStatusMessage>

        <AuthStatusMessage v-if="formError" id="login-error" :key="failedAttempts" variant="error">{{ formError }}</AuthStatusMessage>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField id="email" v-slot="{ id }" label="E-Mail-Adresse" required>
                <Input
                    :id="id"
                    v-model="form.email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    :class="authField"
                    :aria-invalid="!!formError"
                    :aria-describedby="describedBy()"
                />
            </FormField>

            <FormField
                id="password"
                v-slot="{ id, describedBy: passwordDescribedBy, invalid }"
                label="Passwort"
                required
                :error="form.errors.password"
            >
                <!--
                    No length hint here on purpose: login accepts any existing
                    password, including older ones shorter than the current
                    minimum for new passwords.
                -->
                <PasswordInput
                    :id="id"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    :class="authField"
                    :aria-invalid="invalid || !!formError"
                    :aria-describedby="describedBy(passwordDescribedBy)"
                />
            </FormField>

            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 pt-1">
                <Label for="remember" class="flex items-center gap-2 font-normal">
                    <Checkbox id="remember" v-model="form.remember" :class="authCheckbox" />
                    <span>Angemeldet bleiben</span>
                </Label>

                <Link v-if="canResetPassword" :href="route('password.request')" :class="['text-sm', authLink]">Passwort vergessen?</Link>
            </div>

            <div class="pt-4">
                <Button type="submit" :disabled="form.processing" :class="authButton">
                    {{ form.processing ? 'Anmeldung läuft…' : 'Anmelden' }}
                </Button>
            </div>
        </form>

        <template #after>
            Noch kein Konto?
            <Link :href="route('register')" :class="['ml-1', authLink]">Registrieren</Link>
        </template>
    </AuthBase>
</template>

<script setup lang="ts">
import { authButton, authField, authHint, authLink } from '@/components/auth/authClasses';
import AuthStatusMessage from '@/components/auth/AuthStatusMessage.vue';
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import type { UserType } from '@/types/auth';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

// Labels differ from the stored value for Werkstatt only ("Werksatatt" is
// the backend's real, existing enum value — see docs/AUTH_MODULE.md §4).
const roleOptions: SelectFieldOption[] = [
    { value: 'Privatkunde', label: 'Privatkunde' },
    { value: 'Firmenkunde', label: 'Firmenkunde' },
    { value: 'Werksatatt', label: 'Werkstatt' },
];

/**
 * When set, this registration is someone joining a company they were invited
 * to. Account type and address both come from the invitation, so neither is
 * asked for — see RegisteredUserController::create().
 */
const props = defineProps<{
    invitation: {
        token: string;
        email: string;
        company_name: string;
        role_label: string;
    } | null;
}>();

const isInvited = computed(() => props.invitation !== null);

const form = useForm({
    user_type: '' as UserType | '',
    email: props.invitation?.email ?? '',
    password: '',
    invitation: props.invitation?.token ?? '',
});

const submit = () => {
    form
        // Only send what the form actually asked for. An invited registration
        // has no account-type field, so posting the empty one it still holds
        // just gives the server something to reject.
        .transform((data) =>
            isInvited.value
                ? { email: data.email, password: data.password, invitation: data.invitation }
                : { user_type: data.user_type, email: data.email, password: data.password },
        )
        .post(route('register'), {
            onFinish: () => form.reset('password'),
        });
};

/**
 * An error on a field this variant of the form does not render would
 * otherwise be invisible — the page would say only "Bitte prüfen Sie Ihre
 * Eingaben" with nothing marked. Surface it at the top instead.
 */
const hiddenFieldError = computed(() => (isInvited.value ? form.errors.user_type : undefined));

const title = computed(() => (props.invitation ? `Konto erstellen und ${props.invitation.company_name} beitreten` : 'Konto erstellen'));
const description = computed(() =>
    props.invitation
        ? `Sie wurden als ${props.invitation.role_label} eingeladen. Nach der Registrierung sind Sie sofort Mitglied des Unternehmens.`
        : 'Registrieren Sie sich als Privatkunde, Firmenkunde oder Werkstatt.',
);
</script>

<template>
    <AuthBase :title="title" :description="description">
        <Head title="Registrieren" />

        <AuthStatusMessage v-if="hiddenFieldError" variant="error">{{ hiddenFieldError }}</AuthStatusMessage>

        <form novalidate class="space-y-4" @submit.prevent="submit">
            <FormField
                v-if="!isInvited"
                id="user_type"
                v-slot="{ id, describedBy, invalid }"
                label="Kontotyp"
                required
                :error="form.errors.user_type"
            >
                <SelectField
                    :id="id"
                    :model-value="form.user_type"
                    :options="roleOptions"
                    placeholder="Bitte wählen"
                    :invalid="invalid"
                    :described-by="describedBy"
                    :class="authField"
                    @update:model-value="(value) => (form.user_type = value as UserType)"
                />
            </FormField>

            <FormField
                id="email"
                v-slot="{ id, describedBy, invalid }"
                label="E-Mail-Adresse"
                required
                :hint="isInvited ? 'Die Einladung gilt für diese Adresse und kann nicht geändert werden.' : undefined"
                :error="form.errors.email"
            >
                <Input
                    :id="id"
                    v-model="form.email"
                    type="email"
                    required
                    :readonly="isInvited"
                    autocomplete="email"
                    :class="authField"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </FormField>

            <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" required :error="form.errors.password">
                <PasswordInput
                    :id="id"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                    :class="authField"
                    :aria-invalid="invalid"
                    :aria-describedby="[describedBy, 'password-requirements'].filter(Boolean).join(' ')"
                />
                <PasswordRequirements id="password-requirements" :class="authHint" />
            </FormField>

            <div class="pt-4">
                <Button type="submit" :disabled="form.processing" :class="authButton">
                    {{ form.processing ? 'Registrierung läuft…' : isInvited ? 'Registrieren und beitreten' : 'Registrieren' }}
                </Button>
            </div>
        </form>

        <template #after>
            Sie haben bereits ein Konto?
            <Link :href="route('login')" :class="['ml-1', authLink]">Anmelden</Link>
        </template>
    </AuthBase>
</template>

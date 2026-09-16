<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import InputError from '@/components/InputError.vue';
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

// Design match note: consistent with Login/ForgotPassword/etc. — see Login.vue.
const fieldClass = 'h-auto rounded-full border-brand-green-gray bg-white px-4 py-2.5 text-sm';
</script>

<template>
    <AuthBase>
        <Head title="Registrieren" />

        <div class="flex min-h-145 flex-col">
            <div
                class="text-brand-teal mx-auto mt-10 mb-14 max-w-73 text-left text-lg leading-normal font-bold sm:mt-16.25 sm:mb-25 xl:mt-22.75 xl:mb-35 xl:text-xl"
            >
                <template v-if="invitation">
                    <p>Konto erstellen und {{ invitation.company_name }} beitreten</p>
                    <p class="text-brand-black/60 mt-2 text-sm font-medium">
                        Sie wurden als {{ invitation.role_label }} eingeladen. Nach der Registrierung sind Sie sofort im Unternehmen.
                    </p>
                </template>
                <p v-else>Sie können sich als Werkstatt, als Firmenkunde oder auch als Privatkunde registrieren</p>
            </div>

            <div class="flex-1" />

            <form novalidate class="space-y-5" @submit.prevent="submit">
                <InputError :message="hiddenFieldError" />

                <FormField
                    v-if="!isInvited"
                    id="user_type"
                    v-slot="{ id, describedBy, invalid }"
                    label="Jetzt registrieren als"
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
                        @update:model-value="(value) => (form.user_type = value as UserType)"
                    />
                </FormField>

                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" required :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        required
                        :readonly="isInvited"
                        autocomplete="email"
                        placeholder="E-Mail-Adresse"
                        :class="[fieldClass, isInvited ? 'bg-muted cursor-not-allowed' : '']"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                    <p v-if="isInvited" class="text-brand-green-gray mt-1.5 text-xs">
                        Die Einladung gilt für diese Adresse und kann nicht geändert werden.
                    </p>
                </FormField>

                <div>
                    <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" required :error="form.errors.password">
                        <PasswordInput
                            :id="id"
                            v-model="form.password"
                            required
                            autocomplete="new-password"
                            placeholder="Passwort"
                            :class="fieldClass"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <p class="text-brand-green-gray mt-1.5 text-xs">Mindestens 8 Zeichen.</p>
                </div>

                <div class="pt-6">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                    >
                        {{ form.processing ? 'Registrieren…' : isInvited ? 'Registrieren und beitreten' : 'Registrieren' }}
                    </Button>
                </div>
            </form>

            <p class="text-brand-black mt-5 text-center text-sm font-medium">
                Sind Sie schon Kunde bei uns?
                <Link :href="route('login')" class="text-brand-orange font-medium">Zum Login</Link>
            </p>
        </div>
    </AuthBase>
</template>

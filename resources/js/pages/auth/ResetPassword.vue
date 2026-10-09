<script setup lang="ts">
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    token: string;
    email: string;
    /**
     * Where the form posts. The Base44 account activation reuses this page
     * with its own broker (LegacyActivationController); a normal reset keeps
     * the default.
     */
    submitRoute?: string;
    heading?: string;
    intro?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    submitRoute: 'password.store',
    heading: 'Neues Passwort festlegen',
    intro: null,
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route(props.submitRoute), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};

// Design match note: consistent with Login/Register/ForgotPassword — see
// Login.vue for the source of this pattern.
const fieldClass = 'h-auto rounded-full border-brand-green-gray bg-white px-4 py-2.5 text-sm';
</script>

<template>
    <AuthLayout>
        <Head :title="heading" />

        <div class="flex flex-col py-8">
            <p class="text-brand-teal mx-auto mt-8 max-w-[292px] text-left text-lg font-bold xl:text-xl" :class="intro ? 'mb-3' : 'mb-8'">
                {{ heading }}
            </p>
            <p v-if="intro" class="text-muted-foreground mx-auto mb-8 max-w-[292px] text-left text-sm">{{ intro }}</p>

            <form class="space-y-5" @submit.prevent="submit">
                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" required :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        readonly
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div>
                    <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Neues Passwort" required :error="form.errors.password">
                        <PasswordInput
                            :id="id"
                            v-model="form.password"
                            autocomplete="new-password"
                            autofocus
                            placeholder="Neues Passwort"
                            :class="fieldClass"
                            :aria-invalid="invalid"
                            :aria-describedby="[describedBy, 'password-requirements'].filter(Boolean).join(' ')"
                        />
                    </FormField>

                    <PasswordRequirements id="password-requirements" class="mt-1.5" />
                </div>

                <FormField
                    id="password_confirmation"
                    v-slot="{ id, describedBy, invalid }"
                    label="Passwort bestätigen"
                    required
                    :error="form.errors.password_confirmation"
                >
                    <PasswordInput
                        :id="id"
                        v-model="form.password_confirmation"
                        autocomplete="new-password"
                        placeholder="Passwort wiederholen"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div class="pt-2">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                    >
                        {{ form.processing ? 'Wird gespeichert…' : 'Passwort zurücksetzen' }}
                    </Button>
                </div>
            </form>
        </div>
    </AuthLayout>
</template>

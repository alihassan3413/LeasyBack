<script setup lang="ts">
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

// Design match note: consistent with Login/Register/ForgotPassword — see
// Login.vue for the source of this pattern.
const fieldClass = 'h-auto rounded-full border-brand-green-gray bg-white px-4 py-2.5 text-sm';
</script>

<template>
    <AuthLayout>
        <Head title="Passwort bestätigen" />

        <div class="flex flex-col py-8">
            <p class="text-brand-teal mx-auto my-8 max-w-[292px] text-left text-lg font-bold xl:text-xl">Passwort bestätigen</p>

            <p class="text-brand-green-gray mb-5 text-xs">
                Dies ist ein geschützter Bereich der Anwendung. Bitte bestätige dein Passwort, bevor du fortfährst.
            </p>

            <form class="space-y-5" @submit.prevent="submit">
                <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" :error="form.errors.password">
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        autofocus
                        placeholder="Passwort"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                >
                    {{ form.processing ? 'Wird bestätigt…' : 'Passwort bestätigen' }}
                </Button>
            </form>
        </div>
    </AuthLayout>
</template>

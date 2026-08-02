<script setup lang="ts">
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

// Design match note: overrides the shared Input's default look to match
// leasyback_web exactly on this page (see Login.vue for the same).
const fieldClass = 'h-auto rounded-full border-brand-green-gray bg-white px-4 py-2.5 text-sm';
</script>

<template>
    <AuthLayout>
        <Head title="Passwort zurücksetzen" />

        <div class="flex flex-col py-8">
            <p class="text-brand-teal mx-auto my-8 max-w-[292px] text-left text-lg font-bold xl:text-xl">Passwort zurücksetzen</p>

            <AuthStatusMessage v-if="status">{{ status }}</AuthStatusMessage>

            <form class="space-y-2" @submit.prevent="submit">
                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        autofocus
                        placeholder="E-Mail-Adresse"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <p class="text-brand-green-gray text-xs">Geben Sie Ihre E-Mail ein und wir senden Ihnen einen Reset-Link.</p>

                <div class="pt-4">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                    >
                        {{ form.processing ? 'Wird gesendet…' : 'Reset-Link senden' }}
                    </Button>
                </div>

                <div class="text-center">
                    <Link
                        :href="route('login')"
                        class="text-brand-green text-[14px] font-bold underline decoration-[1.12px] underline-offset-[2.8px]"
                    >
                        Zurück zum Login
                    </Link>
                </div>
            </form>
        </div>
    </AuthLayout>
</template>

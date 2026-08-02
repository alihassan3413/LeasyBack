<script setup lang="ts">
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
    <AuthLayout>
        <Head title="E-Mail-Bestätigung" />

        <div class="flex flex-col py-8">
            <p class="text-brand-teal mx-auto my-8 max-w-73 text-left text-lg font-bold xl:text-xl">E-Mail-Adresse bestätigen</p>

            <p class="text-brand-green-gray mb-5 text-xs">Bitte bestätige deine E-Mail-Adresse über den Link, den wir dir gerade gesendet haben.</p>

            <AuthStatusMessage v-if="status === 'verification-link-sent'" variant="success">
                Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die du bei der Registrierung angegeben hast.
            </AuthStatusMessage>

            <form class="space-y-5 text-center" @submit.prevent="submit">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                >
                    {{ form.processing ? 'Wird gesendet…' : 'Bestätigungslink erneut senden' }}
                </Button>

                <button
                    type="button"
                    class="text-brand-green mx-auto block text-[14px] font-bold underline decoration-[1.12px] underline-offset-[2.8px]"
                    @click="logout('manual')"
                >
                    Abmelden
                </button>
            </form>
        </div>
    </AuthLayout>
</template>

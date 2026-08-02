<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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

const fieldClass = 'h-auto rounded-full border-brand-green-gray bg-white px-4 py-2.5 text-sm';
</script>

<template>
    <AuthBase>
        <Head title="Anmelden" />

        <div class="flex flex-col lg:min-h-145">
            <p class="text-brand-teal mx-auto mt-10 mb-14 max-w-73 text-left text-lg font-bold sm:mt-16.25 sm:mb-25 xl:mt-22.75 xl:mb-35 xl:text-xl">
                Hallo! Willkommen zurück!
            </p>

            <img src="/leasyback-logo-dark.svg" alt="LeasyBack" class="mx-auto -mt-6 mb-8 h-auto w-full max-w-55 lg:hidden" />

            <div class="flex-1" />

            <div v-if="status" class="mb-4 rounded-[5px] border border-green-300 bg-green-50 p-3 text-center text-sm text-green-700">
                {{ status }}
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <FormField id="email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" :error="form.errors.email">
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        placeholder="E-Mail-Adresse"
                        :class="fieldClass"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <div>
                    <FormField id="password" v-slot="{ id, describedBy, invalid }" label="Passwort" :error="form.errors.password">
                        <PasswordInput
                            :id="id"
                            v-model="form.password"
                            required
                            tabindex="2"
                            autocomplete="current-password"
                            placeholder="Passwort"
                            :class="fieldClass"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <p class="text-brand-green-gray mt-1.5 text-xs">Mindestens 8 Zeichen.</p>

                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        tabindex="5"
                        class="text-brand-green mt-1 block text-[14px] font-bold underline decoration-[1.12px] underline-offset-[2.8px]"
                    >
                        Passwort vergessen?
                    </Link>
                </div>

                <div class="flex items-center justify-between" tabindex="3">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model="form.remember" tabindex="4" />
                        <span>Angemeldet bleiben</span>
                    </Label>
                </div>

                <div class="pt-6">
                    <Button
                        type="submit"
                        tabindex="4"
                        :disabled="form.processing"
                        class="bg-brand-orange hover:bg-brand-orange/90 h-auto w-full rounded-[5px] py-3 text-sm font-bold text-white shadow-none"
                    >
                        {{ form.processing ? 'Einloggen…' : 'Einloggen' }}
                    </Button>
                </div>
            </form>

            <p class="text-brand-black mt-5 text-center text-sm">
                Sind Sie noch kein Kunde bei uns?
                <Link :href="route('register')" tabindex="5" class="text-brand-orange">Hier registrieren</Link>
            </p>
        </div>
    </AuthBase>
</template>

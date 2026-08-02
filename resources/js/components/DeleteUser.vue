<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// Components
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';

const passwordInput = ref<HTMLInputElement | null>(null);
const showDeleteModal = ref(false);

const form = useForm({
    password: '',
});

const deleteUser = (e: Event) => {
    e.preventDefault();

    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    form.clearErrors();
    form.reset();
    showDeleteModal.value = false;
};
</script>

<template>
    <div class="space-y-6">
        <HeadingSmall title="Delete account" description="Delete your account and all of its resources" />
        <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Warning</p>
                <p class="text-sm">Please proceed with caution, this cannot be undone.</p>
            </div>
            <Button variant="destructive" @click="showDeleteModal = true">Delete account</Button>

            <AppModal
                :open="showDeleteModal"
                title="Are you sure you want to delete your account?"
                description="Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account."
                :width="620"
                @update:open="(value) => !value && closeModal()"
            >
                <form class="px-2" @submit="deleteUser">
                    <div class="grid gap-1">
                        <Label for="password" class="sr-only">Password</Label>
                        <Input id="password" type="password" name="password" ref="passwordInput" v-model="form.password" placeholder="Password" />
                        <InputError :message="form.errors.password" />
                    </div>
                </form>

                <template #footer>
                    <AppModalButton :disabled="form.processing" @click="deleteUser">
                        {{ form.processing ? 'Wird gelöscht...' : 'Delete account' }}
                    </AppModalButton>
                </template>
            </AppModal>
        </div>
    </div>
</template>

<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import type { PreferencesData } from '@/types/profile';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ preferences: PreferencesData | null }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Preferences', href: '/settings/preferences' }];

const timezoneOptions: SelectFieldOption[] = [
    { label: 'Berlin', value: 'Europe/Berlin' },
    { label: 'Vienna', value: 'Europe/Vienna' },
    { label: 'Zurich', value: 'Europe/Zurich' },
    { label: 'London', value: 'Europe/London' },
];

const languageOptions: SelectFieldOption[] = [
    { label: 'Deutsch', value: 'de' },
    { label: 'English', value: 'en' },
    { label: 'Français', value: 'fr' },
    { label: 'Español', value: 'es' },
    { label: 'Italiano', value: 'it' },
];

function languageLabel(value: string): string {
    return languageOptions.find((option) => option.value === value)?.label ?? value;
}

const creating = computed(() => props.preferences === null);
const editing = ref(false);

const form = useForm({
    preference_id: props.preferences?.preference_id ?? '',
    timezone: props.preferences?.timezone ?? 'Europe/Berlin',
    sprache: props.preferences?.sprache ?? 'de',
    benachrichtigungseinstellungen_push: props.preferences?.benachrichtigungseinstellungen_push ?? true,
    benachrichtigungseinstellungen_email: props.preferences?.benachrichtigungseinstellungen_email ?? true,
});

function startEditing() {
    editing.value = true;
}

function cancelEditing() {
    form.reset();
    form.clearErrors();
    editing.value = false;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = false) };

    if (creating.value) {
        form.post(route('preferences.store'), options);
    } else {
        form.put(route('preferences.update'), options);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Preferences" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall title="Preferences" description="Your timezone, language, and notification settings" />

                <form @submit.prevent="submit">
                    <SettingsCard
                        title="Preferences"
                        :editing="editing"
                        :creating="creating"
                        :processing="form.processing"
                        @edit="startEditing"
                        @cancel="cancelEditing"
                    >
                        <template #read>
                            <dl v-if="preferences" class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-muted-foreground">Timezone</dt>
                                    <dd>{{ timezoneOptions.find((option) => option.value === preferences?.timezone)?.label ?? preferences.timezone }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Language</dt>
                                    <dd>{{ languageLabel(preferences.sprache) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Push notifications</dt>
                                    <dd>{{ preferences.benachrichtigungseinstellungen_push ? 'Enabled' : 'Disabled' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Email notifications</dt>
                                    <dd>{{ preferences.benachrichtigungseinstellungen_email ? 'Enabled' : 'Disabled' }}</dd>
                                </div>
                            </dl>
                            <p v-else class="text-muted-foreground text-sm">You haven't set your preferences yet.</p>
                        </template>

                        <template #edit>
                            <div class="space-y-6">
                                <InputError :message="form.errors.preferences" />

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        id="timezone"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Timezone"
                                        required
                                        :error="form.errors.timezone"
                                    >
                                        <SelectField
                                            :id="id"
                                            v-model="form.timezone"
                                            :options="timezoneOptions"
                                            :invalid="invalid"
                                            :described-by="describedBy"
                                        />
                                    </FormField>

                                    <FormField
                                        id="sprache"
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Language"
                                        required
                                        :error="form.errors.sprache"
                                    >
                                        <SelectField
                                            :id="id"
                                            v-model="form.sprache"
                                            :options="languageOptions"
                                            :invalid="invalid"
                                            :described-by="describedBy"
                                        />
                                    </FormField>
                                </div>

                                <div class="space-y-3">
                                    <Label for="push" class="flex items-center space-x-3">
                                        <Checkbox id="push" v-model:checked="form.benachrichtigungseinstellungen_push" />
                                        <span>Push notifications</span>
                                    </Label>
                                    <InputError :message="form.errors.benachrichtigungseinstellungen_push" />

                                    <Label for="email_notifications" class="flex items-center space-x-3">
                                        <Checkbox id="email_notifications" v-model:checked="form.benachrichtigungseinstellungen_email" />
                                        <span>Email notifications</span>
                                    </Label>
                                    <InputError :message="form.errors.benachrichtigungseinstellungen_email" />
                                </div>
                            </div>
                        </template>
                    </SettingsCard>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>

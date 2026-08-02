<script setup lang="ts">
/**
 * One dialog for both "invite someone" and "change what an existing member
 * may do" — the fields are identical apart from the email address, and
 * keeping them in one place is what stops the two forms drifting apart.
 */
import FormField from '@/components/form/FormField.vue';
import MemberAccessFields from '@/components/b2b/MemberAccessFields.vue';
import InputError from '@/components/InputError.vue';
import type { SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { B2bMemberAccessFormData, B2bMemberRow, B2bPermissionGroup } from '@/types/b2b';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    mode: 'invite' | 'edit';
    /** The member being edited; ignored in invite mode. */
    member?: B2bMemberRow | null;
    catalog: B2bPermissionGroup[];
    roleOptions: SelectFieldOption[];
    vehicleScopeOptions: SelectFieldOption[];
    canAssignOwner: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const isInvite = computed(() => props.mode === 'invite');

const form = useForm<B2bMemberAccessFormData & { email: string }>({
    email: '',
    role: 'member',
    permissions: ['vehicles.view'],
    vehicle_scope: 'all',
});

const access = computed<B2bMemberAccessFormData>(() => ({
    role: form.role,
    permissions: form.permissions,
    vehicle_scope: form.vehicle_scope,
}));

function applyAccess(next: B2bMemberAccessFormData) {
    form.role = next.role;
    form.permissions = next.permissions;
    form.vehicle_scope = next.vehicle_scope;
}

// Re-seeded whenever the dialog opens, so reopening it after a cancel never
// shows the previous member's rights.
watch(
    () => [props.open, props.member?.user_id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.email = '';
        form.role = props.member?.role ?? 'member';
        form.permissions = props.member ? [...props.member.permissions] : ['vehicles.view'];
        form.vehicle_scope = props.member?.vehicle_scope ?? 'all';
    },
    { immediate: true },
);

const errors = computed(() => form.errors as Record<string, string | undefined>);

function close() {
    emit('update:open', false);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
    };

    if (isInvite.value) {
        form.post(route('b2b.invitations.store'), options);

        return;
    }

    if (props.member) {
        form.transform(({ email, ...data }) => data).patch(route('b2b.members.update', props.member.user_id), options);
    }
}

const title = computed(() => (isInvite.value ? 'Mitglied einladen' : `Berechtigungen: ${props.member?.name || props.member?.email || ''}`));

const description = computed(() =>
    isInvite.value
        ? 'Die eingeladene Person erhält eine E-Mail mit einem Link und tritt mit genau diesen Rechten bei.'
        : 'Änderungen gelten sofort — beim nächsten Seitenaufruf des Mitglieds.',
);
</script>

<template>
    <AppModal :open="open" :title="title" :description="description" :width="720" @update:open="(value) => emit('update:open', value)">
        <form id="member-access-form" novalidate class="space-y-5 px-2 pb-1" @submit.prevent="submit">
            <InputError :message="errors.invitation ?? errors.member" />

            <FormField v-if="isInvite" id="invite_email" v-slot="{ id, describedBy, invalid }" label="E-Mail-Adresse" required :error="errors.email">
                <Input
                    :id="id"
                    v-model="form.email"
                    type="email"
                    maxlength="255"
                    autocomplete="off"
                    placeholder="name@firma.de"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </FormField>

            <div v-else-if="member" class="rounded-xl border border-gray-100 bg-[#f8faf9] px-4 py-3">
                <p class="text-[14px] font-bold text-[#10393b]">{{ member.name || member.email }}</p>
                <p class="text-[12px] text-gray-500">{{ member.email }}</p>
            </div>

            <MemberAccessFields
                :model-value="access"
                :catalog="catalog"
                :role-options="roleOptions"
                :vehicle-scope-options="vehicleScopeOptions"
                :can-assign-owner="canAssignOwner"
                :errors="errors"
                :disabled="form.processing"
                @update:model-value="applyAccess"
            />
        </form>

        <template #footer>
            <AppModalButton type="button" variant="secondary" :disabled="form.processing" @click="close">Abbrechen</AppModalButton>
            <AppModalButton type="submit" :disabled="form.processing" @click="submit">
                {{ isInvite ? 'Einladung senden' : 'Speichern' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

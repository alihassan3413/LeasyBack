<script setup lang="ts">
/**
 * Role + permissions + vehicle scope, shared by the invite dialog and the
 * member editor so both offer exactly the same choices.
 *
 * The permission list is rendered from the server's catalogue rather than a
 * hard-coded list here — adding a case to App\Enums\B2bPermission is enough
 * to make it appear, with no frontend change.
 */
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type {
    B2bMemberAccessFormData,
    B2bPermissionGroup,
    B2bPermissionValue,
    B2bRoleValue,
    B2bVehicleScopeValue,
} from '@/types/b2b';
import { computed, useId } from 'vue';

const props = defineProps<{
    modelValue: B2bMemberAccessFormData;
    catalog: B2bPermissionGroup[];
    roleOptions: SelectFieldOption[];
    vehicleScopeOptions: SelectFieldOption[];
    /** Only an owner may hand out ownership. */
    canAssignOwner: boolean;
    errors?: Record<string, string | undefined>;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: B2bMemberAccessFormData] }>();

const uid = useId();

// An owner holds everything implicitly, so the fine-grained controls are
// meaningless for them — shown disabled rather than hidden, so it's clear
// what changing the role back to "Mitglied" would restore.
const isOwner = computed(() => props.modelValue.role === 'owner');
const controlsDisabled = computed(() => props.disabled || isOwner.value);

const availableRoles = computed(() =>
    props.canAssignOwner ? props.roleOptions : props.roleOptions.filter((option) => option.value !== 'owner'),
);

function patch(changes: Partial<B2bMemberAccessFormData>) {
    emit('update:modelValue', { ...props.modelValue, ...changes });
}

function isChecked(permission: B2bPermissionValue): boolean {
    return isOwner.value || props.modelValue.permissions.includes(permission);
}

/**
 * Mirrors B2bPermissionSet on the server: enabling a permission pulls in what
 * it depends on, and disabling one drops anything that depended on it — so the
 * owner never saves a member who may create vehicles but not see them.
 */
function toggle(permission: B2bPermissionValue, checked: boolean) {
    const selected = new Set(props.modelValue.permissions);

    if (checked) {
        selected.add(permission);
        dependenciesOf(permission).forEach((dependency) => selected.add(dependency));
    } else {
        selected.delete(permission);

        for (const candidate of allPermissions.value) {
            if (dependenciesOf(candidate).includes(permission)) {
                selected.delete(candidate);
            }
        }
    }

    patch({ permissions: allPermissions.value.filter((value) => selected.has(value)) });
}

const allPermissions = computed<B2bPermissionValue[]>(() =>
    props.catalog.flatMap((group) => group.permissions.map((permission) => permission.value)),
);

/** Transitive closure of a permission's prerequisites. */
function dependenciesOf(permission: B2bPermissionValue): B2bPermissionValue[] {
    const catalogEntry = props.catalog.flatMap((group) => group.permissions).find((entry) => entry.value === permission);
    const direct = catalogEntry?.requires ?? [];

    return direct.flatMap((dependency) => [dependency, ...dependenciesOf(dependency)]);
}
</script>

<template>
    <div class="space-y-5">
        <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
            <FormField id="member_role" v-slot="{ id, describedBy, invalid }" label="Rolle" required :error="errors?.role">
                <SelectField
                    :id="id"
                    :model-value="modelValue.role"
                    :options="availableRoles"
                    :disabled="disabled"
                    :invalid="invalid"
                    :described-by="describedBy"
                    @update:model-value="(value) => patch({ role: value as B2bRoleValue })"
                />
            </FormField>

            <FormField
                id="member_vehicle_scope"
                v-slot="{ id, describedBy, invalid }"
                label="Sichtbare Fahrzeuge"
                required
                :error="errors?.vehicle_scope"
            >
                <SelectField
                    :id="id"
                    :model-value="isOwner ? 'all' : modelValue.vehicle_scope"
                    :options="vehicleScopeOptions"
                    :disabled="controlsDisabled"
                    :invalid="invalid"
                    :described-by="describedBy"
                    @update:model-value="(value) => patch({ vehicle_scope: value as B2bVehicleScopeValue })"
                />
            </FormField>
        </div>

        <div>
            <p class="text-[14px] font-bold text-[#10393b]">Berechtigungen</p>
            <p v-if="isOwner" class="mt-1 text-[12px] text-gray-500">
                Inhaber haben immer vollen Zugriff auf alle Bereiche des Unternehmens.
            </p>
            <p v-else class="mt-1 text-[12px] text-gray-500">
                Abhängige Rechte werden automatisch mit aktiviert – wer Fahrzeuge anlegen darf, muss sie auch sehen können.
            </p>

            <p v-if="errors?.permissions" class="text-destructive mt-1 text-sm">{{ errors.permissions }}</p>

            <div class="mt-3 space-y-3">
                <fieldset
                    v-for="group in catalog"
                    :key="group.group"
                    class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm"
                >
                    <legend class="sr-only">{{ group.group }}</legend>

                    <p
                        class="px-4 text-[13px] font-bold text-white"
                        style="background-color: #01b990; line-height: 36px"
                        aria-hidden="true"
                    >
                        {{ group.group }}
                    </p>

                    <div class="space-y-2.5 p-4">
                        <Label
                            v-for="permission in group.permissions"
                            :key="permission.value"
                            :for="`${uid}-${permission.value}`"
                            class="flex cursor-pointer items-start gap-2.5 font-normal"
                            :class="controlsDisabled ? 'cursor-not-allowed opacity-60' : ''"
                        >
                            <Checkbox
                                :id="`${uid}-${permission.value}`"
                                :model-value="isChecked(permission.value)"
                                :disabled="controlsDisabled"
                                class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                                @update:model-value="(checked) => toggle(permission.value, checked === true)"
                            />
                            <span class="min-w-0">
                                <span class="block text-[14px] font-medium text-[#10393b]">{{ permission.label }}</span>
                                <span class="block text-[12px] leading-[1.45] text-gray-500">{{ permission.description }}</span>
                            </span>
                        </Label>
                    </div>
                </fieldset>
            </div>
        </div>
    </div>
</template>

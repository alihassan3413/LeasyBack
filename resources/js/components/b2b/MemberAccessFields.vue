<script setup lang="ts">
/**
 * What a member of the company may do, shared by the invite dialog and the
 * member editor so both offer exactly the same choices.
 *
 * Two ways to say it, in order of how often they are wanted:
 *
 *  1. One of the three named company roles. Picking one sets the role and the
 *     permissions together, which is what the specification asks for and what
 *     almost every company needs.
 *  2. "Rechte einzeln anpassen" — the original per-permission editor, still
 *     rendered from the server's catalogue, so adding a case to
 *     App\Enums\B2bPermission still needs no frontend change. Touching
 *     anything in there drops the preset to "Individuell".
 */
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type {
    B2bMemberAccessFormData,
    B2bPermissionGroup,
    B2bPermissionValue,
    B2bRolePresetOption,
    B2bRolePresetValue,
    B2bRoleValue,
    B2bVehicleScopeValue,
} from '@/types/b2b';
import { computed, ref, useId, watch } from 'vue';
import MdiChevronDown from '~icons/mdi/chevron-down';

const props = defineProps<{
    modelValue: B2bMemberAccessFormData;
    catalog: B2bPermissionGroup[];
    presets: B2bRolePresetOption[];
    roleOptions: SelectFieldOption[];
    vehicleScopeOptions: SelectFieldOption[];
    /** Only an owner may hand out ownership — and so the administrator preset. */
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

const availableRoles = computed(() => (props.canAssignOwner ? props.roleOptions : props.roleOptions.filter((option) => option.value !== 'owner')));

/**
 * Handing out ownership is owner-only, server-side too — an administrator
 * preset offered to a non-owner would only earn them a 403.
 */
const availablePresets = computed(() => props.presets.filter((preset) => props.canAssignOwner || !preset.assigns_owner));

// Opened by default when the member already has a hand-picked set, so nobody
// has to discover a disclosure to see why their rights look unfamiliar.
const advancedOpen = ref(props.modelValue.preset === null);

watch(
    () => props.modelValue.preset,
    (preset, previous) => {
        if (preset === null && previous !== null) {
            advancedOpen.value = true;
        }
    },
);

function patch(changes: Partial<B2bMemberAccessFormData>) {
    emit('update:modelValue', { ...props.modelValue, ...changes });
}

/** Selecting a preset replaces role and permissions together. */
function selectPreset(preset: B2bRolePresetOption) {
    if (props.disabled) {
        return;
    }

    patch({
        preset: preset.value,
        role: preset.role,
        permissions: [...preset.permissions],
    });
}

/**
 * The raw role select. An owner holds every permission (server-side too), so
 * picking "Inhaber" *is* the administrator preset — storing it with a partial
 * list and calling it "Individuell" described rights the person doesn't have.
 *
 * The member's own ticked boxes are left in the model (the server stores the
 * full set for an owner regardless), so switching back to "Mitglied" restores
 * them instead of silently handing out everything.
 */
function selectRole(role: B2bRoleValue) {
    if (role === 'owner') {
        patch({ preset: props.presets.find((preset) => preset.role === 'owner')?.value ?? null, role });

        return;
    }

    patch({ preset: null, role });
}

function isPresetSelected(value: B2bRolePresetValue): boolean {
    return props.modelValue.preset === value;
}

function isChecked(permission: B2bPermissionValue): boolean {
    return isOwner.value || props.modelValue.permissions.includes(permission);
}

/**
 * Mirrors B2bPermissionSet on the server: enabling a permission pulls in what
 * it depends on, and disabling one drops anything that depended on it — so the
 * owner never saves a member who may create vehicles but not see them.
 *
 * Any edit here means the set is no longer one of the named roles, so the
 * preset is cleared and the member is stored (and shown) as "Individuell".
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

    patch({ preset: null, permissions: allPermissions.value.filter((value) => selected.has(value)) });
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
        <!-- ── The named company roles ── -->
        <div>
            <p class="text-[14px] font-bold text-[#10393b]">Rolle im Unternehmen</p>
            <p class="mt-1 text-[12px] text-gray-500">Bestimmt, was diese Person im Unternehmenskonto tun darf.</p>

            <p v-if="errors?.preset" class="text-destructive mt-1 text-sm">{{ errors.preset }}</p>

            <div class="mt-3 space-y-2">
                <Label
                    v-for="preset in availablePresets"
                    :key="preset.value"
                    :for="`${uid}-preset-${preset.value}`"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 font-normal transition-colors"
                    :class="[
                        isPresetSelected(preset.value) ? 'border-[#01b990] bg-[#01b990]/[0.06]' : 'border-gray-200 hover:border-[#01b990]/60',
                        disabled ? 'cursor-not-allowed opacity-60' : '',
                    ]"
                >
                    <input
                        :id="`${uid}-preset-${preset.value}`"
                        type="radio"
                        class="sr-only"
                        :name="`${uid}-preset`"
                        :value="preset.value"
                        :checked="isPresetSelected(preset.value)"
                        :disabled="disabled"
                        @change="selectPreset(preset)"
                    />

                    <span
                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border"
                        :class="isPresetSelected(preset.value) ? 'border-[#01b990] bg-[#01b990]' : 'border-gray-300'"
                        aria-hidden="true"
                    >
                        <span v-if="isPresetSelected(preset.value)" class="size-1.5 rounded-full bg-white" />
                    </span>

                    <span class="min-w-0">
                        <span class="block text-[14px] font-medium text-[#10393b]">{{ preset.label }}</span>
                        <span class="block text-[12px] leading-[1.45] text-gray-500">{{ preset.description }}</span>
                    </span>
                </Label>

                <p
                    v-if="modelValue.preset === null"
                    class="rounded-xl border border-dashed border-gray-200 px-4 py-3 text-[12px] leading-[1.45] text-gray-500"
                >
                    Für dieses Mitglied sind die Rechte einzeln vergeben („Individuell“). Wählen Sie oben eine Rolle, um die Standardrechte zu
                    übernehmen.
                </p>
            </div>
        </div>

        <!-- ── The original editor, unchanged, one disclosure down ── -->
        <div class="rounded-xl border border-gray-100">
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
                :aria-expanded="advancedOpen"
                @click="advancedOpen = !advancedOpen"
            >
                <span>
                    <span class="block text-[14px] font-bold text-[#10393b]">Rechte einzeln anpassen</span>
                    <span class="block text-[12px] text-gray-500">Sichtbare Fahrzeuge und einzelne Berechtigungen.</span>
                </span>
                <MdiChevronDown class="size-5 shrink-0 text-gray-400 transition-transform" :class="advancedOpen ? 'rotate-180' : ''" />
            </button>

            <div v-if="advancedOpen" class="space-y-5 border-t border-gray-100 p-4">
                <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
                    <FormField id="member_role" v-slot="{ id, describedBy, invalid }" label="Rolle" required :error="errors?.role">
                        <SelectField
                            :id="id"
                            :model-value="modelValue.role"
                            :options="availableRoles"
                            :disabled="disabled"
                            :invalid="invalid"
                            :described-by="describedBy"
                            @update:model-value="(value) => selectRole(value as B2bRoleValue)"
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
                        Unternehmens-Administratoren haben immer vollen Zugriff auf alle Bereiche des Unternehmens.
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

                            <p class="px-4 text-[13px] font-bold text-white" style="background-color: #01b990; line-height: 36px" aria-hidden="true">
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
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * "Fahrzeug anlegen" from a customer's own detail page — the owner
 * (vehicle_belongs + b2c_user_id/b2b_id) is inferred entirely from the
 * page's already-known context (type/ownerId props), never exposed as
 * admin-facing inputs. Avoids needing a separate owner-search picker: the
 * admin is already looking at the exact customer they want to create a
 * vehicle for. Reuses the same field set as the B2C self-service
 * AddVehicleModal.vue (LicensePlateInput, brand SearchableSelectField, the
 * "ich weiß es nicht" pattern) — StoreVehicleRequest already validates
 * b2b_id/b2c_user_id are real records (see Checkpoint 10 decisions).
 */
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import FormField from '@/components/form/FormField.vue';
import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import SearchableSelectField from '@/components/form/SearchableSelectField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { VEHICLE_BRAND_OPTIONS } from '@/lib/vehicleBrands';
import type { AdminCustomerType } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { ref, useId, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    type: AdminCustomerType;
    ownerId: string;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const uid = useId();
const leasingEndUnknownId = `${uid}-leasing-end-unknown`;
const leasinggeberUnknownId = `${uid}-leasinggeber-unknown`;

const leasingEndUnknown = ref(false);
const leasinggeberUnknown = ref(false);

const form = useForm({
    license_plate: '',
    make: '',
    model: '',
    vin: '',
    leasing_end_date: '',
    leasinggeber: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.reset();
        form.clearErrors();
        leasingEndUnknown.value = false;
        leasinggeberUnknown.value = false;
    },
);

watch(leasingEndUnknown, (unknown) => {
    if (unknown) {
        form.leasing_end_date = '';
    }
});

watch(leasinggeberUnknown, (unknown) => {
    if (unknown) {
        form.leasinggeber = '';
    }
});

function close() {
    emit('update:open', false);
}

function submit() {
    form.transform((data) => ({
        license_plate: data.license_plate,
        make: data.make || null,
        model: data.model || null,
        vin: data.vin || null,
        leasing_end_date: leasingEndUnknown.value ? null : data.leasing_end_date || null,
        leasinggeber: leasinggeberUnknown.value ? null : data.leasinggeber || null,
        vehicle_belongs: props.type === 'b2b' ? 'B2B' : 'B2C',
        b2b_id: props.type === 'b2b' ? props.ownerId : null,
        b2c_user_id: props.type === 'b2c' ? props.ownerId : null,
    })).post(route('admin.vehicles.store'), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal
        :open="open"
        title="Fahrzeug anlegen"
        description="Erfassen Sie ein Fahrzeug für diesen Kunden."
        @update:open="(value) => emit('update:open', value)"
    >
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 px-2 md:grid-cols-2">
                <LicensePlateInput v-model="form.license_plate" :server-error="form.errors.license_plate" />

                <FormField
                    v-slot="{ id, describedBy, invalid }"
                    label="FIN"
                    label-hint="* (siehe Fahrzeugschein – Feld E)"
                    :error="form.errors.vin"
                >
                    <Input
                        :id="id"
                        v-model="form.vin"
                        maxlength="17"
                        placeholder="FIN eingeben"
                        class="uppercase"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>

                <FormField v-slot="{ id, describedBy, invalid }" label="Marke" :error="form.errors.make">
                    <SearchableSelectField
                        :id="id"
                        v-model="form.make"
                        :options="VEHICLE_BRAND_OPTIONS"
                        placeholder="Marke wählen"
                        search-placeholder="Marke suchen..."
                        empty-label="Keine Marke gefunden"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <FormField v-slot="{ id, describedBy, invalid }" label="Modell" :error="form.errors.model">
                    <Input :id="id" v-model="form.model" placeholder="Modell eingeben" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <div>
                    <FormField v-slot="{ id, describedBy, invalid }" label="Leasingende" :error="form.errors.leasing_end_date">
                        <CalendarDateField
                            :id="id"
                            v-model="form.leasing_end_date"
                            allow-past
                            :disabled="leasingEndUnknown"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>
                    <Label :for="leasingEndUnknownId" class="mt-1.5 flex cursor-pointer items-start gap-2 font-normal">
                        <Checkbox
                            :id="leasingEndUnknownId"
                            v-model="leasingEndUnknown"
                            class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                        />
                        <span class="text-xs leading-[1.45] font-normal text-[#00000099]">
                            Das genaue Datum des Leasingendes liegt mir aktuell nicht vor. Ich werde Ihnen diese Information zeitnah nachreichen.
                        </span>
                    </Label>
                </div>

                <div>
                    <FormField
                        v-slot="{ id, describedBy, invalid }"
                        label="Leasinggeber"
                        label-hint="*"
                        :error="form.errors.leasinggeber"
                    >
                        <Input
                            :id="id"
                            v-model="form.leasinggeber"
                            placeholder="Leasinggeber eingeben"
                            :disabled="leasinggeberUnknown"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                    <Label :for="leasinggeberUnknownId" class="mt-1.5 flex cursor-pointer items-start gap-2 font-normal">
                        <Checkbox
                            :id="leasinggeberUnknownId"
                            v-model="leasinggeberUnknown"
                            class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                        />
                        <span class="text-xs leading-[1.45] font-normal text-[#00000099]">
                            Der Name des Leasinggebers liegt mir aktuell nicht vor. Ich werde Ihnen diese Information zeitnah nachreichen.
                        </span>
                    </Label>
                </div>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Wird gespeichert...' : 'Fahrzeug anlegen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

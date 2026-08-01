<script setup lang="ts">
/**
 * "Fahrzeug anlegen" from a customer's own detail page — the owner
 * (vehicle_belongs + b2c_user_id/b2b_id) is inferred entirely from the
 * page's already-known context (type/ownerId props), never exposed as
 * admin-facing inputs. Avoids needing a separate owner-search picker: the
 * admin is already looking at the exact customer they want to create a
 * vehicle for. Reuses the same field set as the B2C self-service
 * AddVehicleModal.vue (LicensePlateInput, brand SelectField, the
 * "ich weiß es nicht" pattern) — StoreVehicleRequest already validates
 * b2b_id/b2c_user_id are real records (see Checkpoint 10 decisions).
 */
import FormField from '@/components/form/FormField.vue';
import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import SelectField from '@/components/form/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { VEHICLE_BRAND_OPTIONS } from '@/lib/vehicleBrands';
import type { AdminCustomerType } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    type: AdminCustomerType;
    ownerId: string;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const leasingEndUnknown = ref(true);
const leasinggeberUnknown = ref(true);

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
        leasingEndUnknown.value = true;
        leasinggeberUnknown.value = true;
    },
);

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
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogScrollContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Fahrzeug anlegen</DialogTitle>
                    <DialogDescription>Erfassen Sie ein Fahrzeug für diesen Kunden.</DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <FormField label="Kennzeichen" required :error="form.errors.license_plate">
                        <LicensePlateInput v-model="form.license_plate" />
                    </FormField>

                    <FormField id="make" v-slot="{ id, describedBy, invalid }" label="Marke" :error="form.errors.make">
                        <SelectField
                            :id="id"
                            v-model="form.make"
                            :options="VEHICLE_BRAND_OPTIONS"
                            placeholder="Marke wählen"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>

                    <FormField id="model" v-slot="{ id, describedBy, invalid }" label="Modell" :error="form.errors.model">
                        <Input :id="id" v-model="form.model" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>

                    <FormField
                        id="vin"
                        v-slot="{ id, describedBy, invalid }"
                        label="Fahrgestellnummer (VIN)"
                        hint="Genau 17 Zeichen."
                        :error="form.errors.vin"
                    >
                        <Input :id="id" v-model="form.vin" maxlength="17" class="uppercase" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>

                    <div class="space-y-2">
                        <FormField
                            id="leasing_end_date"
                            v-slot="{ id, describedBy, invalid }"
                            label="Leasingende"
                            :error="form.errors.leasing_end_date"
                        >
                            <Input
                                :id="id"
                                v-model="form.leasing_end_date"
                                type="date"
                                :disabled="leasingEndUnknown"
                                :aria-invalid="invalid"
                                :aria-describedby="describedBy"
                            />
                        </FormField>
                        <Label for="leasing_end_unknown" class="flex items-center space-x-2 text-sm font-normal">
                            <Checkbox id="leasing_end_unknown" v-model:checked="leasingEndUnknown" />
                            <span>Ich weiß es nicht</span>
                        </Label>
                    </div>

                    <div class="space-y-2">
                        <FormField id="leasinggeber" v-slot="{ id, describedBy, invalid }" label="Leasinggeber" :error="form.errors.leasinggeber">
                            <Input
                                :id="id"
                                v-model="form.leasinggeber"
                                :disabled="leasinggeberUnknown"
                                :aria-invalid="invalid"
                                :aria-describedby="describedBy"
                            />
                        </FormField>
                        <Label for="leasinggeber_unknown" class="flex items-center space-x-2 text-sm font-normal">
                            <Checkbox id="leasinggeber_unknown" v-model:checked="leasinggeberUnknown" />
                            <span>Ich weiß es nicht</span>
                        </Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">Abbrechen</Button>
                    <Button type="submit" :loading="form.processing">Fahrzeug anlegen</Button>
                </DialogFooter>
            </form>
        </DialogScrollContent>
    </Dialog>
</template>

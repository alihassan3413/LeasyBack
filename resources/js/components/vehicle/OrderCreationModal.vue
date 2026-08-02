<script setup lang="ts">
/**
 * Books an inspection appointment ("Vorgang starten"). The provider isn't
 * chosen explicitly — it's derived server-side from the selected station,
 * matching leasyback_web's OrderCreationModal. The fee-acknowledgement gate
 * (a separate OrderFeeConfirmModal.vue in the legacy frontend) is folded
 * into this single modal instead: nothing about it needs its own dialog.
 */
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { StationData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    vehicleId: string;
    stations: StationData[];
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const stationOptions = computed<SelectFieldOption[]>(() =>
    props.stations.map((station) => ({
        value: station.station_id,
        label: `${station.name} · ${station.ort} (${station.provider.toUpperCase()})`,
    })),
);

const form = useForm({
    station_id: '',
    date: '',
    time: '',
    remarks: '',
    fee_acknowledged: false,
});

const canSubmit = computed(() => form.station_id !== '' && form.date !== '' && form.time !== '' && form.fee_acknowledged);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.reset();
        form.clearErrors();
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    form.transform((data) => ({
        station_id: data.station_id,
        termin: `${data.date}T${data.time}:00`,
        remarks: data.remarks || null,
    })).post(route('orders.store', props.vehicleId), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal
        :open="open"
        title="Vorgang starten"
        description="Buchen Sie einen Begutachtungstermin für dieses Fahrzeug."
        @update:open="(value) => emit('update:open', value)"
    >
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 px-2">
                <FormField id="station_id" v-slot="{ id, describedBy, invalid }" label="Prüfstation" required :error="form.errors.station_id">
                    <SelectField
                        :id="id"
                        v-model="form.station_id"
                        :options="stationOptions"
                        placeholder="Station wählen"
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </FormField>

                <div class="grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
                    <FormField id="date" v-slot="{ id, describedBy, invalid }" label="Datum" required :error="form.errors.termin">
                        <CalendarDateField
                            :id="id"
                            v-model="form.date"
                            :min-days-ahead="3"
                            block-weekends
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>
                    <FormField id="time" v-slot="{ id }" label="Uhrzeit" required>
                        <Input :id="id" v-model="form.time" type="time" />
                    </FormField>
                </div>

                <FormField id="remarks" v-slot="{ id, describedBy, invalid }" label="Bemerkungen" :error="form.errors.remarks">
                    <Input :id="id" v-model="form.remarks" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>

                <div class="rounded-2xl bg-gray-50 p-4 text-sm">
                    <p class="text-[#00000080]">
                        Mit dem Buchen eines Termins starten Sie den Leasyback-Prozess. Wird der Prozess nicht abgeschlossen, kann eine
                        Bearbeitungsgebühr anfallen.
                    </p>
                    <Label for="fee_acknowledged" class="mt-3 flex items-start space-x-2 text-sm font-normal text-black">
                        <Checkbox id="fee_acknowledged" v-model="form.fee_acknowledged" class="mt-0.5" />
                        <span>Ich habe die Information gelesen und akzeptiere die Bearbeitungsgebühr bei Nichtabschluss des Prozesses.</span>
                    </Label>
                </div>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="!canSubmit || form.processing" @click="submit">
                {{ form.processing ? 'Wird gebucht...' : 'Vorgang starten' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>

<script setup lang="ts">
/**
 * Books an inspection appointment ("Vorgang starten"). The provider isn't
 * chosen explicitly — it's derived server-side from the selected station,
 * matching leasyback_web's OrderCreationModal. The fee-acknowledgement gate
 * (a separate OrderFeeConfirmModal.vue in the legacy frontend) is folded
 * into this single modal instead: nothing about it needs its own dialog.
 */
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

// Bookings need at least a few days' lead time — matches the spirit of
// leasyback_web's CalendarDateField minimum, without porting its full
// weekend-blocking/holiday logic.
const minDate = computed(() => {
    const date = new Date();
    date.setDate(date.getDate() + 3);
    return date.toISOString().slice(0, 10);
});

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
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Vorgang starten</DialogTitle>
                    <DialogDescription>Buchen Sie einen Begutachtungstermin für dieses Fahrzeug.</DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
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

                    <div class="grid grid-cols-2 gap-4">
                        <FormField id="date" v-slot="{ id, describedBy, invalid }" label="Datum" required :error="form.errors.termin">
                            <Input :id="id" v-model="form.date" type="date" :min="minDate" :aria-invalid="invalid" :aria-describedby="describedBy" />
                        </FormField>
                        <FormField id="time" v-slot="{ id }" label="Uhrzeit" required>
                            <Input :id="id" v-model="form.time" type="time" />
                        </FormField>
                    </div>

                    <FormField id="remarks" v-slot="{ id, describedBy, invalid }" label="Bemerkungen" :error="form.errors.remarks">
                        <Input :id="id" v-model="form.remarks" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>

                    <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p class="text-amber-900 dark:text-amber-400">
                            Mit dem Buchen eines Termins starten Sie den Leasyback-Prozess. Wird der Prozess nicht abgeschlossen, kann eine
                            Bearbeitungsgebühr anfallen.
                        </p>
                        <Label for="fee_acknowledged" class="mt-3 flex items-start space-x-2 text-sm font-normal">
                            <Checkbox id="fee_acknowledged" v-model:checked="form.fee_acknowledged" class="mt-0.5" />
                            <span>Ich habe die Information gelesen und akzeptiere die Bearbeitungsgebühr bei Nichtabschluss des Prozesses.</span>
                        </Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">Abbrechen</Button>
                    <Button type="submit" :disabled="!canSubmit" :loading="form.processing">Vorgang starten</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

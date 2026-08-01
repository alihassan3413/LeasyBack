<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FormField from '@/components/form/FormField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { StationData } from '@/types/order';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface OnboardingOrder {
    auftragsnummer: string;
    order_status: string;
}

const props = defineProps<{ order: OnboardingOrder | null; stations: StationData[] }>();
const emit = defineEmits<{ back: []; booked: [] }>();

const stationOptions = computed<SelectFieldOption[]>(() =>
    props.stations.map((station) => ({
        value: station.station_id,
        label: `${station.name} · ${station.ort} (${station.provider.toUpperCase()})`,
    })),
);

// Matches OrderCreationModal's own minimum lead time on the dashboard.
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

function submit() {
    form.transform((data) => ({
        station_id: data.station_id,
        termin: `${data.date}T${data.time}:00`,
        remarks: data.remarks || null,
    })).post(route('onboarding.appointment.store'), {
        preserveScroll: true,
        onSuccess: () => emit('booked'),
    });
}

function goToDashboard() {
    router.visit(route('dashboard'));
}
</script>

<template>
    <OnboardingCard title="Terminvereinbarung" description="Buchen Sie einen Begutachtungstermin für Ihr Fahrzeug.">
        <template v-if="order">
            <dl class="text-sm">
                <dt class="text-muted-foreground">Vorgang</dt>
                <dd class="mt-1 flex items-center gap-2 font-medium">
                    {{ order.auftragsnummer }}
                    <Badge variant="secondary">{{ order.order_status }}</Badge>
                </dd>
            </dl>

            <div class="mt-6 flex items-center justify-between border-t pt-5">
                <Button type="button" variant="ghost" @click="emit('back')">Zurück</Button>
                <Button type="button" @click="goToDashboard">Zum Dashboard</Button>
            </div>
        </template>

        <form v-else novalidate class="space-y-5" @submit.prevent="submit">
            <InputError :message="form.errors.appointment" />

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
                    Mit dem Buchen eines Termins starten Sie den Leasyback-Prozess. Wird der Prozess nicht abgeschlossen, kann eine Bearbeitungsgebühr
                    anfallen.
                </p>
                <Label for="fee_acknowledged" class="mt-3 flex items-start space-x-2 text-sm font-normal">
                    <Checkbox id="fee_acknowledged" v-model:checked="form.fee_acknowledged" class="mt-0.5" />
                    <span>Ich habe die Information gelesen und akzeptiere die Bearbeitungsgebühr bei Nichtabschluss des Prozesses.</span>
                </Label>
            </div>

            <div class="flex items-center justify-between gap-3 border-t pt-5">
                <Button type="button" variant="ghost" @click="emit('back')">Zurück</Button>
                <Button type="submit" :disabled="!canSubmit" :loading="form.processing">Termin buchen</Button>
            </div>
        </form>
    </OnboardingCard>
</template>

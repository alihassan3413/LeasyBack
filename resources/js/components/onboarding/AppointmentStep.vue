<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import FormField from '@/components/form/FormField.vue';
import StationMap from '@/components/form/StationMap.vue';
import StationSelectField from '@/components/form/StationSelectField.vue';
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

const form = useForm({
    station_id: '',
    date: '',
    time: '',
    remarks: '',
    fee_acknowledged: false,
});

const canSubmit = computed(() => form.station_id !== '' && form.date !== '' && form.time !== '' && form.fee_acknowledged);

const selectedStation = computed(() => props.stations.find((station) => station.station_id === form.station_id) ?? null);

function submit() {
    form.transform((data) => ({
        station_id: data.station_id,
        termin: `${data.date}T${data.time}:00`,
        remarks: data.remarks || null,
    })).post(route('onboarding.appointment.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('booked'),
    });
}

function goToDashboard() {
    router.visit(route('dashboard'));
}

const backButtonClass = 'bg-brand-orange hover:bg-brand-orange/90 rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none';
const forwardButtonClass = 'bg-brand-green hover:bg-brand-green/90 rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none';
</script>

<template>
    <div v-if="order" class="space-y-6">
        <OnboardingCard title="Terminvereinbarung" description="Buchen Sie einen Begutachtungstermin für Ihr Fahrzeug.">
            <dl class="text-sm">
                <dt class="text-muted-foreground">Vorgang</dt>
                <dd class="mt-1 flex items-center gap-2 font-medium">
                    {{ order.auftragsnummer }}
                    <Badge variant="secondary">{{ order.order_status }}</Badge>
                </dd>
            </dl>

            <div class="mt-6 flex items-center justify-between border-t pt-5">
                <Button type="button" :class="backButtonClass" @click="emit('back')">Abbrechen</Button>
                <Button type="button" :class="forwardButtonClass" @click="goToDashboard">Zum Dashboard</Button>
            </div>
        </OnboardingCard>
    </div>

    <form v-else novalidate class="space-y-6" @submit.prevent="submit">
        <OnboardingCard title="Suchen Sie Ihre Prüfstation aus">
            <InputError :message="form.errors.appointment" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-4">
                    <FormField id="station_id" v-slot="{ id, describedBy, invalid }" label="Prüfstation" required :error="form.errors.station_id">
                        <StationSelectField
                            :id="id"
                            v-model="form.station_id"
                            :stations="stations"
                            placeholder="Station wählen"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </FormField>

                    <div v-if="selectedStation">
                        <p class="text-sm font-semibold text-black">{{ selectedStation.name }}</p>
                        <p class="text-xs text-[#00000080]">
                            {{ selectedStation.strasse }}, {{ selectedStation.plz }} {{ selectedStation.ort }}
                        </p>
                    </div>
                </div>

                <div class="h-[220px] w-full sm:h-full sm:min-h-[220px]">
                    <StationMap :station="selectedStation" />
                </div>
            </div>
        </OnboardingCard>

        <OnboardingCard title="Hier können Sie Termine buchen">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField id="date" v-slot="{ id, describedBy, invalid }" label="Datum" required :error="form.errors.termin">
                    <CalendarDateField :id="id" v-model="form.date" :min-days-ahead="3" :invalid="invalid" :described-by="describedBy" />
                </FormField>
                <FormField id="time" v-slot="{ id }" label="Uhrzeit" required>
                    <Input :id="id" v-model="form.time" type="time" />
                </FormField>
            </div>

            <FormField id="remarks" v-slot="{ id, describedBy, invalid }" label="Bemerkungen" class="mt-4" :error="form.errors.remarks">
                <Input :id="id" v-model="form.remarks" :aria-invalid="invalid" :aria-describedby="describedBy" />
            </FormField>

            <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                <p class="text-amber-900 dark:text-amber-400">
                    Mit dem Buchen eines Termins starten Sie den Leasyback-Prozess. Wird der Prozess nicht abgeschlossen, kann eine Bearbeitungsgebühr
                    anfallen.
                </p>
                <Label for="fee_acknowledged" class="mt-3 flex items-start space-x-2 text-sm font-normal">
                    <Checkbox id="fee_acknowledged" v-model="form.fee_acknowledged" class="mt-0.5" />
                    <span>Ich habe die Information gelesen und akzeptiere die Bearbeitungsgebühr bei Nichtabschluss des Prozesses.</span>
                </Label>
            </div>

            <div class="mt-5 flex flex-col-reverse items-center gap-3 border-t pt-5 sm:flex-row sm:justify-end">
                <Button type="button" :class="[backButtonClass, 'w-full sm:w-auto']" @click="emit('back')">Abbrechen</Button>
                <Button type="submit" :disabled="!canSubmit" :loading="form.processing" :class="[forwardButtonClass, 'w-full sm:w-auto']">
                    Termin buchen
                </Button>
            </div>
        </OnboardingCard>
    </form>
</template>

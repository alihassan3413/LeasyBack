<script setup lang="ts">
import AppointmentStep, { type OnboardingOrder } from '@/components/onboarding/AppointmentStep.vue';
import OnboardingCard from '@/components/onboarding/OnboardingCard.vue';
import ProfileStep from '@/components/onboarding/ProfileStep.vue';
import VehicleStep, { type OnboardingVehicle } from '@/components/onboarding/VehicleStep.vue';
import PaymentMethodStep from '@/components/payment/PaymentMethodStep.vue';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import B2cRegistrationLayout from '@/layouts/onboarding/B2cRegistrationLayout.vue';
import type { StationData } from '@/types/order';
import type { UserProfileData } from '@/types/profile';
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    profile: UserProfileData | null;
    vehicle: OnboardingVehicle | null;
    order: OnboardingOrder | null;
    stations: StationData[];
}>();

const stepTitles = ['Kundendaten', 'Fahrzeugdaten', 'Terminvereinbarung', 'Zahlungsmethode'];

function resolveStep(): number {
    if (!props.profile?.address) {
        return 1;
    }
    if (!props.vehicle) {
        return 2;
    }
    // The order exists, so the appointment is booked and only the payment
    // method is outstanding. PaymentMethodStep skips itself when one is
    // already saved, so re-entering here is safe.
    if (!props.order) {
        return 3;
    }
    return 4;
}

const currentStep = ref(resolveStep());
const showSuccess = ref(false);

function goToDashboard() {
    router.visit(route('dashboard'));
}
</script>

<template>
    <Head title="Registrierung abschließen" />

    <B2cRegistrationLayout :title="stepTitles[currentStep - 1]" :current-step="currentStep" :total-steps="stepTitles.length">
        <ProfileStep v-if="currentStep === 1" :profile="profile" @next="currentStep = 2" />

        <VehicleStep v-if="currentStep === 2" :vehicle="vehicle" @next="currentStep = 3" @back="currentStep = 1" />

        <AppointmentStep v-if="currentStep === 3" :order="order" :stations="stations" @back="currentStep = 2" @booked="currentStep = 4" />

        <OnboardingCard
            v-if="currentStep === 4 && order"
            title="Zahlungsmethode hinterlegen"
            description="Zum Abschluss hinterlegen Sie bitte eine Zahlungsmethode als Sicherheit für den Prozess."
        >
            <PaymentMethodStep :order-id="order.id" @complete="showSuccess = true" />
        </OnboardingCard>
    </B2cRegistrationLayout>

    <AppModal
        :open="showSuccess"
        title="Vielen Dank!"
        description="Ihre Registrierung war erfolgreich. Sie werden zum Dashboard weitergeleitet."
        :width="620"
        @update:open="(open) => !open && goToDashboard()"
    >
        <div class="flex justify-center px-2">
            <CheckCircle2 class="text-brand-green size-12" aria-hidden="true" />
        </div>

        <template #footer>
            <AppModalButton @click="goToDashboard">Zum Dashboard</AppModalButton>
        </template>
    </AppModal>
</template>

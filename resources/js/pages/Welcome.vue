<script setup lang="ts">
import AudienceSection from '@/components/landing/AudienceSection.vue';
import CtaSection from '@/components/landing/CtaSection.vue';
import FaqSection from '@/components/landing/FaqSection.vue';
import FeaturesSection from '@/components/landing/FeaturesSection.vue';
import HeroSection from '@/components/landing/HeroSection.vue';
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHeader from '@/components/landing/LandingHeader.vue';
import ProcessSection from '@/components/landing/ProcessSection.vue';
import TestimonialsSection from '@/components/landing/TestimonialsSection.vue';
import TrustBar from '@/components/landing/TrustBar.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Leasingrückgabe ohne böse Überraschung">
        <meta
            name="description"
            content="Kostenloses Minderwert-Gutachten, günstige Reparaturangebote und bis zu 42 % Ersparnis bei der Leasingrückgabe — für Privatkunden, Flotten und Werkstätten."
        />
        <link rel="preconnect" href="https://fonts.bunny.net" />
    </Head>

    <div class="text-brand-black min-h-screen bg-[#FAFAFA] antialiased">
        <LandingHeader />

        <main>
            <HeroSection />
            <TrustBar />
            <FeaturesSection />
            <ProcessSection />
            <AudienceSection />
            <TestimonialsSection />
            <FaqSection />
            <CtaSection />
        </main>

        <LandingFooter />
    </div>
</template>

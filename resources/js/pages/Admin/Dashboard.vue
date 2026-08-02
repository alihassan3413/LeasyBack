<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AdminSummaryData } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { AlertCircle, Building2, Car, CheckCircle2, Clock, FileText, User, Users } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = defineProps<{ summary: AdminSummaryData }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin', href: route('admin.dashboard') }];

type Accent = 'teal' | 'green' | 'orange';

const accentStyles: Record<Accent, { fg: string; bg: string }> = {
    teal: { fg: '#10393b', bg: 'rgba(16, 57, 59, 0.08)' },
    green: { fg: '#01875e', bg: 'rgba(1, 185, 144, 0.12)' },
    orange: { fg: '#c0622e', bg: 'rgba(239, 132, 80, 0.14)' },
};

interface StatDef {
    label: string;
    description: string;
    value: keyof AdminSummaryData;
    icon: Component;
    accent: Accent;
}

const customerStats: StatDef[] = [
    { label: 'B2C-Kunden', description: 'Privatkunden gesamt', value: 'total_b2c_customers', icon: User, accent: 'teal' },
    { label: 'B2B-Nutzer', description: 'Firmenkunden gesamt', value: 'total_b2b_users', icon: Users, accent: 'teal' },
    { label: 'B2B-Unternehmen', description: 'Firmen gesamt', value: 'total_b2b_companies', icon: Building2, accent: 'teal' },
];

const operationsStats: StatDef[] = [
    { label: 'Fahrzeuge', description: 'Fahrzeuge gesamt', value: 'total_vehicles', icon: Car, accent: 'green' },
    { label: 'Aufträge', description: 'Aufträge gesamt', value: 'total_orders', icon: FileText, accent: 'green' },
    { label: 'Aktive Aufträge', description: 'In Bearbeitung', value: 'active_orders', icon: Clock, accent: 'orange' },
    { label: 'Abgeschlossene Aufträge', description: 'Ausgeliefert', value: 'delivered_orders', icon: CheckCircle2, accent: 'green' },
    { label: 'Ausstehende Prüfungen', description: 'Bestellt oder bestätigt', value: 'pending_inspections', icon: AlertCircle, accent: 'orange' },
];

const numberFormat = new Intl.NumberFormat('de-DE');

function formatValue(value: number | string): string {
    const numeric = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(numeric) ? numberFormat.format(numeric) : String(value);
}

const totalCustomers = computed(() => {
    const b2c = Number(props.summary.total_b2c_customers) || 0;
    const b2b = Number(props.summary.total_b2b_companies) || 0;
    return numberFormat.format(b2c + b2b);
});
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <header class="rounded-[24px] border border-[#e8f1ef] bg-white p-6 shadow-[0_8px_30px_rgba(16,57,59,0.08)] md:p-8">
                <p class="text-[12px] font-bold tracking-[0.2em] text-[#01b990] uppercase">Admin Übersicht</p>
                <h1 class="mt-2 text-[28px] font-extrabold text-[#10393b] md:text-[32px]">Flotten- und Kundenübersicht</h1>
                <p class="mt-2 max-w-2xl text-sm text-[#5a6e6c] md:text-base">
                    Live-Zahlen zu Kunden, Fahrzeugen und Aufträgen auf einen Blick.
                </p>
            </header>

            <section>
                <p class="mb-3 px-1 text-[13px] font-semibold tracking-[0.16em] text-[#5a6e6c] uppercase">
                    Kunden <span class="font-normal text-[#9fb0af]">· {{ totalCustomers }} gesamt</span>
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="stat in customerStats"
                        :key="stat.value"
                        class="group rounded-[24px] border border-[#eef3f2] bg-white p-6 shadow-[0_10px_28px_rgba(16,57,59,0.06)] transition-all duration-200 hover:border-[#01b990]/50 hover:shadow-[0_12px_28px_rgba(16,57,59,0.1)]"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-[12px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">{{ stat.label }}</p>
                            <span
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px]"
                                :style="{ backgroundColor: accentStyles[stat.accent].bg, color: accentStyles[stat.accent].fg }"
                            >
                                <component :is="stat.icon" :size="20" :stroke-width="2" />
                            </span>
                        </div>
                        <div class="mt-5 text-[34px] leading-none font-extrabold text-[#10393b]">
                            {{ formatValue(props.summary[stat.value]) }}
                        </div>
                        <p class="mt-2 text-sm text-[#6f8585]">{{ stat.description }}</p>
                    </div>
                </div>
            </section>

            <section>
                <p class="mb-3 px-1 text-[13px] font-semibold tracking-[0.16em] text-[#5a6e6c] uppercase">Fuhrpark &amp; Aufträge</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    <div
                        v-for="stat in operationsStats"
                        :key="stat.value"
                        class="group rounded-[24px] border border-[#eef3f2] bg-white p-6 shadow-[0_10px_28px_rgba(16,57,59,0.06)] transition-all duration-200 hover:border-[#01b990]/50 hover:shadow-[0_12px_28px_rgba(16,57,59,0.1)]"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-[12px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">{{ stat.label }}</p>
                            <span
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px]"
                                :style="{ backgroundColor: accentStyles[stat.accent].bg, color: accentStyles[stat.accent].fg }"
                            >
                                <component :is="stat.icon" :size="20" :stroke-width="2" />
                            </span>
                        </div>
                        <div class="mt-5 text-[34px] leading-none font-extrabold text-[#10393b]">
                            {{ formatValue(props.summary[stat.value]) }}
                        </div>
                        <p class="mt-2 text-sm text-[#6f8585]">{{ stat.description }}</p>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

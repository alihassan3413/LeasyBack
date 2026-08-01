<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AdminSummaryData } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const props = defineProps<{ summary: AdminSummaryData }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin', href: route('admin.dashboard') }];

const stats: { label: string; description: string; value: keyof AdminSummaryData }[] = [
    { label: 'B2C-Kunden', description: 'Privatkunden gesamt', value: 'total_b2c_customers' },
    { label: 'B2B-Nutzer', description: 'Firmenkunden gesamt', value: 'total_b2b_users' },
    { label: 'B2B-Unternehmen', description: 'Firmen gesamt', value: 'total_b2b_companies' },
    { label: 'Fahrzeuge', description: 'Fahrzeuge gesamt', value: 'total_vehicles' },
    { label: 'Aufträge', description: 'Aufträge gesamt', value: 'total_orders' },
    { label: 'Aktive Aufträge', description: 'In Bearbeitung', value: 'active_orders' },
    { label: 'Abgeschlossene Aufträge', description: 'Ausgeliefert', value: 'delivered_orders' },
    { label: 'Ausstehende Prüfungen', description: 'Bestellt oder bestätigt', value: 'pending_inspections' },
];
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Admin Dashboard</h1>
                <p class="text-muted-foreground text-sm">Überblick über Kunden, Fahrzeuge und Aufträge.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card v-for="stat in stats" :key="stat.value">
                    <CardHeader class="pb-2">
                        <CardDescription>{{ stat.description }}</CardDescription>
                        <CardTitle class="text-2xl">{{ props.summary[stat.value] }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-muted-foreground text-sm">{{ stat.label }}</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>

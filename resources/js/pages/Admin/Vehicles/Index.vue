<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { AdminVehicleList, AdminVehicleRow } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps<{ vehicles: AdminVehicleList }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Fahrzeuge', href: route('admin.vehicles.index') }];

function ownerRoute(vehicle: AdminVehicleRow): string | null {
    if (vehicle.vehicle_belongs === 'B2C' && vehicle.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: vehicle.user_id });
    }
    if (vehicle.vehicle_belongs === 'B2B' && vehicle.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: vehicle.b2b_id });
    }
    return null;
}

function ownerLabel(vehicle: AdminVehicleRow): string {
    if (vehicle.vehicle_belongs === 'B2B') {
        return vehicle.company_name ?? '—';
    }
    return vehicle.user_email ?? '—';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleDateString('de-DE');
}

const totalPages = (list: AdminVehicleList) => Math.max(1, Math.ceil(list.total / list.limit));

function goToPage(page: number) {
    router.get(route('admin.vehicles.index'), { page }, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <Head title="Fahrzeuge" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Fahrzeuge</h1>
                <p class="text-muted-foreground text-sm">Alle Fahrzeuge aus B2C und B2B.</p>
            </div>

            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Kennzeichen</TableHead>
                            <TableHead>Marke · Modell</TableHead>
                            <TableHead>Halter</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Erstellt</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="vehicles.data.length === 0" :colspan="5"> Keine Fahrzeuge gefunden. </TableEmpty>
                        <TableRow v-for="vehicle in vehicles.data" :key="vehicle.vehicle_id">
                            <TableCell class="font-medium">
                                <Link :href="route('admin.vehicles.show', vehicle.vehicle_id)">{{ vehicle.license_plate }}</Link>
                            </TableCell>
                            <TableCell>{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                            <TableCell>
                                <Link v-if="ownerRoute(vehicle)" :href="ownerRoute(vehicle)!" class="hover:underline">
                                    {{ ownerLabel(vehicle) }}
                                </Link>
                                <span v-else>{{ ownerLabel(vehicle) }}</span>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).variant">
                                    {{ getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).label }}
                                </Badge>
                            </TableCell>
                            <TableCell>{{ formatDate(vehicle.created_at) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="totalPages(props.vehicles) > 1" class="flex items-center justify-between">
                <p class="text-muted-foreground text-sm">Seite {{ vehicles.page }} von {{ totalPages(props.vehicles) }}</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="vehicles.page <= 1" @click="goToPage(vehicles.page - 1)"> Zurück </Button>
                    <Button variant="outline" size="sm" :disabled="vehicles.page >= totalPages(props.vehicles)" @click="goToPage(vehicles.page + 1)">
                        Weiter
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

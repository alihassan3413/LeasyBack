<script setup lang="ts">
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { AdminOrderList, AdminOrderRow } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ orders: AdminOrderList; filters: { status: string | null } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Alle Aufträge', href: route('admin.orders.index') }];

const ALL_STATUSES = 'all';

const statusOptions: SelectFieldOption[] = [
    { value: ALL_STATUSES, label: 'Alle Status' },
    { value: 'order_requested', label: 'Angefragt' },
    { value: 'order_placed', label: 'Bestellt' },
    { value: 'confirmed', label: 'Bestätigt' },
    { value: 'inspected', label: 'Begutachtet' },
    { value: 'workshop', label: 'In der Werkstatt' },
    { value: 'reinspection', label: 'Nachbegutachtung' },
    { value: 'reworkshop', label: 'Erneut in der Werkstatt' },
    { value: 'delivered', label: 'Abgeschlossen' },
    { value: 'cancelled', label: 'Storniert' },
    { value: 'discarded', label: 'Verworfen' },
];

const statusFilter = computed({
    get: () => props.filters.status ?? ALL_STATUSES,
    set: (value: string) => {
        router.get(
            route('admin.orders.index'),
            value === ALL_STATUSES ? {} : { status: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
});

function ownerRoute(order: AdminOrderRow): string | null {
    if (order.user_type === 'Firmenkunde' && order.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: order.b2b_id });
    }
    if (order.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: order.user_id });
    }
    return null;
}

function ownerLabel(order: AdminOrderRow): string {
    return (order.user_type === 'Firmenkunde' ? order.company_name : order.user_email) ?? '—';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleDateString('de-DE');
}

const totalPages = (list: AdminOrderList) => Math.max(1, Math.ceil(list.total / list.limit));

function goToPage(page: number) {
    router.get(
        route('admin.orders.index'),
        { page, ...(props.filters.status ? { status: props.filters.status } : {}) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Alle Aufträge" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Alle Aufträge</h1>
                    <p class="text-muted-foreground text-sm">Aufträge aus B2C und B2B, mit Status.</p>
                </div>
                <div class="w-56">
                    <SelectField v-model="statusFilter" :options="statusOptions" placeholder="Status" />
                </div>
            </div>

            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Auftragsnummer</TableHead>
                            <TableHead>Kennzeichen</TableHead>
                            <TableHead>Halter</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Erstellt</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="orders.data.length === 0" :colspan="5"> Keine Aufträge gefunden. </TableEmpty>
                        <TableRow v-for="order in orders.data" :key="order.id">
                            <TableCell class="font-medium">
                                <Link :href="route('admin.orders.show', order.id)">{{ order.auftragsnummer }}</Link>
                            </TableCell>
                            <TableCell>{{ order.license_plate }}</TableCell>
                            <TableCell>
                                <Link v-if="ownerRoute(order)" :href="ownerRoute(order)!" class="hover:underline">
                                    {{ ownerLabel(order) }}
                                </Link>
                                <span v-else>{{ ownerLabel(order) }}</span>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getVehicleStatusDisplay(order.order_status).variant">
                                    {{ getVehicleStatusDisplay(order.order_status).label }}
                                </Badge>
                            </TableCell>
                            <TableCell>{{ formatDate(order.created_at) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="totalPages(props.orders) > 1" class="flex items-center justify-between">
                <p class="text-muted-foreground text-sm">Seite {{ orders.page }} von {{ totalPages(props.orders) }}</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="orders.page <= 1" @click="goToPage(orders.page - 1)"> Zurück </Button>
                    <Button variant="outline" size="sm" :disabled="orders.page >= totalPages(props.orders)" @click="goToPage(orders.page + 1)">
                        Weiter
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup lang="ts">
import AdminOffersCard from '@/components/admin/AdminOffersCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import OrderStatusTimeline from '@/components/shared/OrderStatusTimeline.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { AdminOrderDetail } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ order: AdminOrderDetail }>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Alle Aufträge', href: route('admin.orders.index') },
    { title: props.order.auftragsnummer, href: route('admin.orders.show', props.order.id) },
]);

const ownerRoute = computed(() => {
    if (props.order.user_type === 'Firmenkunde' && props.order.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: props.order.b2b_id });
    }
    if (props.order.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: props.order.user_id });
    }
    return null;
});

const ownerLabel = computed(() => (props.order.user_type === 'Firmenkunde' ? props.order.company_name : props.order.user_email) ?? '—');

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString('de-DE');
}

const approving = ref(false);
const transitioning = ref<string | null>(null);

function approve() {
    approving.value = true;
    router.post(
        route('admin.orders.approve', props.order.id),
        {},
        { preserveScroll: true, onFinish: () => (approving.value = false) },
    );
}

function transitionTo(status: string) {
    transitioning.value = status;
    router.patch(
        route('admin.orders.status', props.order.id),
        { status },
        { preserveScroll: true, onFinish: () => (transitioning.value = null) },
    );
}
</script>

<template>
    <Head :title="order.auftragsnummer" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <Card>
                <CardContent class="flex flex-wrap items-center justify-between gap-4 pt-6">
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-lg font-semibold">{{ order.auftragsnummer }}</h1>
                            <Badge :variant="getVehicleStatusDisplay(order.order_status).variant">
                                {{ getVehicleStatusDisplay(order.order_status).label }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground text-sm">{{ order.license_plate }} · {{ order.leasyback_partner }}</p>
                    </div>
                    <Link v-if="ownerRoute" :href="ownerRoute" class="text-sm hover:underline"> Halter: {{ ownerLabel }} </Link>
                    <span v-else class="text-muted-foreground text-sm">Halter: {{ ownerLabel }}</span>
                </CardContent>
            </Card>

            <OrderStatusTimeline :status="order.order_status" />

            <Card v-if="order.order_status === 'order_requested' || order.available_transitions.length > 0">
                <CardHeader>
                    <CardTitle class="text-sm">Status verwalten</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-wrap gap-2">
                    <Button v-if="order.order_status === 'order_requested'" size="sm" :loading="approving" @click="approve">
                        Freigeben (an TÜV SÜD senden)
                    </Button>
                    <Button
                        v-for="status in order.available_transitions"
                        :key="status"
                        size="sm"
                        :variant="status === 'cancelled' ? 'destructive' : 'outline'"
                        :loading="transitioning === status"
                        @click="transitionTo(status)"
                    >
                        {{ getVehicleStatusDisplay(status).label }}
                    </Button>
                </CardContent>
            </Card>

            <AdminOffersCard :order-id="order.id" :offers="order.offers" />

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm">Verlauf</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Von</TableHead>
                                <TableHead>Nach</TableHead>
                                <TableHead>Durch</TableHead>
                                <TableHead>Zeitpunkt</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableEmpty v-if="order.status_updates.length === 0" :colspan="4"> Keine Statusänderungen. </TableEmpty>
                            <TableRow v-for="update in order.status_updates" :key="update.id">
                                <TableCell>{{ getVehicleStatusDisplay(update.old_status).label }}</TableCell>
                                <TableCell>{{ getVehicleStatusDisplay(update.new_status).label }}</TableCell>
                                <TableCell>{{ update.updated_by }}</TableCell>
                                <TableCell>{{ formatDate(update.created_at) }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>

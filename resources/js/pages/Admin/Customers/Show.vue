<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useInitials } from '@/composables/useInitials';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { AdminCustomerDetail, AdminCustomerOrder, AdminCustomerType, AdminCustomerVehicle } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    type: AdminCustomerType;
    customer: AdminCustomerDetail;
    vehicles: AdminCustomerVehicle[];
    orders: AdminCustomerOrder[];
}>();

const { getInitials } = useInitials();

const displayName = computed(() => {
    if (props.type === 'b2b' && props.customer.company_name) {
        return props.customer.company_name;
    }
    return [props.customer.salutation, props.customer.first_name, props.customer.last_name].filter(Boolean).join(' ') || props.customer.user_email;
});

const identifier = computed(() => (props.type === 'b2b' ? props.customer.b2b_id : String(props.customer.user_id)));

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Kunden', href: route('admin.customers.index') },
    { title: displayName.value, href: route('admin.customers.show', { type: props.type, id: identifier.value }) },
]);

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleDateString('de-DE');
}

const confirmingStatusChange = ref(false);
const statusProcessing = ref(false);

function toggleStatus() {
    statusProcessing.value = true;
    router.patch(
        route('admin.customers.status', { type: props.type, id: identifier.value }),
        { is_active: !props.customer.is_active },
        {
            preserveScroll: true,
            onFinish: () => {
                statusProcessing.value = false;
                confirmingStatusChange.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="displayName" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <Card>
                <CardContent class="flex flex-wrap items-center justify-between gap-4 pt-6">
                    <div class="flex items-center gap-4">
                        <Avatar size="base">
                            <AvatarFallback>{{ getInitials(displayName) }}</AvatarFallback>
                        </Avatar>
                        <div>
                            <div class="flex items-center gap-2">
                                <h1 class="text-lg font-semibold">{{ displayName }}</h1>
                                <Badge :variant="customer.is_active ? 'success' : 'outline'">
                                    {{ customer.is_active ? 'Aktiv' : 'Inaktiv' }}
                                </Badge>
                            </div>
                            <p class="text-muted-foreground text-sm">{{ customer.user_email ?? customer.contact_email }}</p>
                        </div>
                    </div>

                    <div v-if="!confirmingStatusChange">
                        <Button :variant="customer.is_active ? 'destructive' : 'default'" size="sm" @click="confirmingStatusChange = true">
                            {{ customer.is_active ? 'Deaktivieren' : 'Aktivieren' }}
                        </Button>
                    </div>
                    <div v-else class="bg-muted flex items-center gap-3 rounded-md border p-3 text-sm">
                        <span>{{ customer.is_active ? 'Kunde wirklich deaktivieren?' : 'Kunde wirklich aktivieren?' }}</span>
                        <Button variant="ghost" size="sm" :disabled="statusProcessing" @click="confirmingStatusChange = false"> Abbrechen </Button>
                        <Button
                            :variant="customer.is_active ? 'destructive' : 'default'"
                            size="sm"
                            :loading="statusProcessing"
                            @click="toggleStatus"
                        >
                            Bestätigen
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Adresse</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <p v-if="customer.street">{{ customer.street }} {{ customer.number }}</p>
                        <p v-if="customer.additional_address">{{ customer.additional_address }}</p>
                        <p v-if="customer.zip_code || customer.city">{{ customer.zip_code }} {{ customer.city }}</p>
                        <p v-if="!customer.street" class="text-muted-foreground">Keine Adresse hinterlegt.</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Land</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <p>{{ customer.country ?? '—' }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Meta</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <p>Beigetreten: {{ formatDate(customer.created_at) }}</p>
                        <p v-if="type === 'b2b' && customer.vat_id">USt-ID: {{ customer.vat_id }}</p>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="type === 'b2b' && customer.members && customer.members.length > 0">
                <CardHeader>
                    <CardTitle class="text-sm">Mitglieder</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div v-for="member in customer.members" :key="member.user_id" class="flex items-center justify-between">
                        <span>{{ member.user_email }}</span>
                        <Badge variant="outline">{{ member.role }}</Badge>
                    </div>
                </CardContent>
            </Card>

            <Tabs default-value="vehicles">
                <TabsList>
                    <TabsTrigger value="vehicles">Fahrzeuge</TabsTrigger>
                    <TabsTrigger value="orders">Aufträge</TabsTrigger>
                </TabsList>

                <TabsContent value="vehicles">
                    <div class="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kennzeichen</TableHead>
                                    <TableHead>Marke · Modell</TableHead>
                                    <TableHead>VIN</TableHead>
                                    <TableHead>Leasingende</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableEmpty v-if="vehicles.length === 0" :colspan="5"> Keine Fahrzeuge. </TableEmpty>
                                <TableRow v-for="vehicle in vehicles" :key="vehicle.vehicle_id">
                                    <TableCell class="font-medium">{{ vehicle.license_plate }}</TableCell>
                                    <TableCell>{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                                    <TableCell>{{ vehicle.vin ?? '—' }}</TableCell>
                                    <TableCell>{{ formatDate(vehicle.leasing_end_date) }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).variant">
                                            {{ getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).label }}
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </TabsContent>

                <TabsContent value="orders">
                    <div class="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Auftragsnummer</TableHead>
                                    <TableHead>Marke · Modell</TableHead>
                                    <TableHead>Partner</TableHead>
                                    <TableHead>Kennzeichen</TableHead>
                                    <TableHead>Erstellt</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableEmpty v-if="orders.length === 0" :colspan="6"> Keine Aufträge. </TableEmpty>
                                <TableRow v-for="order in orders" :key="order.id">
                                    <TableCell class="font-medium">{{ order.auftragsnummer }}</TableCell>
                                    <TableCell>{{ [order.make, order.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                                    <TableCell>{{ order.leasyback_partner }}</TableCell>
                                    <TableCell>{{ order.license_plate }}</TableCell>
                                    <TableCell>{{ formatDate(order.created_at) }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="getVehicleStatusDisplay(order.order_status).variant">
                                            {{ getVehicleStatusDisplay(order.order_status).label }}
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AdminLayout>
</template>

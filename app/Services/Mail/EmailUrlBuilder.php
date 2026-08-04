<?php

namespace App\Services\Mail;

use App\Modules\UserProfile\Vehicle\Models\Vehicle;

class EmailUrlBuilder
{
    public function customerVehicleUrl(?Vehicle $vehicle): string
    {
        if ($vehicle === null || $vehicle->vehicle_id === null) {
            return $this->customerDashboardUrl();
        }

        $path = $vehicle->vehicle_belongs === 'B2B'
            ? (string) config('mail_notifications.portal.b2b_vehicle_path')
            : (string) config('mail_notifications.portal.b2c_vehicle_path');

        if (trim($path) === '') {
            return $this->customerDashboardUrl();
        }

        return $this->portalUrl(str_replace('{vehicle}', rawurlencode((string) $vehicle->vehicle_id), $path));
    }

    public function customerDashboardUrl(): string
    {
        return $this->portalUrl((string) config('mail_notifications.portal.dashboard_path'));
    }

    public function adminOrderUrl(?string $orderId): string
    {
        if ($orderId === null) {
            return route('admin.orders.index');
        }

        return route('admin.orders.show', ['orderId' => $orderId]);
    }

    private function portalUrl(string $path): string
    {
        $base = rtrim((string) (config('mail_notifications.portal.url') ?: config('app.frontend_url')), '/');

        return $base.'/'.ltrim($path, '/');
    }
}

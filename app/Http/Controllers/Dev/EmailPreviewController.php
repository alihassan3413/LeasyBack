<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Mail\Orders\AppointmentConfirmedMail;
use App\Mail\Orders\AppointmentRequestedMail;
use App\Mail\Orders\FinalInspectionCompletedMail;
use App\Mail\Orders\InitialInspectionCompletedMail;
use App\Mail\Orders\OrderCreatedAdminMail;
use App\Mail\Orders\OrderCreatedCustomerMail;
use App\Mail\Orders\OrderEventMail;
use App\Mail\Orders\OrderStatusUpdatedMail;
use App\Mail\Orders\RepairApprovalConfirmedMail;
use App\Mail\Orders\RepairQuotationAvailableMail;
use App\Mail\Orders\VehicleInRepairMail;
use App\Mail\Orders\VehicleReadyForPickupMail;
use App\Services\Mail\OrderEmailData;
use App\Support\OrderStatusLabel;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EmailPreviewController extends Controller
{
    /**
     * @var array<string, array{mailable: class-string<OrderEventMail>, label: string, status: string, audience: string}>
     */
    private const PREVIEWS = [
        'order-created-customer' => ['mailable' => OrderCreatedCustomerMail::class, 'label' => 'Auftrag angelegt (Kunde)', 'status' => 'order_placed', 'audience' => 'customer'],
        'order-created-admin' => ['mailable' => OrderCreatedAdminMail::class, 'label' => 'Neuer Auftrag (Admin)', 'status' => 'order_placed', 'audience' => 'admin'],
        'appointment-requested' => ['mailable' => AppointmentRequestedMail::class, 'label' => 'Terminanfrage eingegangen', 'status' => 'order_requested', 'audience' => 'customer'],
        'appointment-confirmed' => ['mailable' => AppointmentConfirmedMail::class, 'label' => 'Termin bestätigt', 'status' => 'confirmed', 'audience' => 'customer'],
        'initial-inspection-completed' => ['mailable' => InitialInspectionCompletedMail::class, 'label' => 'Erstbegutachtung abgeschlossen', 'status' => 'inspected', 'audience' => 'customer'],
        'repair-quotation-available' => ['mailable' => RepairQuotationAvailableMail::class, 'label' => 'Reparaturangebot verfügbar', 'status' => 'inspected', 'audience' => 'offer'],
        'repair-approval-confirmed' => ['mailable' => RepairApprovalConfirmedMail::class, 'label' => 'Reparaturfreigabe bestätigt', 'status' => 'inspected', 'audience' => 'offer'],
        'vehicle-in-repair' => ['mailable' => VehicleInRepairMail::class, 'label' => 'Fahrzeug in Werkstatt', 'status' => 'workshop', 'audience' => 'customer'],
        'final-inspection-completed' => ['mailable' => FinalInspectionCompletedMail::class, 'label' => 'Nachprüfung abgeschlossen', 'status' => 'reinspection', 'audience' => 'customer'],
        'vehicle-ready-for-pickup' => ['mailable' => VehicleReadyForPickupMail::class, 'label' => 'Fahrzeug abholbereit', 'status' => 'delivered', 'audience' => 'customer'],
        'order-status-updated' => ['mailable' => OrderStatusUpdatedMail::class, 'label' => 'Allgemeines Statusupdate', 'status' => 'order_placed', 'audience' => 'customer'],
    ];

    public function index(): View
    {
        $this->guard();

        $previews = [];

        foreach (self::PREVIEWS as $key => $preview) {
            $previews[$key] = [
                'label' => $preview['label'],
                'mailable' => class_basename($preview['mailable']),
                'subject' => $this->mailable($key)->subjectLine(),
            ];
        }

        return view('dev.email-previews', ['previews' => $previews]);
    }

    public function show(string $key): Response
    {
        $this->guard();

        abort_unless(array_key_exists($key, self::PREVIEWS), 404);

        return new Response($this->mailable($key)->render());
    }

    private function mailable(string $key): OrderEventMail
    {
        $preview = self::PREVIEWS[$key];
        $mailable = $preview['mailable'];

        return new $mailable($this->sampleData($preview['status'], $preview['audience']));
    }

    private function sampleData(string $status, string $audience): OrderEmailData
    {
        return new OrderEmailData(
            recipientName: $audience === 'admin' ? (string) config('mail.from.name') : 'Maria Musterfrau',
            orderNumber: 'K-MU-1234-20260804',
            licensePlate: 'K-MU 1234',
            vin: 'WVWZZZ1KZAW123456',
            make: 'Volkswagen',
            model: 'Golf 8 Style',
            statusValue: $status,
            statusLabel: OrderStatusLabel::for($status),
            appointmentDate: '12.08.2026, 09:30 Uhr',
            stationName: 'TÜV SÜD Service-Center Köln',
            stationAddress: 'Rolshover Str. 45, 51105 Köln',
            provider: 'TÜV SÜD',
            remarks: 'Fahrzeug wird vom Fuhrparkleiter gebracht.',
            offerTotalGross: $audience === 'offer' ? '1.428,00 €' : null,
            actionUrl: $audience === 'admin'
                ? url('/admin/orders')
                : rtrim((string) (config('mail_notifications.portal.url') ?: config('app.frontend_url')), '/').'/dashboard',
        );
    }

    private function guard(): void
    {
        abort_unless(app()->environment('local'), 404);
    }
}

<?php

namespace App\Http\Controllers\B2b;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bStatisticsService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The company's own return statistics (§17) and their Excel export.
 *
 * Both routes are gated on `b2b.can:analytics.view` — the permission that
 * already governs every other company-level figure in the app — and neither
 * accepts a company id from the request. The company is resolved from the
 * caller's active membership, so there is no identifier to tamper with.
 */
class StatisticsController extends Controller
{
    public function __construct(
        private readonly B2bContext $context,
        private readonly B2bStatisticsService $statistics,
    ) {}

    public function index(Request $request): Response
    {
        $membership = $this->membership($request);

        return Inertia::render('b2b/Statistics', [
            'statistics' => $this->statistics->summary($membership, $request->user()->id),
        ]);
    }

    /**
     * The underlying rows as a real `.xlsx`, built by App\Support\XlsxWriter so
     * no spreadsheet dependency is needed. Two sheets: the same key figures the
     * page shows, and one row per order they were aggregated from.
     */
    public function export(Request $request): HttpResponse
    {
        $membership = $this->membership($request);
        $userId = $request->user()->id;

        $summary = $this->statistics->summary($membership, $userId);
        $rows = $this->statistics->exportRows($membership, $userId);

        $workbook = (new XlsxWriter)
            ->addSheet('Kennzahlen', ['Kennzahl', 'Wert'], $this->summarySheet($summary))
            ->addSheet('Auftraege', $this->exportHeadings(), $this->exportSheet($rows))
            ->toString();

        $filename = sprintf(
            'leasyback-statistik-%s-%s.xlsx',
            Str::slug($membership->companyName) ?: 'unternehmen',
            now()->format('Y-m-d'),
        );

        return response($workbook, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<list<mixed>>
     */
    private function summarySheet(array $summary): array
    {
        $savings = $summary['savings'];

        return [
            ['Laufende Aufträge', (int) $summary['orders']['active']],
            ['Abgeschlossene Aufträge', (int) $summary['orders']['completed']],
            ['Stornierte Aufträge', (int) $summary['orders']['cancelled']],
            ['Aufträge mit freigegebenem Angebot', (int) $savings['orders_counted']],
            ['Fahrzeuge mit freigegebenem Angebot', (int) $savings['vehicles_counted']],
            ['Gutachtenbetrag gesamt (netto)', (float) $savings['appraisal_total_net']],
            ['Freigegebener Reparaturbetrag gesamt (netto)', (float) $savings['repair_total_net']],
            ['Ersparnis gesamt (netto)', (float) $savings['saving_total_net']],
            [
                'Durchschnittliche Ersparnis je Fahrzeug (netto)',
                $savings['average_saving_per_vehicle_net'] === null ? '—' : (float) $savings['average_saving_per_vehicle_net'],
            ],
            [
                'Ersparnis in Prozent',
                $savings['saving_percentage'] === null ? '—' : (float) $savings['saving_percentage'],
            ],
            [
                'Durchschnittliche Bearbeitungsdauer (Tage)',
                $summary['processing_time']['average_days'] === null ? '—' : (float) $summary['processing_time']['average_days'],
            ],
            ['Gemessene abgeschlossene Aufträge', (int) $summary['processing_time']['measured_orders']],
        ];
    }

    /**
     * @return list<string>
     */
    private function exportHeadings(): array
    {
        return [
            'Auftragsnummer',
            'Kennzeichen',
            'FIN',
            'Hersteller',
            'Modell',
            'Leasinggeber',
            'Vertragsnummer',
            'Kostenstelle',
            'Status',
            'Erstellt am',
            'Abgeschlossen am',
            'Bearbeitungsdauer (Tage)',
            'Gutachtenbetrag (netto)',
            'Freigegebener Reparaturbetrag (netto)',
            'Ersparnis (netto)',
            'Freigegeben am',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<mixed>>
     */
    private function exportSheet(array $rows): array
    {
        return array_map(fn (array $row) => [
            $row['auftragsnummer'],
            $row['license_plate'],
            $row['vin'],
            $row['make'],
            $row['model'],
            $row['leasinggeber'],
            $row['contract_number'],
            $row['cost_centre'],
            $row['order_status_label'],
            $row['created_at'],
            $row['completed_at'],
            $row['processing_days'],
            $row['appraisal_total_net'],
            $row['repair_total_net'],
            $row['saving_net'],
            $row['accepted_at'],
        ], $rows);
    }

    /**
     * The route middleware has already refused anyone without an active
     * membership, so this only narrows the type.
     */
    private function membership(Request $request): B2bMembership
    {
        $membership = $this->context->activeMembership($request->user());

        abort_if($membership === null, 403);

        return $membership;
    }
}

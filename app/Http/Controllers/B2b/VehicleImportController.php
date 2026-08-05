<?php

namespace App\Http\Controllers\B2b;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Services\VehicleImportService;
use App\Support\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bulk vehicle import for company users (§5).
 *
 * Firmenkunde-only. `EnsureB2bPermission` deliberately waves every other
 * account type — Privatkunde, Werkstatt, Admin — straight through, so the
 * route middleware alone is not the boundary; the user type is re-checked
 * here, exactly as StatisticsController does for the same reason. There is no
 * Admin import surface and no B2C one, by construction rather than by UI.
 *
 * No company id is accepted from the request or the file: VehicleService
 * resolves the owning company from the caller's active B2bContext membership.
 */
class VehicleImportController extends Controller
{
    /** Bytes. A few thousand vehicle rows fit comfortably inside this. */
    private const MAX_UPLOAD_KILOBYTES = 5120;

    public function __construct(
        private readonly B2bContext $context,
        private readonly VehicleImportService $importer,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authoriseCompanyUser($request);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_UPLOAD_KILOBYTES,
                'extensions:'.implode(',', VehicleImportService::ACCEPTED_EXTENSIONS),
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,text/csv,text/plain,application/csv,application/vnd.ms-excel',
            ],
        ], [
            'file.extensions' => 'Bitte laden Sie eine Excel-Datei (.xlsx) oder eine CSV-Datei hoch.',
            'file.mimetypes' => 'Bitte laden Sie eine Excel-Datei (.xlsx) oder eine CSV-Datei hoch.',
            'file.max' => 'Die Datei ist zu groß (maximal 5 MB).',
        ]);

        $result = $this->importer->import($request->user(), $validated['file']);

        return to_route('dashboard')
            ->with('vehicle_import', $result)
            ->with($result['imported'] > 0 ? 'success' : 'warning', $this->summaryMessage($result));
    }

    /**
     * An empty workbook carrying exactly the headings the importer recognises,
     * so a user never has to guess a column name. Built with the existing
     * App\Support\XlsxWriter — writing was already solved in phase 14; only
     * reading needed the new dependency.
     */
    public function template(Request $request): HttpResponse
    {
        $this->authoriseCompanyUser($request);

        $workbook = (new XlsxWriter)
            ->addSheet('Fahrzeuge', VehicleImportService::templateHeadings(), [])
            ->toString();

        return response($workbook, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="leasyback-fahrzeug-import-vorlage.xlsx"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array{imported: int, rejected: int, truncated: bool}  $result
     */
    private function summaryMessage(array $result): string
    {
        $message = sprintf(
            '%d %s importiert, %d %s abgelehnt.',
            $result['imported'],
            $result['imported'] === 1 ? 'Fahrzeug' : 'Fahrzeuge',
            $result['rejected'],
            $result['rejected'] === 1 ? 'Zeile' : 'Zeilen',
        );

        if ($result['truncated']) {
            $message .= sprintf(
                ' Es wurden nur die ersten %d Zeilen verarbeitet.',
                VehicleImportService::MAX_ROWS,
            );
        }

        return $message;
    }

    /**
     * The import belongs to a company. A Privatkunde reaching this point has
     * passed the permission middleware by design and is refused here.
     */
    private function authoriseCompanyUser(Request $request): void
    {
        $user = $request->user();

        abort_if($user->user_type !== UserType::Firmenkunde, 403);
        abort_if($this->context->activeMembership($user) === null, 403);
    }
}

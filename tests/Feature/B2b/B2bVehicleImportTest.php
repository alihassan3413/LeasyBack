<?php

namespace Tests\Feature\B2b;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleImportService;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §5: "The import must validate required fields and show errors per
 * row without discarding valid rows."
 *
 * The partial-success contract is the phase's whole point, so the first test
 * is the one that would fail if anyone ever wrapped the import in a single
 * transaction.
 */
class B2bVehicleImportTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    /**
     * @param  list<list<string>>  $rows
     */
    private function upload(array $rows, string $name = 'fleet.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';

        file_put_contents($path, (new XlsxWriter)
            ->addSheet('Fahrzeuge', VehicleImportService::templateHeadings(), $rows)
            ->toString());

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @return list<string>
     */
    private function row(string $plate, string $vin = 'WVWZZZ1JZXW000001', string $mileage = '10000'): array
    {
        return [$plate, $vin, 'VW', 'Golf', '15.03.2022', $mileage, 'LeasePlan', 'V-1', '', '', '', '', '', '', '', '', '', ''];
    }

    public function test_valid_rows_are_committed_even_when_later_rows_fail(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        $file = $this->upload([
            $this->row('B AA 1111', 'WVWZZZ1JZXW000001'),
            ['', 'WVWZZZ1JZXW000002', 'VW', 'Polo', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            $this->row('B CC 3333', 'WVWZZZ1JZXW000003'),
            $this->row('B DD 4444', 'TOOSHORT'),
            $this->row('B EE 5555', 'WVWZZZ1JZXW000005'),
        ]);

        $result = app(VehicleImportService::class)->import($owner, $file);

        $this->assertSame(3, $result['imported']);
        $this->assertSame(2, $result['rejected']);

        foreach (['B AA 1111', 'B CC 3333', 'B EE 5555'] as $plate) {
            $this->assertDatabaseHas('vehicles', ['license_plate' => $plate, 'b2b_id' => $company->b2b_id]);
        }

        $this->assertDatabaseMissing('vehicles', ['license_plate' => 'B DD 4444']);
    }

    public function test_every_rejected_row_reports_its_file_row_number_and_reason(): void
    {
        $company = $this->makeCompany();

        $result = app(VehicleImportService::class)->import($this->makeOwner($company), $this->upload([
            $this->row('B AA 1111'),
            ['', 'WVWZZZ1JZXW000002', 'VW', 'Polo', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]));

        $this->assertCount(1, $result['errors']);
        // Heading is row 1, so the first data row is row 2 and the bad one is 3.
        $this->assertSame(3, $result['errors'][0]['row']);
        $this->assertNotEmpty($result['errors'][0]['messages']);
    }

    public function test_ownership_columns_in_the_file_are_ignored(): void
    {
        $company = $this->makeCompany();
        $foreign = $this->makeCompany('Fremd GmbH');
        $owner = $this->makeOwner($company);

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        file_put_contents($path, (new XlsxWriter)
            ->addSheet('F', ['Kennzeichen', 'b2b_id', 'vehicle_belongs', 'b2c_user_id'], [
                ['B ZZ 9999', $foreign->b2b_id, 'B2C', '1'],
            ])
            ->toString());

        $result = app(VehicleImportService::class)->import(
            $owner,
            new UploadedFile($path, 'hostile.xlsx', null, null, true),
        );

        $this->assertSame(1, $result['imported']);

        $vehicle = Vehicle::where('license_plate', 'B ZZ 9999')->firstOrFail();

        $this->assertSame($company->b2b_id, $vehicle->b2b_id);
        $this->assertSame('B2B', $vehicle->vehicle_belongs);
        $this->assertNull($vehicle->b2c_user_id);
        $this->assertSame($owner->id, (int) $vehicle->created_by_user_id);
    }

    public function test_a_duplicate_registration_number_is_rejected_without_disclosing_the_owner(): void
    {
        $company = $this->makeCompany();
        $foreign = $this->makeCompany('Fremd GmbH');

        $this->makeB2bVehicle($foreign, ['license_plate' => 'B XX 8888']);

        $result = app(VehicleImportService::class)->import(
            $this->makeOwner($company),
            $this->upload([$this->row('B XX 8888')]),
        );

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['rejected']);

        $message = implode(' ', $result['errors'][0]['messages']);

        $this->assertStringContainsString('bereits vergeben', $message);
        $this->assertStringNotContainsString('Fremd GmbH', $message);
        $this->assertStringNotContainsString($foreign->b2b_id, $message);
    }

    public function test_a_duplicate_inside_one_file_is_caught(): void
    {
        $company = $this->makeCompany();

        $result = app(VehicleImportService::class)->import(
            $this->makeOwner($company),
            $this->upload([
                $this->row('B AA 1111', 'WVWZZZ1JZXW000001'),
                // Same plate, differing only in case and spacing.
                $this->row('b  aa   1111', 'WVWZZZ1JZXW000002'),
            ]),
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['rejected']);
        $this->assertSame(1, Vehicle::where('license_plate', 'B AA 1111')->count());
    }

    public function test_the_import_route_refuses_a_privatkunde_and_an_admin(): void
    {
        $privatkunde = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($privatkunde)->post(route('vehicles.import'))->assertForbidden();
        $this->actingAs($this->makeAdmin())->post(route('vehicles.import'))->assertForbidden();
    }

    public function test_a_member_without_the_create_permission_is_refused(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, ['vehicles.view']);

        $this->actingAs($member)->post(route('vehicles.import'))->assertForbidden();
    }

    public function test_the_template_download_returns_a_real_xlsx(): void
    {
        $company = $this->makeCompany();

        $response = $this->actingAs($this->makeOwner($company))
            ->get(route('vehicles.import.template'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // A real xlsx is a zip, so it starts with the local file header magic.
        $this->assertStringStartsWith('PK', $response->getContent());
    }
}

<?php

namespace Tests\Feature\LegacyImport;

use App\Support\LegacyImport\LegacyValue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyValueTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null}>
     */
    public static function streets(): array
    {
        return [
            'plain' => ['Musterstraße 12', 'Musterstraße', '12'],
            'range' => ['Beispielstraße 12-16', 'Beispielstraße', '12-16'],
            'letter' => ['Hauptstr. 5a', 'Hauptstr.', '5a'],
            'slash' => ['Ring 3/4', 'Ring', '3/4'],
            'trailing space' => ['Testweg 178 ', 'Testweg', '178'],
            'ordinal street' => ['5. Straße 12', '5. Straße', '12'],
            'no number' => ['Am Markt', 'Am Markt', null],
            'empty' => ['', null, null],
            'null' => [null, null, null],
        ];
    }

    #[DataProvider('streets')]
    public function test_it_splits_street_and_house_number(?string $input, ?string $street, ?string $number): void
    {
        $this->assertSame([$street, $number], LegacyValue::splitStreet($input));
    }

    public function test_it_splits_names_on_the_last_space(): void
    {
        $this->assertSame(['first' => 'Anna Maria', 'last' => 'Beispiel'], LegacyValue::splitName('Anna Maria Beispiel'));
        $this->assertSame(['first' => null, 'last' => 'Beispiel'], LegacyValue::splitName(' Beispiel '));
        $this->assertSame(['first' => null, 'last' => null], LegacyValue::splitName(''));
    }

    public function test_it_normalises_phone_numbers_and_strips_the_excel_apostrophe(): void
    {
        $this->assertSame(['prefix' => '+49', 'number' => '30123456'], LegacyValue::phone("'+4930123456"));
        $this->assertSame(['prefix' => '+49', 'number' => '30123456'], LegacyValue::phone('030 123456'));
        $this->assertNull(LegacyValue::phone('123'));
        $this->assertNull(LegacyValue::phone(''));
        $this->assertSame('+49 30 123456', LegacyValue::phoneText("'+49 30 123456"));
    }

    public function test_it_normalises_plates_like_the_existing_vehicle_import(): void
    {
        $this->assertSame('B-AB 1234', LegacyValue::plate("  b-ab \t 1234 "));
        $this->assertNull(LegacyValue::plate('   '));
    }

    public function test_it_validates_vins(): void
    {
        $this->assertTrue(LegacyValue::isValidVin('VF1ABCDEFGH123456'));
        $this->assertFalse(LegacyValue::isValidVin('VF1ABCDEFGH12345'), 'too short');
        $this->assertFalse(LegacyValue::isValidVin('VF1ABCDEFGO123456'), 'contains O');
        $this->assertFalse(LegacyValue::isValidVin(null));
        $this->assertSame('VF1ABCDEFGH123456', LegacyValue::vin(' vf1abcdefgh123456 '));
    }

    public function test_it_reads_vehicle_id_lists_and_degrades_gracefully(): void
    {
        $this->assertSame(['aaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbb'], LegacyValue::idList('["aaaaaaaaaaaaaaaaaaaaaaaa","bbbbbbbbbbbbbbbbbbbbbbbb"]'));
        $this->assertSame([], LegacyValue::idList('[]'));
        $this->assertSame([], LegacyValue::idList(''));
        $this->assertSame(['aaaaaaaaaaaaaaaaaaaaaaaa'], LegacyValue::idList("['aaaaaaaaaaaaaaaaaaaaaaaa']"), 'malformed JSON falls back to scanning for ids');
    }

    public function test_it_parses_dates_and_timestamps_into_the_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);

        $this->assertSame('2026-12-31', LegacyValue::date('2026-12-31T00:00:00.000Z'));
        $this->assertSame('2026-03-01', LegacyValue::date('2026-03-01'));
        $this->assertNull(LegacyValue::date('not a date'));
        $this->assertSame('2026-07-01 12:00:00', LegacyValue::timestamp('2026-07-01T10:00:00.000000'));
        $this->assertNull(LegacyValue::timestamp(''));
    }

    public function test_it_reads_booleans_and_json(): void
    {
        $this->assertTrue(LegacyValue::bool('true'));
        $this->assertFalse(LegacyValue::bool('false'));
        $this->assertNull(LegacyValue::bool(''));
        $this->assertSame([['name' => 'IT']], LegacyValue::json('[{"name":"IT"}]'));
        $this->assertNull(LegacyValue::json('{broken'));
    }
}

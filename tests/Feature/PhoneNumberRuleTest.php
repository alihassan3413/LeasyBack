<?php

namespace Tests\Feature;

use App\Rules\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One phone rule for the whole application.
 *
 * There were seven rule sites with four different limits — digits-only 4–14,
 * max 20, max 30, max 50, max 64 — so the same number was accepted on one
 * screen and refused on another. These pin what the single rule now accepts.
 */
class PhoneNumberRuleTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validNumbers(): array
    {
        return [
            'two-digit area code' => ['030 12345678'],
            'three-digit area code' => ['0221 123456'],
            'four-digit area code' => ['08031 1234'],
            'mobile' => ['0151 23456789'],
            'unformatted digits' => ['03012345678'],
            'international form' => ['+49 30 12345678'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'far too short' => ['123'],
            'letters' => ['nicht vorhanden'],
            'beyond the E.164 ceiling' => ['0301234567890123'],
            'absurdly long' => ['0123456789012345678901234567890123456789'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_it_accepts_real_german_numbers(string $number): void
    {
        $this->assertTrue($this->passes($number), "{$number} should be accepted");
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_refuses_impossible_numbers(string $number): void
    {
        $this->assertFalse($this->passes($number), "{$number} should be refused");
    }

    public function test_an_empty_value_is_left_to_required(): void
    {
        $this->assertTrue($this->passes(''));
    }

    /**
     * A regex that allows every valid German number also allows thousands of
     * impossible ones — this is the difference the library buys.
     */
    public function test_a_number_of_the_right_length_but_a_dead_area_code_is_refused(): void
    {
        $this->assertFalse($this->passes('0199 1234567'));
    }

    public function test_the_sibling_prefix_decides_the_country(): void
    {
        $data = ['phones' => [['international_prefix' => '+41', 'phone_number' => '079 123 45 67']]];

        $validator = Validator::make($data, [
            'phones.*.phone_number' => [new PhoneNumber('international_prefix')],
        ]);

        $this->assertTrue($validator->passes(), 'a Swiss number must pass when the prefix says +41');
    }

    /**
     * The same digits, judged by two countries. A German 0800 service number
     * is not a number Switzerland issues, so the prefix beside the field is
     * what decides — German and Swiss national formats overlap too much for
     * the digits alone to say.
     */
    public function test_the_prefix_changes_which_country_judges_the_number(): void
    {
        $this->assertTrue($this->withPrefix('+49', '0800 123 45 67'));
        $this->assertFalse($this->withPrefix('+41', '0800 123 45 67'));
    }

    private function withPrefix(string $prefix, string $number): bool
    {
        return Validator::make(
            ['phones' => [['international_prefix' => $prefix, 'phone_number' => $number]]],
            ['phones.*.phone_number' => [new PhoneNumber('international_prefix')]],
        )->passes();
    }

    public function test_the_refusal_is_in_german(): void
    {
        $validator = Validator::make(['phone' => '123'], ['phone' => [new PhoneNumber]]);

        $this->assertStringContainsString('gültige Telefonnummer', $validator->errors()->first('phone'));
    }

    private function passes(string $number): bool
    {
        return Validator::make(['phone' => $number], ['phone' => [new PhoneNumber]])->passes();
    }
}

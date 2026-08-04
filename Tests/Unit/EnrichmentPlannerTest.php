<?php

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\WooCommerceCustomerEnrichment\Services\EnrichmentPlanner;

require_once __DIR__.'/../../Services/EnrichmentPlanner.php';

class EnrichmentPlannerTest extends TestCase
{
    private static function normalizer()
    {
        // Mirrors \Helper::phoneToNumeric(): digits only.
        return function ($phone) {
            return preg_replace('/[^0-9]/', '', (string) $phone);
        };
    }

    private function customer(array $overrides = [])
    {
        return array_merge([
            'first_name' => '', 'last_name' => '', 'company' => '',
            'address' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '',
            'phones' => [], 'emails' => ['kunde@example.org'],
        ], $overrides);
    }

    private function order(array $billing = [], $number = '12345')
    {
        return [
            'id' => (int) $number,
            'number' => $number,
            'billing' => array_merge([
                'first_name' => '', 'last_name' => '', 'company' => '',
                'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '',
                'postcode' => '', 'country' => '', 'email' => '', 'phone' => '',
            ], $billing),
        ];
    }

    private function allOn()
    {
        return ['phone' => true, 'email' => true, 'name' => true, 'address' => true];
    }

    public function testFillsEmptyFieldsFromFirstOrderWithValue()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [
                $this->order(['city' => ''], '111'),
                $this->order(['city' => 'Köln', 'postcode' => '50823'], '222'),
            ],
            $this->allOn(),
            self::normalizer()
        );

        $this->assertSame(['value' => 'Köln', 'order' => '222'], $plan['fields']['city']);
        $this->assertSame(['value' => '50823', 'order' => '222'], $plan['fields']['zip']);
    }

    public function testDoesNotTouchNonEmptyFields()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(['city' => 'Bonn']),
            [$this->order(['city' => 'Köln'], '111')],
            $this->allOn(),
            self::normalizer()
        );

        $this->assertArrayNotHasKey('city', $plan['fields']);
    }

    public function testAddressJoinsAddressLines()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [$this->order(['address_1' => 'Musterstr. 1', 'address_2' => 'Etage 3'], '111')],
            $this->allOn(),
            self::normalizer()
        );

        $this->assertSame('Musterstr. 1 Etage 3', $plan['fields']['address']['value']);
    }

    public function testNamePairOnlyWhenBothEmptyAndFromSameOrder()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [$this->order(['first_name' => 'Max', 'last_name' => 'Muster'], '111')],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertSame('Max', $plan['fields']['first_name']['value']);
        $this->assertSame('Muster', $plan['fields']['last_name']['value']);

        // Existing first name → name group untouched entirely.
        $plan = EnrichmentPlanner::plan(
            $this->customer(['first_name' => 'Maria']),
            [$this->order(['first_name' => 'Max', 'last_name' => 'Muster'], '111')],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertArrayNotHasKey('first_name', $plan['fields']);
        $this->assertArrayNotHasKey('last_name', $plan['fields']);
    }

    public function testPhoneDedupOnNormalizedForm()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(['phones' => ['491712345678']]),
            [$this->order(['phone' => '+49 171 2345678'], '111')],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertSame([], $plan['phones']);

        // Known limitation (same semantics as core's numeric compare):
        // "0171…" and "+49171…" normalize differently and are treated as two numbers.
        $plan = EnrichmentPlanner::plan(
            $this->customer(['phones' => ['491712345678']]),
            [$this->order(['phone' => '0171 2345678'], '111')],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertCount(1, $plan['phones']);
    }

    public function testSamePhoneInTwoOrdersAddedOnce()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [
                $this->order(['phone' => '+49 171 2345678'], '111'),
                $this->order(['phone' => '0049-171-2345678'], '222'),
            ],
            $this->allOn(),
            self::normalizer()
        );
        // '491712345678' vs '00491712345678' differ — but identical strings dedup:
        $this->assertCount(2, $plan['phones']);

        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [
                $this->order(['phone' => '+49 171 2345678'], '111'),
                $this->order(['phone' => '+49 171 2345678'], '222'),
            ],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertCount(1, $plan['phones']);
        $this->assertSame('111', $plan['phones'][0]['order']);
    }

    public function testEmailDedupCaseInsensitive()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(['emails' => ['kunde@example.org']]),
            [$this->order(['email' => 'KUNDE@Example.org'], '111')],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertSame([], $plan['emails']);
    }

    public function testNewBillingEmailPlanned()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [$this->order(['email' => 'partner@example.org'], '111')],
            $this->allOn(),
            self::normalizer()
        );
        $this->assertSame([['value' => 'partner@example.org', 'order' => '111']], $plan['emails']);
    }

    public function testTogglesDisableGroups()
    {
        $plan = EnrichmentPlanner::plan(
            $this->customer(),
            [$this->order([
                'first_name' => 'Max', 'last_name' => 'Muster', 'company' => 'ACME',
                'city' => 'Köln', 'phone' => '+49 171 2345678', 'email' => 'partner@example.org',
            ], '111')],
            ['phone' => false, 'email' => false, 'name' => false, 'address' => false],
            self::normalizer()
        );
        $this->assertSame(['fields' => [], 'phones' => [], 'emails' => []], $plan);
    }

    public function testNoOrdersEmptyPlan()
    {
        $plan = EnrichmentPlanner::plan($this->customer(), [], $this->allOn(), self::normalizer());
        $this->assertSame(['fields' => [], 'phones' => [], 'emails' => []], $plan);
    }
}

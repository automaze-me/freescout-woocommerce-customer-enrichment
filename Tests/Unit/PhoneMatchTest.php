<?php

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\WooCommerceCustomerEnrichment\Services\PhoneMatch;

require_once __DIR__.'/../../Services/PhoneMatch.php';

/**
 * Channel customers (WhatsApp) carry their number in international form
 * ("+491761234567"), while shop customers type theirs however they like
 * ("0176 1234567", "0160/12345678"). Both must be recognised as one number.
 */
class PhoneMatchTest extends TestCase
{
    public function samePhones()
    {
        return [
            'international vs national'       => ['+491761234567', '01761234567'],
            'national with separator'         => ['+491601234567', '0160/1234567'],
            'national with spaces'            => ['+491761234567', '0176 123 45 67'],
            'double-zero prefix'              => ['+491761234567', '0049 176 1234567'],
            'trunk zero in parentheses'       => ['+491761234567', '+49 (0) 176 1234567'],
            'trunk zero kept after code'      => ['+491761234567', '+490176 1234567'],
            'digits only, with country code'  => ['491761234567', '0176 1234567'],
            'digits only, without trunk zero' => ['1761234567', '+49 176 1234567'],
            'identical'                       => ['+43 664 1234567', '+43 664 1234567'],
            'three-digit country code'        => ['+420 601 123 456', '+420601123456'],
            'one-digit country code'          => ['+1 (555) 123-4567', '+15551234567'],
        ];
    }

    /**
     * @dataProvider samePhones
     */
    public function testSameNumberInDifferentNotation($a, $b)
    {
        $this->assertTrue(PhoneMatch::same($a, $b));
        $this->assertTrue(PhoneMatch::same($b, $a));
    }

    public function differentPhones()
    {
        return [
            'different subscriber'          => ['+491761234567', '01761234568'],
            'same digits, other country'    => ['+491761234567', '+431761234567'],
            'digits only vs other country'  => ['491761234567', '+43 176 1234567'],
            'longer number ends the same'   => ['+491761234567', '+4917612345678'],
            'too short to be a phone'       => ['12345', '12345'],
            'no digits'                     => ['n/a', 'n/a'],
        ];
    }

    /**
     * @dataProvider differentPhones
     */
    public function testDifferentNumbers($a, $b)
    {
        $this->assertFalse(PhoneMatch::same($a, $b));
        $this->assertFalse(PhoneMatch::same($b, $a));
    }

    // WooCommerce's order search is a plain substring match on the stored
    // text, so the term must survive the separators people type between
    // prefix and subscriber number: the last seven digits do.
    public function testSearchTermIsTheLastSevenDigits()
    {
        $this->assertSame('1234567', PhoneMatch::searchTerm('+49 176 1234567'));
        $this->assertSame('2345678', PhoneMatch::searchTerm('+49 176 12345678'));
    }

    public function testNoSearchTermForNumbersTooShortToMatch()
    {
        $this->assertNull(PhoneMatch::searchTerm('12345'));
        $this->assertNull(PhoneMatch::searchTerm(''));
    }
}

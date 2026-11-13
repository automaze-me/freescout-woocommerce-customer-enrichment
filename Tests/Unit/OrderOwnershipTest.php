<?php

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\WooCommerceCustomerEnrichment\Services\OrderOwnership;

require_once __DIR__.'/../../Services/OrderOwnership.php';

/**
 * An order found only because its number appears in a message is not proof
 * that the sender placed it: suppliers, fulfillers and carriers cite the end
 * customer's order number all the time. Such an order may only feed the
 * sender's profile when the billing last name matches the sender's name.
 */
class OrderOwnershipTest extends TestCase
{
    private function order($number, $first_name, $last_name)
    {
        return [
            'id'      => (int) $number,
            'number'  => (string) $number,
            'billing' => ['first_name' => $first_name, 'last_name' => $last_name],
        ];
    }

    // A supplier replying to the shop's "Order #1234 – please ship" mail.
    public function testNumberOnlyOrderOfAnotherBuyerIsForeign()
    {
        $result = OrderOwnership::partition(
            [$this->order(1234, 'Max', 'Mustermann')],
            [],
            'Sanna',
            'Virtanen'
        );

        $this->assertSame([], $result['trusted']);
        $this->assertSame([['order' => '1234', 'name' => 'Max Mustermann']], $result['foreign']);
    }

    public function testNumberOnlyOrderWithTheSendersLastNameIsTrusted()
    {
        $order  = $this->order(1234, 'Max', 'Mustermann');
        $result = OrderOwnership::partition([$order], [], 'Max', 'Mustermann');

        $this->assertSame([$order], $result['trusted']);
        $this->assertSame([], $result['foreign']);
    }

    // The sender's own email or phone already led to this order: it is theirs,
    // whatever name their mail client shows.
    public function testOrderAlsoFoundByEmailOrPhoneIsTrustedWhateverTheName()
    {
        $order  = $this->order(1234, 'Max', 'Mustermann');
        $result = OrderOwnership::partition([$order], [1234], 'Info', '');

        $this->assertSame([$order], $result['trusted']);
        $this->assertSame([], $result['foreign']);
    }

    public function testSenderWithoutAnyNameCannotConfirmANumberOnlyOrder()
    {
        $result = OrderOwnership::partition(
            [$this->order(4321, 'Lena', 'Schulz')],
            [],
            '',
            ''
        );

        $this->assertSame([], $result['trusted']);
        $this->assertSame([['order' => '4321', 'name' => 'Lena Schulz']], $result['foreign']);
    }

    public function testOrderWithoutBillingNameIsForeignWithEmptyName()
    {
        $result = OrderOwnership::partition([$this->order(4321, '', '')], [], 'Lena', 'Schulz');

        $this->assertSame([['order' => '4321', 'name' => '']], $result['foreign']);
    }

    public function testKeepsTheOrderOfTrustedOrders()
    {
        $a = $this->order(111, 'Max', 'Muster');
        $b = $this->order(222, 'Erika', 'Fremd');
        $c = $this->order(333, 'Max', 'Muster');

        $result = OrderOwnership::partition([$a, $b, $c], [], 'Max', 'Muster');

        $this->assertSame([$a, $c], $result['trusted']);
        $this->assertSame([['order' => '222', 'name' => 'Erika Fremd']], $result['foreign']);
    }

    public function testLastNameComparisonIgnoresCaseAndUmlautSpelling()
    {
        $this->assertTrue(OrderOwnership::nameMatches('Jörg', 'Müller', $this->order(1, 'J.', 'MUELLER')));
        $this->assertTrue(OrderOwnership::nameMatches('Jörg', 'Mueller', $this->order(1, 'Jörg', 'müller')));
    }

    // Mail clients send "Mustermann, Max" or a company-style display name.
    public function testAnyPartOfTheSendersNameMayCarryTheLastName()
    {
        $order = $this->order(1, 'Max', 'Mustermann');

        $this->assertTrue(OrderOwnership::nameMatches('Mustermann,', 'Max', $order));
        $this->assertTrue(OrderOwnership::nameMatches('Haustechnik', 'Mustermann', $order));
    }

    public function testDoubleLastNamesMatchOnEitherPart()
    {
        $this->assertTrue(OrderOwnership::nameMatches('Anna', 'Schmidt', $this->order(1, 'Anna', 'Schmidt-Weber')));
    }

    // A shared first name says nothing about who placed the order.
    public function testSameFirstNameAloneDoesNotMatch()
    {
        $this->assertFalse(OrderOwnership::nameMatches('Michael', 'Müller', $this->order(1, 'Michael', 'Schmidt')));
    }

    public function testInitialsDoNotMatch()
    {
        $this->assertFalse(OrderOwnership::nameMatches('M.', 'Weber', $this->order(1, 'Max', 'M.')));
    }
}

<?php

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\WooCommerceCustomerEnrichment\Services\InternalSender;

require_once __DIR__.'/../../Services/InternalSender.php';

/**
 * A FreeScout customer that is really the shop itself (the mailbox address,
 * an alias, or any address on the shop's domain) must never be enriched:
 * the shop's own automation mails cite order numbers of other people's
 * orders, and enriching that record piles strangers' contact data onto it.
 */
class InternalSenderTest extends TestCase
{
    const OWN_ADDRESSES = ['info@example-shop.de', 'sales@example-shop.de'];
    const OWN_HOSTS     = ['https://www.example-shop.de/'];

    private function isInternal(array $customer_emails)
    {
        return InternalSender::matches($customer_emails, self::OWN_ADDRESSES, self::OWN_HOSTS);
    }

    public function testMailboxAddressMatches()
    {
        $this->assertTrue($this->isInternal(['info@example-shop.de']));
    }

    public function testMailboxAliasMatches()
    {
        $this->assertTrue($this->isInternal(['sales@example-shop.de']));
    }

    public function testAddressMatchIsCaseInsensitive()
    {
        $this->assertTrue($this->isInternal(['Info@Example-Shop.de']));
    }

    public function testShopDomainMatchesAnyLocalPart()
    {
        $this->assertTrue($this->isInternal(['noreply@example-shop.de']));
    }

    public function testShopSubdomainMatches()
    {
        $this->assertTrue($this->isInternal(['alerts@mail.example-shop.de']));
    }

    public function testShopHostIsNormalised()
    {
        // scheme, "www." and trailing slash come from WooCommerce's URL setting
        $this->assertTrue(InternalSender::matches(['x@example-shop.de'], [], ['https://www.example-shop.de/']));
        $this->assertTrue(InternalSender::matches(['x@example-shop.de'], [], ['example-shop.de']));
        $this->assertTrue(InternalSender::matches(['x@example-shop.de'], [], ['http://example-shop.de/wp/']));
    }

    public function testAnyOfSeveralCustomerEmailsIsEnough()
    {
        // an already polluted record: the mailbox address sits among strangers
        $this->assertTrue($this->isInternal(['buyer@gmx.de', 'info@example-shop.de', 'other@web.de']));
    }

    public function testOrdinaryCustomerDoesNotMatch()
    {
        $this->assertFalse($this->isInternal(['buyer@gmx.de']));
    }

    public function testLookalikeDomainDoesNotMatch()
    {
        $this->assertFalse($this->isInternal(['x@notexample-shop.de']));
        $this->assertFalse($this->isInternal(['x@example-shop.de.evil.org']));
    }

    public function testTopLevelDomainAloneNeverMatches()
    {
        // a shop at example-shop.de must not flag every .de address
        $this->assertFalse(InternalSender::matches(['x@other.de'], [], ['example-shop.de']));
    }

    public function testEmptyInputsDoNotMatch()
    {
        $this->assertFalse(InternalSender::matches([], self::OWN_ADDRESSES, self::OWN_HOSTS));
        $this->assertFalse(InternalSender::matches(['buyer@gmx.de'], [], []));
        $this->assertFalse(InternalSender::matches(['not-an-email'], self::OWN_ADDRESSES, self::OWN_HOSTS));
    }
}

<?php

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\WooCommerceCustomerEnrichment\Services\OrderNumberExtractor;

require_once __DIR__.'/../../Services/OrderNumberExtractor.php';

class OrderNumberExtractorTest extends TestCase
{
    private function extract($text)
    {
        return OrderNumberExtractor::extract($text, OrderNumberExtractor::DEFAULT_PATTERN);
    }

    public function testHashNumber()
    {
        $this->assertSame(['12345'], $this->extract('Problem mit #12345, bitte helfen'));
    }

    public function testGermanKeywords()
    {
        $this->assertSame(['12345'], $this->extract('Meine Bestellung 12345 ist nicht angekommen'));
        $this->assertSame(['12345'], $this->extract('Bestellnummer: 12345'));
        $this->assertSame(['12345'], $this->extract('Auftrag #12345'));
        $this->assertSame(['12345'], $this->extract('auftragsnummer 12345'));
    }

    public function testEnglishKeyword()
    {
        $this->assertSame(['12345'], $this->extract('My order 12345 arrived damaged'));
        // "Order: #123" — the keyword branch fails on ": #", the # branch matches.
        $this->assertSame(['12345'], $this->extract('Order: #12345'));
    }

    public function testBareNumbersAreIgnored()
    {
        $this->assertSame([], $this->extract('Ich warte seit 14 Tagen, Artikel 88421 fehlt'));
        $this->assertSame([], $this->extract('PLZ 50823 Köln'));
        $this->assertSame([], $this->extract('am 12.05.2024 bestellt'));
        $this->assertSame([], $this->extract('Tel 0171 2345678'));
    }

    public function testKeywordInsideWordDoesNotMatch()
    {
        $this->assertSame([], $this->extract('Vorbestellung 12345 kommt später'));
    }

    public function testTooFewDigits()
    {
        $this->assertSame([], $this->extract('#12'));
    }

    public function testDedupAndCap()
    {
        $this->assertSame(['111', '222', '333'],
            $this->extract('#111 #111 #222 #333 #444 #555'));
    }

    public function testOrderOfAppearance()
    {
        $this->assertSame(['999', '111'], $this->extract('#999 und auch Bestellung 111'));
    }

    public function testCustomPattern()
    {
        $this->assertSame(['0042'], OrderNumberExtractor::extract('Ref WC-0042 bitte', 'WC-(\d{4})'));
    }

    public function testInvalidPatternReturnsEmpty()
    {
        $this->assertSame([], OrderNumberExtractor::extract('#12345', '([bad'));
    }

    public function testPatternWithoutCaptureGroupReturnsEmpty()
    {
        $this->assertSame([], OrderNumberExtractor::extract('#12345', '#\d+'));
    }

    // FreeScout tags outgoing subjects with the conversation number as
    // "[#1317]"; customer replies bring it back in the subject and in
    // quoted Outlook headers. It is never an order number.
    public function testFreeScoutTicketTagIsIgnored()
    {
        $this->assertSame([], $this->extract('AW: [#1317] Servus zusammen'));
    }

    public function testTicketTagInQuotedHeaderDoesNotHideRealOrder()
    {
        $text = "Betreff: AW: [#1317] Rückfrage\nVon: info@example.org\n\nBestellung 4390 ist falsch";
        $this->assertSame(['4390'], $this->extract($text));
    }

    public function testTicketTagStrippedEvenWithSpacesInsideBrackets()
    {
        $this->assertSame([], $this->extract('Re: [ #1317 ] Hallo'));
    }

    public function testIgnoredNumbersAreSkipped()
    {
        $this->assertSame(['4390'],
            OrderNumberExtractor::extract('#1510 und #4390', OrderNumberExtractor::DEFAULT_PATTERN, ['1510']));
        $this->assertSame(['4390'],
            OrderNumberExtractor::extract('#1510 und #4390', OrderNumberExtractor::DEFAULT_PATTERN, [1510]));
    }

    public function testIgnoredNumbersDoNotCountTowardsCap()
    {
        $this->assertSame(['100', '200', '300'],
            OrderNumberExtractor::extract('#1510 #100 #200 #300', OrderNumberExtractor::DEFAULT_PATTERN, ['1510']));
    }
}

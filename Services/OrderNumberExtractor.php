<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

/**
 * Pure order-number detection: no Laravel, no I/O — unit-testable standalone.
 */
class OrderNumberExtractor
{
    /**
     * Requires context (# or an order keyword) next to the digits so bare
     * numbers, dates, zip codes and phone numbers in the text don't match.
     * Longest keyword alternatives first so "bestellnummer" isn't half-eaten
     * by "bestellung"'s prefix. One capture group = the order number.
     */
    const DEFAULT_PATTERN = '(?:#|\b(?:bestellnummer|bestellung|auftragsnummer|auftrag|order)\s*[:#-]?\s*)(\d{3,10})\b';

    const MAX_NUMBERS = 3;

    /**
     * @param string $text    plain text (caller strips HTML)
     * @param string $pattern regex body without delimiters, one capture group;
     *                        applied case-insensitively (unicode)
     * @return string[] distinct numbers, order of appearance, max MAX_NUMBERS
     */
    public static function extract($text, $pattern)
    {
        if (!is_string($text) || $text === '' || !is_string($pattern) || $pattern === '') {
            return [];
        }

        // '~' as delimiter; escaping '~' in the pattern body keeps its
        // regex meaning identical (it is a literal there anyway).
        $regex = '~'.str_replace('~', '\~', $pattern).'~iu';

        if (@preg_match_all($regex, $text, $matches) === false) {
            return [];
        }

        $numbers = [];
        foreach ($matches[1] ?? [] as $number) {
            if ($number !== '' && !in_array($number, $numbers)) {
                $numbers[] = $number;
                if (count($numbers) >= self::MAX_NUMBERS) {
                    break;
                }
            }
        }

        return $numbers;
    }
}

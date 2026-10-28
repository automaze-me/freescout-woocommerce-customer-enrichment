<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

/**
 * Decides whether two phone numbers written in different notations are the
 * same number: a channel customer's international "+491761234567" and the
 * "0176 1234567" a shop customer typed at checkout.
 * Pure: no Laravel, no I/O — unit-testable standalone.
 */
class PhoneMatch
{
    /** Fewer digits than this is not a number worth matching on. */
    const MIN_DIGITS = 7;

    /**
     * Country calling codes are prefix-free: 1 and 7 are the only one-digit
     * codes, these are all the two-digit ones, every other code has three.
     */
    const TWO_DIGIT_CODES = [
        '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46',
        '47', '48', '49', '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63',
        '64', '65', '66', '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98',
    ];

    public static function same($a, $b)
    {
        $x = self::parse($a);
        $y = self::parse($b);

        if (!$x || !$y) {
            return false;
        }
        if ($x['code'] !== null && $y['code'] !== null && $x['code'] !== $y['code']) {
            return false;
        }
        if ($x['known'] && $y['known']) {
            return $x['national'] === $y['national'];
        }

        // One side is a bare digit run ("491761234567" or "1761234567"),
        // which may or may not carry a country code: compare as suffixes.
        $x_digits = $x['code'].$x['national'];
        $y_digits = $y['code'].$y['national'];
        if (strlen($x_digits) > strlen($y_digits)) {
            list($x_digits, $y_digits) = [$y_digits, $x_digits];
        }

        return substr($y_digits, -strlen($x_digits)) === $x_digits;
    }

    /**
     * Term for WooCommerce's order search, a plain substring match on the
     * stored text: the last digits survive the separators people type
     * between prefix and subscriber number ("0160/1234567").
     *
     * @return string|null
     */
    public static function searchTerm($phone)
    {
        $parsed = self::parse($phone);

        return $parsed ? substr($parsed['national'], -self::MIN_DIGITS) : null;
    }

    /**
     * @return array|null ['code' => country code or null, 'national' => digits
     *                    without country code and trunk zero, 'known' => whether
     *                    the notation said which of the two the digits are]
     */
    protected static function parse($phone)
    {
        $phone  = str_replace('(0)', '', trim((string) $phone));
        $digits = preg_replace('/[^0-9]/', '', $phone);

        $code  = null;
        $known = true;

        if (substr($phone, 0, 1) === '+' || substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 0, 2) === '00' ? substr($digits, 2) : $digits;
            $length = in_array($digits[0] ?? '', ['1', '7'], true)
                ? 1
                : (in_array(substr($digits, 0, 2), self::TWO_DIGIT_CODES, true) ? 2 : 3);
            $code   = substr($digits, 0, $length);
            // "+490176…": the trunk zero typed after the country code.
            $national = preg_replace('/^0/', '', (string) substr($digits, $length));
        } elseif (substr($digits, 0, 1) === '0') {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
            $known    = false;
        }

        if (strlen($national) < self::MIN_DIGITS) {
            return null;
        }

        return ['code' => $code, 'national' => $national, 'known' => $known];
    }
}

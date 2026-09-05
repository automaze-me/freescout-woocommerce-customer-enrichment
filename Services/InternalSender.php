<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

/**
 * Detects FreeScout customers that are really the shop itself: the mailbox
 * address, one of its aliases, or any address on the shop's own domain.
 *
 * The shop's automation mails (order alerts, seller notifications) land in
 * the support mailbox under such a record and cite other people's order
 * numbers, so enriching it piles strangers' contact data onto one profile.
 * Pure: no Laravel, no I/O — unit-testable standalone.
 */
class InternalSender
{
    /**
     * @param string[] $customer_emails all emails on the customer record
     * @param string[] $own_addresses   mailbox addresses and aliases
     * @param string[] $own_hosts       shop hosts/URLs as configured for WooCommerce
     */
    public static function matches(array $customer_emails, array $own_addresses, array $own_hosts)
    {
        $addresses = [];
        foreach ($own_addresses as $address) {
            $address = mb_strtolower(trim((string) $address));
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        $hosts = [];
        foreach ($own_hosts as $host) {
            $host = self::normalizeHost($host);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        foreach ($customer_emails as $email) {
            $email = mb_strtolower(trim((string) $email));
            if ($email === '' || strpos($email, '@') === false) {
                continue;
            }
            if (in_array($email, $addresses, true)) {
                return true;
            }
            $domain = substr($email, strrpos($email, '@') + 1);
            foreach ($hosts as $host) {
                if ($domain === $host || substr($domain, -strlen('.'.$host)) === '.'.$host) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * "https://www.Example-Shop.de/wp/" → "example-shop.de". A bare label
     * without a dot (e.g. "localhost" or a TLD) yields '' so it never matches.
     */
    protected static function normalizeHost($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'http://'.$url;
        }
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        return strpos($host, '.') === false ? '' : $host;
    }
}

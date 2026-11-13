<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

/**
 * Decides whether an order found only through its number in a message
 * belongs to the sender. Suppliers, fulfillers and carriers cite the end
 * customer's order number ("Order #1234 – please ship"); taking that order's
 * billing data would put a stranger's email on the partner's profile, and
 * FreeScout would then file the stranger's next mail under the partner.
 * Pure: no Laravel, no I/O — unit-testable standalone.
 */
class OrderOwnership
{
    /**
     * @param array      $number_orders orders found by number in the text, priority order
     * @param array      $confirmed_ids order ids also found through the customer's
     *                                  own emails or phones
     * @param string     $first_name    customer's first name
     * @param string     $last_name     customer's last name
     * @return array ['trusted' => orders, 'foreign' => [['order' => number, 'name' => billing name]]]
     */
    public static function partition(array $number_orders, array $confirmed_ids, $first_name, $last_name)
    {
        $confirmed = array_map('strval', $confirmed_ids);
        $result    = ['trusted' => [], 'foreign' => []];

        foreach ($number_orders as $order) {
            if (in_array((string) ($order['id'] ?? ''), $confirmed, true)
                || self::nameMatches($first_name, $last_name, $order)
            ) {
                $result['trusted'][] = $order;
                continue;
            }

            $billing = $order['billing'] ?? [];
            $result['foreign'][] = [
                'order' => (string) ($order['number'] ?? ($order['id'] ?? '')),
                'name'  => trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * True when a part of the customer's name equals a part of the order's
     * billing last name. Any part of the customer's name counts, because
     * display names come as "Mustermann, Max" or "Haustechnik Mustermann"; only
     * the order's last name counts, because a shared first name proves nothing.
     */
    public static function nameMatches($first_name, $last_name, array $order)
    {
        $customer = self::tokens($first_name.' '.$last_name);
        $buyer    = self::tokens($order['billing']['last_name'] ?? '');

        return (bool) array_intersect($customer, $buyer);
    }

    /**
     * "Jörg Müller-Lüdenscheidt" → ['joerg', 'mueller', 'luedenscheidt'].
     * Parts shorter than two letters (initials) are dropped.
     */
    protected static function tokens($name)
    {
        $name  = strtr(mb_strtolower((string) $name, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, function ($part) {
            return mb_strlen($part, 'UTF-8') >= 2;
        }));
    }
}

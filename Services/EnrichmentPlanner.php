<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

/**
 * Pure planning: decides what to change given a customer snapshot and
 * candidate orders. No Laravel, no I/O — unit-testable standalone.
 *
 * Fill-gaps-only for scalar fields; append-with-dedup for phones/emails.
 * Whether a planned email belongs to another FreeScout customer is checked
 * by the caller (needs DB access).
 */
class EnrichmentPlanner
{
    /** Customer field => WC billing key (scalars filled independently). */
    const FIELD_MAP = [
        'company' => 'company',
        'city'    => 'city',
        'state'   => 'state',
        'zip'     => 'postcode',
        'country' => 'country',
    ];

    /**
     * @param array $customer snapshot (see plan doc / tests); phones as stored
     * @param array $orders   candidate orders, priority order
     * @param array $enrich   ['phone'=>bool,'email'=>bool,'name'=>bool,'address'=>bool]
     * @return array ['fields'=>[], 'phones'=>[], 'emails'=>[]]
     */
    public static function plan(array $customer, array $orders, array $enrich)
    {
        $plan = ['fields' => [], 'phones' => [], 'emails' => []];

        $known_phones = $customer['phones'] ?? [];
        $known_emails = array_map('mb_strtolower', $customer['emails'] ?? []);

        foreach ($orders as $order) {
            $billing = $order['billing'] ?? [];
            $number  = (string) ($order['number'] ?? ($order['id'] ?? ''));

            // Name: only when the customer has neither first nor last name,
            // and both come from the same order (mixing sources mixes people).
            if (!empty($enrich['name'])
                && empty($customer['first_name']) && empty($customer['last_name'])
                && !isset($plan['fields']['first_name']) && !isset($plan['fields']['last_name'])
            ) {
                if (!empty($billing['first_name'])) {
                    $plan['fields']['first_name'] = ['value' => $billing['first_name'], 'order' => $number];
                }
                if (!empty($billing['last_name'])) {
                    $plan['fields']['last_name'] = ['value' => $billing['last_name'], 'order' => $number];
                }
            }

            if (!empty($enrich['address'])) {
                foreach (self::FIELD_MAP as $field => $key) {
                    if (empty($customer[$field]) && !isset($plan['fields'][$field]) && !empty($billing[$key])) {
                        $plan['fields'][$field] = ['value' => $billing[$key], 'order' => $number];
                    }
                }
                $address = trim(($billing['address_1'] ?? '').' '.($billing['address_2'] ?? ''));
                if (empty($customer['address']) && !isset($plan['fields']['address']) && $address !== '') {
                    $plan['fields']['address'] = ['value' => $address, 'order' => $number];
                }
            }

            if (!empty($enrich['phone']) && !empty($billing['phone'])
                && preg_match('/[0-9]/', $billing['phone'])
                && !self::knownPhone($billing['phone'], $known_phones)
            ) {
                $known_phones[] = $billing['phone'];
                $plan['phones'][] = ['value' => $billing['phone'], 'order' => $number];
            }

            if (!empty($enrich['email']) && !empty($billing['email'])) {
                $email = mb_strtolower(trim($billing['email']));
                if ($email !== '' && !in_array($email, $known_emails)) {
                    $known_emails[] = $email;
                    $plan['emails'][] = ['value' => $email, 'order' => $number];
                }
            }
        }

        return $plan;
    }

    /**
     * Same digits, or the same number in another notation ("0171…" in the
     * order, "+49171…" on a WhatsApp customer).
     */
    protected static function knownPhone($phone, array $known_phones)
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        foreach ($known_phones as $known) {
            if (preg_replace('/[^0-9]/', '', $known) === $digits || PhoneMatch::same($phone, $known)) {
                return true;
            }
        }

        return false;
    }
}

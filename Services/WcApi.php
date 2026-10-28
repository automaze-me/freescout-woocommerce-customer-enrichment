<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

/**
 * Thin wrapper around the official WooCommerce module's API access.
 * Shares its credentials and its wc_orders_… cache; adds single-order
 * lookup by ID and order search by phone (which the official module does
 * not provide).
 */
class WcApi
{
    const CACHE_MINUTES = 60;

    /** Phone searches match on a few digits only, so leave room for strays. */
    const MAX_PHONE_ORDERS = 10;

    /**
     * Orders matching an email, via the official module's search endpoint.
     * Mirrors WooCommerceController::ajax() caching exactly (same keys, same
     * TTL, empty results not cached) so job and sidebar share one API budget.
     *
     * @return array orders ([] on error or none)
     */
    public static function getOrdersByEmail($email, $mailbox = null)
    {
        $mailbox_enabled = $mailbox && \WooCommerce::isMailboxApiEnabled($mailbox);

        $cache_key = $mailbox_enabled
            ? 'wc_orders_'.$mailbox->id.'_'.$email
            : 'wc_orders_'.$email;

        $cached = \Cache::get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        // Hand the address over verbatim: the official module sanitizes it
        // (rejecting anything without a literal "@") and URL-encodes it itself.
        $result = \WooCommerce::apiGetOrders($email, $mailbox_enabled ? $mailbox : null);

        if (!empty($result['error'])) {
            \Log::error('[WooCommerceCustomerEnrichment] Order search failed for '.$email.': '.$result['error']);
            return [];
        }

        $orders = is_array($result['data'] ?? null) ? $result['data'] : [];

        if (count($orders)) {
            \Cache::put($cache_key, $orders, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $orders;
    }

    /**
     * Single order by ID (GET …/orders/<id>), same credential resolution as
     * the official module. 404 (unknown/random number) is expected noise.
     *
     * @return array|null
     */
    public static function getOrder($order_id, $mailbox = null)
    {
        $order_id = preg_replace('/[^0-9]/', '', (string) $order_id);
        if ($order_id === '') {
            return null;
        }

        $mailbox_enabled = $mailbox && \WooCommerce::isMailboxApiEnabled($mailbox);
        $cache_key = 'wcce_order_'.($mailbox_enabled ? $mailbox->id : 'global').'_'.$order_id;

        $cached = \Cache::get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            list($status_code, $order) = static::request('orders/'.$order_id, [], $mailbox);

            if ($status_code == 200 && !empty($order['id'])) {
                \Cache::put($cache_key, $order, now()->addMinutes(self::CACHE_MINUTES));
                return $order;
            }

            if ($status_code != 404) {
                \Log::error('[WooCommerceCustomerEnrichment] Order lookup #'.$order_id.' failed: HTTP '.$status_code);
            }
        } catch (\Exception $e) {
            \Log::error('[WooCommerceCustomerEnrichment] Order lookup #'.$order_id.': '.$e->getMessage());
        }

        return null;
    }

    /**
     * Orders whose billing phone is the given number, in whatever notation
     * either side wrote it. The official module can only search by email.
     * Raw search results are cached (non-empty only, like the email search);
     * the same-number check runs on every call.
     *
     * @return array orders ([] on error or none)
     */
    public static function getOrdersByPhone($phone, $mailbox = null)
    {
        $term = PhoneMatch::searchTerm($phone);
        if ($term === null) {
            return [];
        }

        $mailbox_enabled = $mailbox && \WooCommerce::isMailboxApiEnabled($mailbox);
        $cache_key = 'wcce_orders_phone_'.($mailbox_enabled ? $mailbox->id : 'global').'_'.$term;

        $orders = \Cache::get($cache_key);
        if (!is_array($orders)) {
            $orders = [];
            try {
                list($status_code, $json) = static::request('orders', ['per_page' => self::MAX_PHONE_ORDERS, 'search' => $term], $mailbox);

                if ($status_code == 200 && is_array($json)) {
                    // Some setups wrap collections in "data" (the official module accepts both).
                    $orders = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
                } else {
                    \Log::error('[WooCommerceCustomerEnrichment] Order search by phone failed: HTTP '.$status_code);
                }
            } catch (\Exception $e) {
                \Log::error('[WooCommerceCustomerEnrichment] Order search by phone: '.$e->getMessage());
            }

            if (count($orders)) {
                \Cache::put($cache_key, $orders, now()->addMinutes(self::CACHE_MINUTES));
            }
        }

        // The term is only the last digits: drop orders of other numbers that contain them.
        return array_values(array_filter($orders, function ($order) use ($phone) {
            return !empty($order['id']) && PhoneMatch::same($phone, $order['billing']['phone'] ?? '');
        }));
    }

    /**
     * GET …/wc/v<version>/<path> with the official module's credential
     * resolution: the mailbox's own when enabled for it, else the global ones.
     *
     * @return array [HTTP status code, decoded JSON body]
     */
    protected static function request($path, array $query, $mailbox = null)
    {
        if ($mailbox && \WooCommerce::isMailboxApiEnabled($mailbox)) {
            $settings  = \WooCommerce::getMailboxWcSettings($mailbox);
            $url       = \WooCommerce::getSanitizedUrl($settings['url'] ?? '');
            $key       = $settings['key'] ?? '';
            $secret    = $settings['secret'] ?? '';
            $version   = $settings['version'] ?? '';
            $rest_path = $settings['rest_path'] ?? 'wp-json';
        } else {
            $settings  = \WooCommerce::getGlobalSettings();
            $url       = \WooCommerce::getSanitizedUrl();
            $key       = $settings['woocommerce.key'];
            $secret    = $settings['woocommerce.secret'];
            $version   = $settings['woocommerce.version'];
            $rest_path = $settings['woocommerce.rest_path'];
        }

        $query = array_merge(['consumer_key' => $key, 'consumer_secret' => $secret], $query);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url.$rest_path.'/wc/v'.$version.'/'.$path.'?'.http_build_query($query));
        \Helper::setCurlDefaultOptions($ch);
        curl_setopt($ch, CURLOPT_USERAGENT, config('app.curl_user_agent') ?: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 7_1_4) AppleWebKit/603.26 (KHTML, like Gecko) Chrome/55.0.3544.220 Safari/534');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $json = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        return [$status_code, json_decode($json, true)];
    }
}

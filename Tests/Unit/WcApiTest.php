<?php

// WcApi::getOrdersByEmail() delegates to the official WooCommerce module and
// Laravel's cache. The module tests run without a booted app, so this file
// provides minimal stand-ins for those three globals; the guards keep them
// from ever shadowing the real classes when an app is present.

namespace {
    if (!class_exists('Cache', false)) {
        class Cache
        {
            public static $store = [];

            public static function get($key)
            {
                return self::$store[$key] ?? null;
            }

            public static function put($key, $value, $ttl = null)
            {
                self::$store[$key] = $value;
            }
        }
    }

    if (!class_exists('WooCommerce', false)) {
        class WooCommerce
        {
            /** @var string[] emails handed to apiGetOrders(), in call order */
            public static $searched = [];
            public static $response = ['error' => '', 'data' => []];

            public static function isMailboxApiEnabled($mailbox)
            {
                return false;
            }

            public static function apiGetOrders($customer_email, $mailbox = null)
            {
                self::$searched[] = $customer_email;

                return self::$response;
            }
        }
    }

    if (!class_exists('Log', false)) {
        class Log
        {
            public static $errors = [];

            public static function error($message, $context = [])
            {
                self::$errors[] = $message;
            }
        }
    }
}

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use Modules\WooCommerceCustomerEnrichment\Services\WcApi;

    require_once __DIR__.'/../../Services/WcApi.php';
    require_once __DIR__.'/../../Services/PhoneMatch.php';

    /** Answers WooCommerce REST requests from a script instead of the network. */
    class ScriptedWcApi extends WcApi
    {
        /** @var array[] [path, query] per request, in call order */
        public static $requests = [];
        public static $status   = 200;
        public static $body     = [];

        protected static function request($path, array $query, $mailbox = null)
        {
            self::$requests[] = [$path, $query];

            return [self::$status, self::$body];
        }
    }

    class WcApiTest extends TestCase
    {
        protected function setUp(): void
        {
            \Cache::$store         = [];
            \WooCommerce::$searched = [];
            \WooCommerce::$response = ['error' => '', 'data' => [['id' => 4390, 'currency' => 'EUR']]];
            \Log::$errors          = [];

            ScriptedWcApi::$requests = [];
            ScriptedWcApi::$status   = 200;
            ScriptedWcApi::$body     = [];
        }

        private static function order($id, $phone)
        {
            return ['id' => $id, 'currency' => 'EUR', 'billing' => ['phone' => $phone]];
        }

        // The official module sanitizes the address itself and rejects
        // anything without a literal "@" — a pre-encoded "%40" made every
        // email lookup fail with "Invalid customer email".
        public function testEmailReachesWooCommerceSearchVerbatim()
        {
            $orders = WcApi::getOrdersByEmail('Max.Mustermann+shop@example.org');

            $this->assertSame(['Max.Mustermann+shop@example.org'], \WooCommerce::$searched);
            $this->assertSame([['id' => 4390, 'currency' => 'EUR']], $orders);
        }

        public function testResultIsCachedUnderTheOfficialModulesKey()
        {
            WcApi::getOrdersByEmail('buyer@example.org');
            WcApi::getOrdersByEmail('buyer@example.org');

            $this->assertCount(1, \WooCommerce::$searched);
            $this->assertArrayHasKey('wc_orders_buyer@example.org', \Cache::$store);
        }

        public function testErrorYieldsNoOrdersAndIsLogged()
        {
            \WooCommerce::$response = ['error' => 'boom', 'data' => []];

            $this->assertSame([], WcApi::getOrdersByEmail('buyer@example.org'));
            $this->assertCount(1, \Log::$errors);
            $this->assertSame([], \Cache::$store);
        }
    
        // A WhatsApp customer has no email, only the international number it
        // wrote from; the shop stored what the buyer typed at checkout.
        public function testPhoneFindsOrdersStoredInNationalNotation()
        {
            ScriptedWcApi::$body = [self::order(5154, '0160/1234567')];

            $orders = ScriptedWcApi::getOrdersByPhone('+491601234567');

            $this->assertSame([5154], array_column($orders, 'id'));
            $this->assertSame('orders', ScriptedWcApi::$requests[0][0]);
            $this->assertSame('1234567', ScriptedWcApi::$requests[0][1]['search']);
        }

        // The search term is only seven digits: whatever else contains them
        // (another buyer's number) must not count as this customer's order.
        public function testPhoneSearchKeepsOnlyOrdersWithTheSameNumber()
        {
            ScriptedWcApi::$body = [
                self::order(5154, '0160 1234567'),
                self::order(4711, '0171 1234567'),
                self::order(4712, ''),
            ];

            $orders = ScriptedWcApi::getOrdersByPhone('+491601234567');

            $this->assertSame([5154], array_column($orders, 'id'));
        }

        public function testPhoneSearchIsCached()
        {
            ScriptedWcApi::$body = [self::order(5154, '01601234567')];

            ScriptedWcApi::getOrdersByPhone('+491601234567');
            $orders = ScriptedWcApi::getOrdersByPhone('+491601234567');

            $this->assertCount(1, ScriptedWcApi::$requests);
            $this->assertSame([5154], array_column($orders, 'id'));
        }

        public function testPhoneTooShortIsNeverSearched()
        {
            $this->assertSame([], ScriptedWcApi::getOrdersByPhone('12345'));
            $this->assertSame([], ScriptedWcApi::$requests);
        }

        public function testPhoneSearchErrorYieldsNoOrdersAndIsLogged()
        {
            ScriptedWcApi::$status = 500;
            ScriptedWcApi::$body   = null;

            $this->assertSame([], ScriptedWcApi::getOrdersByPhone('+491601234567'));
            $this->assertCount(1, \Log::$errors);
            $this->assertSame([], \Cache::$store);
        }
    }
}

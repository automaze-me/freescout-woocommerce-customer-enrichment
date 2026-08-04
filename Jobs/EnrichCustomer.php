<?php

namespace Modules\WooCommerceCustomerEnrichment\Jobs;

use App\Conversation;
use App\Email;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WooCommerceCustomerEnrichment\Services\EnrichmentLineItem;
use Modules\WooCommerceCustomerEnrichment\Services\EnrichmentPlanner;
use Modules\WooCommerceCustomerEnrichment\Services\OrderNumberExtractor;
use Modules\WooCommerceCustomerEnrichment\Services\WcApi;

class EnrichCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $conversation_id;
    public $thread_id;

    /** A failed lookup just means no enrichment this time — do not retry. */
    public $tries = 1;

    public function __construct($conversation_id, $thread_id)
    {
        $this->conversation_id = $conversation_id;
        $this->thread_id       = $thread_id;
    }

    public function handle()
    {
        try {
            $this->enrich();
        } catch (\Exception $e) {
            \Log::error('[WooCommerceCustomerEnrichment] '.$e->getMessage(), ['conversation' => $this->conversation_id]);
        }
    }

    protected function enrich()
    {
        $conversation = Conversation::find($this->conversation_id);
        $thread       = Thread::find($this->thread_id);

        if (!$conversation || !$thread) {
            return;
        }

        $customer = $conversation->customer;
        $mailbox  = $conversation->mailbox;

        if (!$customer || !$mailbox || !\Module::isActive('woocommerce')) {
            return;
        }

        $mailbox_enabled = \WooCommerce::isMailboxApiEnabled($mailbox);
        if (!\WooCommerce::isApiEnabled() && !$mailbox_enabled) {
            return;
        }
        $wc_mailbox = $mailbox_enabled ? $mailbox : null;

        $enrich = [
            'phone'   => (bool) \Option::get(WCCE_MODULE.'.enrich_phone', true),
            'email'   => (bool) \Option::get(WCCE_MODULE.'.enrich_email', true),
            'name'    => (bool) \Option::get(WCCE_MODULE.'.enrich_name', true),
            'address' => (bool) \Option::get(WCCE_MODULE.'.enrich_address', true),
        ];
        if (!array_filter($enrich)) {
            return;
        }

        // ---- Candidate orders: by number in subject/body first (most
        // specific — it's what the ticket is about), then by customer emails.
        $orders    = [];
        $order_ids = [];

        $pattern = \Option::get(WCCE_MODULE.'.pattern') ?: OrderNumberExtractor::DEFAULT_PATTERN;
        $text    = $conversation->subject."\n".\Helper::htmlToText($thread->body ?? '');

        foreach (OrderNumberExtractor::extract($text, $pattern) as $number) {
            $order = WcApi::getOrder($number, $wc_mailbox);
            if ($order && empty($order_ids[$order['id']])) {
                $order_ids[$order['id']] = true;
                $orders[] = $order;
            }
        }

        foreach ($customer->emails_cached as $email_obj) {
            foreach (WcApi::getOrdersByEmail($email_obj->email, $mailbox) as $order) {
                if (!empty($order['id']) && empty($order_ids[$order['id']])) {
                    $order_ids[$order['id']] = true;
                    $orders[] = $order;
                }
            }
        }

        if (!$orders) {
            return;
        }

        // ---- Plan.
        $snapshot = [
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'company'    => $customer->company,
            'address'    => $customer->address,
            'city'       => $customer->city,
            'state'      => $customer->state,
            'zip'        => $customer->zip,
            'country'    => $customer->country,
            'phones'     => [],
            'emails'     => [],
        ];
        foreach ($customer->getPhones() as $phone) {
            $snapshot['phones'][] = !empty($phone['n'])
                ? (string) $phone['n']
                : (string) \Helper::phoneToNumeric($phone['value'] ?? '');
        }
        foreach ($customer->emails_cached as $email_obj) {
            $snapshot['emails'][] = mb_strtolower($email_obj->email);
        }

        $plan = EnrichmentPlanner::plan($snapshot, $orders, $enrich, [\Helper::class, 'phoneToNumeric']);

        // ---- Apply.
        $added         = []; // translated, escaped fragments for the line item
        $skipped       = []; // ['email' =>, 'order' =>]
        $order_numbers = []; // set of order numbers that contributed

        if ($plan['fields']) {
            $data = [];
            foreach ($plan['fields'] as $field => $item) {
                $data[$field] = $item['value'];
                $order_numbers[$item['order']] = true;
            }
            // Planner already picked only-empty fields; replace_data=false is
            // belt-and-braces against concurrent edits.
            $customer->setData($data, false);

            if (isset($data['first_name']) || isset($data['last_name'])) {
                $added[] = __('name');
            }
            if (array_diff_key($data, ['first_name' => 1, 'last_name' => 1])) {
                $added[] = __('address');
            }
        }

        foreach ($plan['phones'] as $item) {
            $customer->addPhone($item['value']);
            $added[] = __('Phone').' '.e($item['value']);
            $order_numbers[$item['order']] = true;
        }

        foreach ($plan['emails'] as $item) {
            $sanitized = Email::sanitizeEmail($item['value']);
            if (!$sanitized) {
                continue;
            }
            $existing = Email::where('email', $sanitized)->first();
            if ($existing) {
                if ($existing->customer_id != $customer->id) {
                    // Never merge identities from a background job (spec D8).
                    $skipped[] = ['email' => $sanitized, 'order' => $item['order']];
                    $order_numbers[$item['order']] = true;
                }
                continue;
            }
            $customer->addEmail($sanitized);
            $added[] = __('Email').' '.e($sanitized);
            $order_numbers[$item['order']] = true;
        }

        if ($customer->isDirty()) {
            $customer->save();
        }

        // ---- Provenance.
        if (!$added && !$skipped) {
            return;
        }

        $lines = [];
        if ($added) {
            $lines[] = __('Customer profile enriched from WooCommerce order :orders', [
                    'orders' => e('#'.implode(', #', array_keys($order_numbers))),
                ]).': '.implode(', ', $added);
        }
        foreach ($skipped as $skip) {
            $lines[] = __('WooCommerce order :order matched, but :email belongs to another customer', [
                'order' => e('#'.$skip['order']),
                'email' => e($skip['email']),
            ]);
        }

        EnrichmentLineItem::create($conversation, implode('<br>', $lines));
    }
}

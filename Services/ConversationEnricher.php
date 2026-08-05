<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

use App\Conversation;
use App\Thread;
use Modules\WooCommerceCustomerEnrichment\Jobs\EnrichCustomer;

/**
 * Runs enrichment for a conversation synchronously: the first thread plus the
 * latest customer thread (deduped). Shared by the wcce:enrich command and the
 * manual-enrich AJAX endpoint.
 */
class ConversationEnricher
{
    /**
     * @return bool whether the run produced a new enrichment line item
     *              (data added or an ownership skip recorded)
     */
    public static function enrich(Conversation $conversation)
    {
        $threads = collect([
            $conversation->threads()->orderBy('created_at')->first(),
            $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('created_at', 'desc')->first(),
        ])->filter()->unique('id');

        if ($threads->isEmpty()) {
            return false;
        }

        $line_items_before = self::countEnrichmentLineItems($conversation);

        foreach ($threads as $thread) {
            (new EnrichCustomer($conversation->id, $thread->id))->handle();
        }

        return self::countEnrichmentLineItems($conversation) > $line_items_before;
    }

    protected static function countEnrichmentLineItems(Conversation $conversation)
    {
        return $conversation->threads()
            ->where('type', Thread::TYPE_LINEITEM)
            ->get()
            ->filter(function ($thread) {
                return (bool) $thread->getMeta(EnrichmentLineItem::LINEITEM_META);
            })
            ->count();
    }
}

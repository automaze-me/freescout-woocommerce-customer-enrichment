<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

use App\Conversation;
use App\Thread;

/**
 * The "profile enriched from order #X" line item. Same rendering contract as
 * KapsoWhatsApp's DeliveryFailureLineItem: NULL action_type (core can't
 * render custom codes), translated escaped HTML in body, meta flag consumed
 * by the provider's thread.action_text filter.
 */
class EnrichmentLineItem
{
    const LINEITEM_META = 'wcce_enrichment';

    /**
     * @param string $body_html already translated AND escaped by the caller
     */
    public static function create(Conversation $conversation, $body_html)
    {
        $lineItem = new Thread();
        $lineItem->conversation_id = $conversation->id;
        $lineItem->user_id         = null;
        $lineItem->type            = Thread::TYPE_LINEITEM;
        $lineItem->status          = Thread::STATUS_NOCHANGE;
        $lineItem->state           = Thread::STATE_PUBLISHED;
        $lineItem->body            = $body_html;
        // Core has no PERSON_SYSTEM; system-generated items go to the user side.
        $lineItem->source_via      = Thread::PERSON_USER;
        $lineItem->source_type     = Thread::SOURCE_TYPE_API;
        $lineItem->customer_id     = $conversation->customer_id;
        $lineItem->setMeta(self::LINEITEM_META, true);
        $lineItem->save();

        return $lineItem;
    }
}

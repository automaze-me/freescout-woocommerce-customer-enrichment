<?php

namespace Modules\WooCommerceCustomerEnrichment\Console;

use App\Conversation;
use Illuminate\Console\Command;
use Modules\WooCommerceCustomerEnrichment\Services\ConversationEnricher;

class EnrichConversation extends Command
{
    protected $signature = 'wcce:enrich {conversation_id}';

    protected $description = 'Run WooCommerce customer enrichment for one conversation (first thread + latest customer thread)';

    public function handle()
    {
        $conversation = Conversation::find($this->argument('conversation_id'));

        if (!$conversation) {
            $this->error('Conversation not found');
            return 1;
        }

        if (ConversationEnricher::enrich($conversation)) {
            $this->info('Enrichment recorded changes — see the conversation line item.');
        } else {
            $this->info('Nothing new to enrich.');
        }

        return 0;
    }
}

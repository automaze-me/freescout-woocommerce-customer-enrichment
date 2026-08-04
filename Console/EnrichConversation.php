<?php

namespace Modules\WooCommerceCustomerEnrichment\Console;

use App\Conversation;
use App\Thread;
use Illuminate\Console\Command;
use Modules\WooCommerceCustomerEnrichment\Jobs\EnrichCustomer;

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

        $threads = collect([
            $conversation->threads()->orderBy('created_at')->first(),
            $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('created_at', 'desc')->first(),
        ])->filter()->unique('id');

        if ($threads->isEmpty()) {
            $this->error('Conversation has no threads');
            return 1;
        }

        foreach ($threads as $thread) {
            (new EnrichCustomer($conversation->id, $thread->id))->handle();
            $this->line('Processed thread '.$thread->id);
        }

        $this->info('Done. Check the conversation for an enrichment line item (only added when something changed).');
        return 0;
    }
}

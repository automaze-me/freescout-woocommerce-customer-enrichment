<?php

namespace Modules\WooCommerceCustomerEnrichment\Providers;

use Illuminate\Support\ServiceProvider;

// Module alias.
define('WCCE_MODULE', 'woocommercecustomerenrichment');

class WooCommerceCustomerEnrichmentServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->registerViews();

        // Companion module: everything requires the official WooCommerce
        // module (credentials, API client, cache). Without it, register nothing.
        if (!\Module::isActive('woocommerce')) {
            return;
        }

        $this->hooks();
    }

    /**
     * Module hooks.
     * - thread.action_text filter (line item rendering) — in place.
     * Filled by later tasks:
     * - conversation.* triggers dispatching the EnrichCustomer job
     * - settings.* filters (settings section)
     */
    public function hooks()
    {
        // Render our enrichment line items: core's getActionText() returns ''
        // for a NULL action_type, so return the pre-translated body instead.
        \Eventy::addFilter('thread.action_text', function ($did_this, $thread, $conversation_number, $escape, $viewed_by_user) {
            if ((int) $thread->type === \App\Thread::TYPE_LINEITEM
                && $thread->getMeta(\Modules\WooCommerceCustomerEnrichment\Services\EnrichmentLineItem::LINEITEM_META)
            ) {
                return $thread->body;
            }

            return $did_this;
        }, 20, 5);
    }

    public function register()
    {
        $this->commands([
            \Modules\WooCommerceCustomerEnrichment\Console\EnrichConversation::class,
        ]);

        $this->registerTranslations();
    }

    public function registerViews()
    {
        $viewPath = resource_path('views/modules/woocommercecustomerenrichment');
        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([$sourcePath => $viewPath], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path.'/modules/woocommercecustomerenrichment';
        }, \Config::get('view.paths')), [$sourcePath]), 'woocommercecustomerenrichment');
    }

    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
    }

    public function provides()
    {
        return [];
    }
}

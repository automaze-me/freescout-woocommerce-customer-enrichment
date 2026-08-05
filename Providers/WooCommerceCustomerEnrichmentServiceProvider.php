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
        $this->registerConfig();
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
     * - thread.action_text filter: renders our enrichment line items.
     * - Three conversation triggers (created_by_customer, customer_replied,
     *   created_by_user) dispatching the EnrichCustomer job.
     * - settings.* filters wiring up the module's settings section
     *   (sections, view, section_settings, before_save validation).
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

        // Automatic enrichment triggers. Cheap guards here; the job re-checks
        // everything on fresh state when it runs.
        $dispatch_enrichment = function ($conversation, $thread) {
            if (!\WooCommerce::isApiEnabled() && !\WooCommerce::isMailboxApiEnabled($conversation->mailbox)) {
                return;
            }
            \Modules\WooCommerceCustomerEnrichment\Jobs\EnrichCustomer::dispatch($conversation->id, $thread->id)
                ->onQueue('default');
        };

        \Eventy::addAction('conversation.created_by_customer', function ($conversation, $thread, $customer) use ($dispatch_enrichment) {
            $dispatch_enrichment($conversation, $thread);
        }, 20, 3);

        \Eventy::addAction('conversation.customer_replied', function ($conversation, $thread, $customer) use ($dispatch_enrichment) {
            $dispatch_enrichment($conversation, $thread);
        }, 20, 3);

        \Eventy::addAction('conversation.created_by_user', function ($conversation, $thread) use ($dispatch_enrichment) {
            $dispatch_enrichment($conversation, $thread);
        }, 20, 2);

        // Settings section.
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[WCCE_MODULE] = ['title' => __('WooCommerce Customer Enrichment'), 'icon' => 'user', 'order' => 560];
            return $sections;
        }, 30, 1);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === WCCE_MODULE ? 'woocommercecustomerenrichment::settings' : $view;
        }, 20, 2);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== WCCE_MODULE) {
                return $settings;
            }
            return [
                WCCE_MODULE.'.pattern'        => \Option::get(WCCE_MODULE.'.pattern') ?: \Modules\WooCommerceCustomerEnrichment\Services\OrderNumberExtractor::DEFAULT_PATTERN,
                WCCE_MODULE.'.enrich_phone'   => \Option::get(WCCE_MODULE.'.enrich_phone', true),
                WCCE_MODULE.'.enrich_email'   => \Option::get(WCCE_MODULE.'.enrich_email', true),
                WCCE_MODULE.'.enrich_name'    => \Option::get(WCCE_MODULE.'.enrich_name', true),
                WCCE_MODULE.'.enrich_address' => \Option::get(WCCE_MODULE.'.enrich_address', true),
            ];
        }, 20, 2);

        // Validate the pattern: must compile. Empty is allowed (falls back to
        // the default at runtime); invalid keeps the previous value.
        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section !== WCCE_MODULE) {
                return $request;
            }
            $new = $request->settings ?: [];
            $pattern = trim($new[WCCE_MODULE.'.pattern'] ?? '');

            if ($pattern !== ''
                && @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', 'probe #12345') === false
            ) {
                $new[WCCE_MODULE.'.pattern'] = \Option::get(WCCE_MODULE.'.pattern') ?: '';
                $request->session()->flash('flash_error', __('Invalid order number pattern — keeping the previous value.'));
                $request->merge(['settings' => $new]);
            }

            return $request;
        }, 20, 3);
    }

    /**
     * Register config.
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('woocommercecustomerenrichment.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'woocommercecustomerenrichment'
        );
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

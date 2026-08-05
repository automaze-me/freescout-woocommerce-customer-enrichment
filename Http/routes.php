<?php

Route::group(['middleware' => ['web', 'auth'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\WooCommerceCustomerEnrichment\Http\Controllers'], function () {
    Route::post('/woocommerce-customer-enrichment/ajax', ['uses' => 'WooCommerceCustomerEnrichmentController@ajax', 'laroute' => true])->name('woocommercecustomerenrichment.ajax');
});

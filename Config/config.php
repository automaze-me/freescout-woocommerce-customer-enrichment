<?php

return [
    'name' => 'WooCommerceCustomerEnrichment',

    // Option defaults. Needed so core's processSave stores `false` when a
    // checkbox is unchecked (Option::getDefault must resolve `true`),
    // instead of removing the option row.
    'options' => [
        'enrich_phone'   => ['default' => true],
        'enrich_email'   => ['default' => true],
        'enrich_name'    => ['default' => true],
        'enrich_address' => ['default' => true],
    ],
];

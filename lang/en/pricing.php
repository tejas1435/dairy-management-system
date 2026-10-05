<?php

return [
    'title' => 'Milk Prices',
    'subtitle' => 'Business default rates. Changing a price opens a new period and leaves earlier ones untouched.',

    'price_set' => 'Price period saved.',
    'future_rule_removed' => 'The future price has been withdrawn.',

    'milk_types' => [
        'cow' => 'Cow',
        'buffalo' => 'Buffalo',
    ],

    'current' => 'Current rate',
    'no_current' => 'No rate configured',
    'history' => 'Price history',
    'set_new' => 'Set a new price',

    'columns' => [
        'rate' => 'Rate',
        'effective_from' => 'From',
        'effective_to' => 'To',
        'status' => 'Status',
    ],

    'fields' => [
        'milk_type' => 'Milk type',
        'rate' => 'Rate per litre',
        'effective_from' => 'Effective from',
    ],

    'states' => [
        'open' => 'Current',
        'closed' => 'Ended',
        'future' => 'Scheduled',
    ],

    'buyer' => [
        'title' => 'Price overrides',
        'subtitle' => 'Leave empty to use the business default rate.',
        'using_default' => 'Using business default',
        'using_override' => 'Buyer override',
    ],

    'help' => [
        'append_only' => 'Prices are history. A new period closes the current one the day before it starts, so past sales keep the rate that applied then.',
        'forward_only' => 'A new period must start after the most recent one.',
    ],

    'errors' => [
        'not_configured' => 'No :type price is configured for :date. Configure the milk price before saving.',
        'rate_positive' => 'The rate must be greater than zero.',
        'must_be_later' => 'The new period must start after :date.',
        'overlaps_existing' => 'That date falls inside an existing price period.',
        'invalid_date' => 'That is not a valid date.',
        'cannot_delete_effective' => 'A price that has already taken effect cannot be removed. Set a new price instead.',
        'wrong_business' => 'That price rule belongs to another business.',
        'wrong_buyer' => 'That price rule belongs to another buyer.',
    ],
];

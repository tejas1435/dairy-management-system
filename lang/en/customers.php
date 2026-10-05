<?php

return [
    'title' => 'Direct Customers',
    'subtitle' => 'People milk is delivered to daily. Registered once, then entered by date rather than selected.',
    'singular' => 'Customer',
    'profile' => 'Customer profile',

    'created' => 'Customer :name added.',
    'updated' => 'Customer :name updated.',
    'archived' => ':name has been archived and will no longer appear on the delivery round.',
    'restored' => ':name has been restored to the delivery round.',

    'actions' => [
        'add' => 'Add customer',
        'edit_profile' => 'Edit profile',
        'archive' => 'Archive customer',
        'restore' => 'Restore customer',
        'save' => 'Save customer',
    ],

    'fields' => [
        'name' => 'Name',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'address' => 'Address',
        'area' => 'Area',
        'delivery_note' => 'Delivery note',
        'payment_cycle' => 'Payment cycle',
        'end_date' => 'End date (optional)',
        'start_date' => 'Start date',
        'status' => 'Status',
        'morning_reminder' => 'Morning reminder',
        'evening_reminder' => 'Evening reminder',
        'amount' => 'Amount',
        'payment_method' => 'Payment method',
        'received_into' => 'Received into',
        'reference' => 'Reference',
        'notes' => 'Notes',
        'date' => 'Date',
        'reason' => 'Reason',
        'cancellation_reason' => 'Cancellation reason',
    ],

    'help' => [
        'delivery_note' => 'Standing instruction shown to whoever delivers — a gate code, a neighbour to leave it with. Never interpreted by the system.',
        'start_date' => 'The first date this customer appears on the delivery round. Leave empty for an existing customer.',
        'payment_cycle' => 'How often this customer settles — monthly, weekly, on collection.',
        'archive' => 'Archiving removes the customer from the delivery round. Past deliveries, payments and ledger stay exactly as they are.',
    ],

    'status' => [
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'sale_sources' => [
        'customer_daily_grid' => 'Customer daily entry',
        'mandali_delivery' => 'Mandali delivery',
        'vendor_sale' => 'Vendor sale',
        'generic_sale' => 'Other sale',
    ],

    'sale_cancellation' => [
        'removed_from_customer_daily_entry' => 'Removed from customer daily entry',
    ],

    'preferences' => [
        'title' => 'Milk preferences',
        'subtitle' => 'Which milk this customer takes, and the quantities to show as a reminder.',
        'takes_this_milk' => 'Takes :type milk',
        'none' => 'No milk preference set. This customer will not appear on the delivery round until one is.',
        'saved' => 'Milk preferences saved.',
        'summary' => 'Preferences',
        'inactive_note' => 'Switching a milk type off removes the customer from the round for that milk. Past deliveries are untouched.',
    ],

    'reminders' => [
        'label' => 'Reminder',
        'morning_short' => 'M',
        'evening_short' => 'E',
        'none' => 'No reminder',
        // The rule that matters, stated where the person setting it will read it.
        'explanation' => 'Reminders are a note to the person entering the day — nothing more. They are never filled into the quantity fields, never a minimum, and never generate a delivery on their own.',
    ],

    'pauses' => [
        'title' => 'Pauses',
        'subtitle' => 'Periods when this customer takes no milk.',
        'add' => 'Add pause',
        'created' => 'Pause recorded.',
        'cancelled' => 'Pause withdrawn.',
        'current' => 'Currently paused',
        'upcoming' => 'Upcoming',
        'past' => 'Past',
        'withdrawn' => 'Withdrawn',
        'none' => 'No pauses recorded.',
        'open_ended' => 'until further notice',
        'from_to' => ':from to :to',
        'paused_on' => 'Paused on :date',
        'cancel_title' => 'Withdraw this pause',
        'cancel_help' => 'The pause stays in the history and stops affecting deliveries. Give the reason it is being withdrawn.',
        'help' => 'During a pause no delivery is recorded for this customer. The daily entry screen marks them and the server refuses a sale for those dates.',
    ],

    'prices' => [
        'title' => 'Price overrides',
        'subtitle' => 'Leave empty to use the business default rate. Setting a rate opens a new period and leaves earlier ones untouched.',
        'using_default' => 'Business default',
        'using_override' => 'Customer rate',
        'set' => 'Set customer rate',
    ],

    'ledger' => [
        'title' => 'Ledger',
        'subtitle' => 'Derived from deliveries and payments. Nothing here is entered twice.',
        'opening_balance' => 'Opening balance',
        'closing_balance' => 'Closing balance',
        'morning' => 'Morning',
        'evening' => 'Evening',
        'total_milk' => 'Total milk',
        'rate' => 'Rate',
        'sale_amount' => 'Sale',
        'payment' => 'Payment',
        'balance' => 'Balance',
        'mixed_rate' => 'mixed',
        'none' => 'No deliveries or payments in this period.',
        'payment_received_from' => 'Payment received from :name',
        'payment_cancelled_for' => 'Payment cancelled — :name',
        'delivery' => 'Delivery',
    ],

    'statement' => [
        'title' => 'Statement',
        'period' => 'Period',
        'month' => 'Month',
        'milk_quantity' => 'Milk quantity',
        'sales' => 'Sales',
        'payments_received' => 'Payments received',
        'outstanding' => 'Outstanding',
        'outstanding_total' => 'Total outstanding',
        'in_period' => 'This period',
        // Sales are revenue recorded; payments are cash received. Conflating them is
        // the mistake this line exists to prevent.
        'not_cash' => 'Sales are what was delivered and billed. Payments are what has actually been received. The difference is the outstanding balance.',
        'apply' => 'Apply',
    ],

    'outstanding' => [
        'title' => 'Outstanding',
        'formula' => 'Sales + receivable adjustments − payments received.',
        'adjustments' => 'Receivable adjustments',
        'adjustments_note' => 'Receivable adjustments arrive in Phase 5. Until then this term is genuinely zero rather than estimated.',
        'settled' => 'Nothing outstanding',
    ],

    'payments' => [
        'title' => 'Payments',
        'subtitle' => 'Money received from this customer. Recording one credits the account it went into.',
        'record' => 'Record payment',
        'recorded' => 'Payment recorded.',
        'cancelled' => 'Payment withdrawn.',
        'none' => 'No payments recorded.',
        'history' => 'Payment history',
        'cancel_title' => 'Withdraw this payment',
        'cancel_help' => 'The payment stays in the history, the account credit is reversed, and the outstanding balance rises again. Give the reason.',
        'help' => 'A payment cannot exceed what the customer owes. Nothing here moves money — it records money that has already arrived.',
    ],

    'eligibility' => [
        'deliverable' => 'On the delivery round',
        'not_direct_customer' => 'This buyer is not a direct customer.',
        'archived' => 'Archived, so not on the delivery round.',
        'not_started' => 'Starts on a later date.',
        'paused' => 'Paused, so no delivery is recorded.',
        'no_preference' => 'No active milk preference, so nothing to deliver.',
    ],

    'list' => [
        'search' => 'Search by name or mobile',
        'filter_area' => 'Area',
        'filter_milk' => 'Takes milk type',
        'filter_status' => 'Status',
        'paused_today' => 'Paused today',
        'none' => 'No customers match these filters.',
        'none_yet' => 'No direct customers yet.',
        'columns' => [
            'customer' => 'Customer',
            'area' => 'Area',
            'preferences' => 'Takes',
            'outstanding' => 'Outstanding',
        ],
    ],

    'errors' => [
        'not_a_direct_customer' => 'That buyer is not a direct customer. Mandali and vendor records are managed from the buyer master.',
        'wrong_business' => 'That record belongs to another business.',
        'buyer_inactive' => ':name is archived, so no delivery can be recorded.',
        'buyer_without_channel' => 'That buyer has no sales channel, so a sale cannot be attributed.',
        'before_start_date' => ':name does not start until :date.',
        'customer_paused' => ':name is paused on :date, so no delivery can be recorded.',
        'no_active_preference' => ':name does not take :type milk.',
        'sale_quantity_positive' => 'The quantity must be greater than zero. Clear the field instead to remove the delivery.',
        'sale_already_cancelled' => 'That delivery has already been withdrawn.',
        'pause_overlaps' => 'This overlaps an existing pause from :from to :to. Withdraw that one first, or choose dates outside it.',
        'pause_end_before_start' => 'The pause cannot end before it starts.',
        'pause_already_cancelled' => 'That pause has already been withdrawn.',
        'payment_amount_positive' => 'The payment amount must be greater than zero.',
        'payment_exceeds_outstanding' => ':amount is more than the :outstanding outstanding. Reduce the amount — a credit balance needs an authorised workflow that does not exist yet.',
        'nothing_outstanding' => ':name owes nothing, so there is no payment to record.',
        'payment_already_cancelled' => 'That payment has already been withdrawn.',
        'account_not_found' => 'That financial account does not exist.',
        'account_inactive' => 'The :name account is inactive, so money cannot be received into it.',
        'payment_method_not_found' => 'That payment method does not exist.',
        'cancellation_reason_required' => 'Give the reason this record is being withdrawn.',
        'cancellation_reason_too_short' => 'Give a reason somebody reading this later could act on.',
    ],
];

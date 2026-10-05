<?php

return [
    'title' => 'Buyers',
    'subtitle' => 'Everyone the business sells milk to: Mandalis, vendors, direct customers and any channel you add.',
    'create_title' => 'Add buyer',
    'edit_title' => 'Edit buyer',

    'created' => 'Buyer :name has been added.',
    'updated' => 'Buyer :name has been updated.',
    'activated' => 'Buyer :name has been activated.',
    'deactivated' => 'Buyer :name has been deactivated.',

    'columns' => [
        'name' => 'Buyer',
        'channel' => 'Channel',
        'mobile' => 'Mobile',
        'area' => 'Area',
        'status' => 'Status',
    ],

    'fields' => [
        'name' => 'Name',
        'channel' => 'Sales channel',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'address' => 'Address',
        'area' => 'Area',
        'payment_cycle' => 'Payment cycle',
        'notes' => 'Notes',
    ],

    'filters' => [
        'search' => 'Search name or mobile',
        'channel' => 'Channel',
        'area' => 'Area',
        'all_channels' => 'All channels',
    ],

    'help' => [
        'shared_identity' => 'One record serves every channel. Milk deliveries, preferences, ledgers and payments arrive in later phases.',
        'permissions' => 'Access follows the channel: Mandali buyers need Mandali permissions, vendors need vendor permissions, and everything else needs customer permissions.',
    ],

    'empty' => 'No buyers match these filters.',

    /*
     |--------------------------------------------------------------------------
     | Phase 5 — Mandali, vendors, other buyers, settlement
     |--------------------------------------------------------------------------
     |
     | Added alongside the generic buyer master above, because a Mandali and a vendor
     | are buyers in different channels rather than different kinds of record (D26).
     | The direct-customer vocabulary stays in `customers.php`: a Mandali has no
     | delivery round, no pause and no reminder, so sharing those strings would mean
     | one of the two screens reading oddly.
     */

    'mandali' => [
        'title' => 'Mandalis',
        'subtitle' => 'The dairies that collect milk from this farm.',
        'singular' => 'Mandali',
        'create' => 'Add Mandali',
        'edit' => 'Edit Mandali',
        'empty' => 'No Mandalis are registered yet.',
        'empty_help' => 'Add the dairy that collects your milk, then record its deliveries and settle the period.',
        'deliveries' => 'Mandali Deliveries',
        'deliveries_subtitle' => 'Collections, with the fat and SNF readings and the agreed rate.',
        'record_delivery' => 'Record delivery',
        'delivery_saved' => 'Delivery recorded.',
        'no_deliveries' => 'No deliveries match these filters.',
    ],

    'vendor' => [
        'title' => 'Vendors',
        'subtitle' => 'Local dairies and traders who buy milk from this farm.',
        'singular' => 'Vendor',
        'create' => 'Add vendor',
        'edit' => 'Edit vendor',
        'empty' => 'No vendors are registered yet.',
        'empty_help' => 'Add a vendor to record sales against them and keep a ledger.',
        'sales' => 'Vendor Sales',
        'sales_subtitle' => 'Sales to local dairies, priced from the configured rate unless deliberately overridden.',
        'record_sale' => 'Record sale',
        'sale_saved' => 'Sale recorded.',
        'no_sales' => 'No sales match these filters.',
    ],

    'other' => [
        'title' => 'Other Sales',
        'subtitle' => 'Sales to hotels, sweet shops and any other channel you have set up.',
        'record_sale' => 'Record sale',
        'sale_saved' => 'Sale recorded.',
        'empty' => 'No other-channel sales match these filters.',
        'empty_buyers' => 'No buyers exist in a custom sales channel yet.',
        'empty_buyers_help' => 'Create a sales channel in Settings, then add a buyer in it. Mandalis, vendors and direct customers have their own screens.',
    ],

    /*
     * The buyers themselves, as opposed to the sales above: hotels, sweet shops and
     * anything else in a channel the business created.
     */
    'other_buyer' => [
        'title' => 'Other Buyers',
        'subtitle' => 'Buyers in the sales channels you have set up yourself.',
        'singular' => 'Buyer',
        'create' => 'Add buyer',
        'empty' => 'No buyers exist in a custom sales channel yet.',
        'empty_help' => 'Create a sales channel in Settings, then add a buyer in it. Mandalis, vendors and direct customers have their own screens.',
    ],

    'sale' => [
        'record' => 'Record a sale',
        'updated' => 'Sale updated.',
        'cancelled' => 'Sale withdrawn.',
        'edit' => 'Edit sale',
        'cancel' => 'Withdraw sale',
        'cancel_help' => 'The sale stays in the history, its milk returns to the shift and the buyer stops owing for it. Give the reason it is being withdrawn.',
        'overridden' => 'Rate overridden',
        'overridden_from' => 'Configured rate was :rate',
        'no_configured_rate' => 'No rate is configured for this buyer and milk type on this date',
        'fields' => [
            'buyer' => 'Buyer',
            'mandali' => 'Mandali',
            'vendor' => 'Vendor',
            'channel' => 'Sales channel',
            'date' => 'Date',
            'shift' => 'Shift',
            'milk_type' => 'Milk type',
            'quantity' => 'Quantity',
            'fat' => 'Fat %',
            'snf' => 'SNF %',
            'rate' => 'Rate',
            'manual_rate' => 'Rate (per litre)',
            'amount' => 'Amount',
            'notes' => 'Notes',
            'slip' => 'Collection slip',
            'override_reason' => 'Reason for the rate',
            'source' => 'Entered through',
        ],
        'help' => [
            'fat_snf' => 'Recorded for reference and reporting. They do not affect the rate or the amount.',
            'manual_rate' => 'The rate agreed with the Mandali, typed in each time. This system does not calculate a rate from fat or SNF.',
            'vendor_rate' => 'Pre-filled from the configured rate for this vendor and milk type on the selected date. A different figure is an authorised override and needs a reason.',
            'generic_rate' => 'Typed in for this sale: a custom channel has no configured price rules.',
            'slip' => 'Optional. PDF or photograph, up to 5 MB. Stored privately and reachable only by someone who may view the delivery.',
            'amount_calculated' => 'Quantity × rate, calculated by the server when you save.',
            'remaining_milk' => 'Milk still unallocated for the selected shift.',
        ],
    ],

    'settlement' => [
        'title' => 'Settlements',
        'subtitle' => 'Monthly reconciliation of what the Mandali collected against what it says it owes.',
        'singular' => 'Settlement',
        'create' => 'New settlement',
        'empty' => 'No settlements recorded for this Mandali.',
        'empty_help' => 'Open a settlement for a period, enter the Mandali statement when it arrives, then finalize it.',
        'draft_help' => 'A draft has no accounting effect. The figures below follow the deliveries and are frozen when you finalize.',
        'finalized_help' => 'Finalized on :date. These figures were agreed and no longer change.',
        'finalize' => 'Finalize settlement',
        'finalize_confirm' => 'This freezes the quantity and the expected amount, and records an adjustment for any difference. Continue?',
        'finalized' => 'Settlement finalized.',
        'created' => 'Settlement opened.',
        'updated' => 'Settlement updated.',
        'cancelled' => 'Settlement withdrawn.',
        'cancel' => 'Withdraw settlement',
        'cancel_help' => 'The settlement stays in the history and its receivable adjustment is withdrawn with it. Receipts already recorded are not touched; withdraw those separately first.',
        'deliveries_in_period' => 'Deliveries in this period',
        'adjustment_reason' => 'Mandali settlement difference for :from to :to',
        'adjustment_cancelled_reason' => 'Settlement for :period was withdrawn',
        'no_statement' => 'No statement entered, so the amount due is the system figure.',
        'no_difference' => 'The statement matches the system figure, so no adjustment was needed.',
        'difference_recorded' => 'A receivable adjustment was recorded for the difference.',
        'period_help' => 'Periods may not overlap another settlement for the same Mandali, so the same milk cannot be settled twice.',
        'fields' => [
            'period_start' => 'Period from',
            'period_end' => 'Period to',
            'statement_amount' => 'Mandali statement amount',
            'expected_amount' => 'System expected amount',
            'difference' => 'Difference',
            'milk_quantity' => 'Milk quantity',
            'amount_due' => 'Amount due',
            'paid' => 'Paid',
            'remaining' => 'Remaining',
            'status' => 'Status',
            'notes' => 'Notes',
        ],
        'help' => [
            'statement_amount' => 'Leave empty until the Mandali sends its statement. The difference against the system figure becomes an explicit receivable adjustment at finalization, and no historical rate is ever rewritten.',
            'expected_amount' => 'The total of the active deliveries from this Mandali in the period, at the rates they were recorded at.',
        ],
    ],

    'settlement_statuses' => [
        'draft' => 'Draft',
        'finalized' => 'Finalized',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
    ],

    'adjustment_directions' => [
        'increase' => 'Increase',
        'decrease' => 'Decrease',
    ],

    'adjustments' => [
        'title' => 'Balance adjustments',
        'empty' => 'No balance adjustments recorded.',
        'help' => 'An adjustment changes the outstanding balance only. It moves no milk and no cash.',
        'cancel' => 'Withdraw adjustment',
        'cancelled' => 'Adjustment withdrawn.',
    ],

    'ledger' => [
        'title' => 'Ledger',
        'sales' => 'Sales',
        'adjustments' => 'Receivable adjustments',
        'payments' => 'Payments received',
        'outstanding' => 'Outstanding',
        'milk' => 'Milk',
        'balance' => 'Balance',
        'opening' => 'Opening balance',
        'closing' => 'Closing balance',
        'empty' => 'No transactions in this period.',
        'formula' => 'Outstanding = sales + receivable adjustments − payments received.',
        'from' => 'From',
        'to' => 'To',
    ],

    'statement' => [
        'title' => 'Period statement',
        'open' => 'Period statement',
        'period' => 'Period',
        'previous_month' => 'Previous month',
        'next_month' => 'Next month',
        'this_month' => 'This month',
        'summary' => 'Summary',
        'expected_sales' => 'Expected sales amount',
        'settlements_in_period' => 'Settlements covering this period',
        'settlements_help' => 'A settlement freezes what was agreed for its own period. The figures above follow the deliveries themselves.',
        'transactions' => 'Transaction detail',
    ],

    'payment' => [
        'title' => 'Payments',
        'record' => 'Record payment',
        'recorded' => 'Payment recorded.',
        'cancelled' => 'Payment withdrawn.',
        'cancel' => 'Withdraw payment',
        'against_settlement' => 'Against settlement',
        'no_settlement' => 'On account (no settlement)',
        'empty' => 'No payments recorded.',
    ],

    'actions' => [
        'download_slip' => 'Download slip',
        'remove_slip' => 'Remove the current slip',
        'view_ledger' => 'Ledger',
        'view_settlements' => 'Settlements',
    ],
    'errors' => [
        'wrong_business' => 'That sales channel belongs to another business.',

        // Channel identity — the server's own check, not a restatement of the form's.
        'not_a_mandali' => 'That buyer is not a Mandali.',
        'not_a_vendor' => 'That buyer is not a vendor.',
        'not_a_custom_channel' => 'That buyer belongs to a system channel (:channels), which has its own entry screen.',

        // Rates
        'manual_rate_required' => 'Enter the agreed rate per litre.',
        'rate_positive' => 'The rate must be greater than zero.',
        'rate_override_not_permitted' => 'You do not have permission to depart from the configured rate.',
        'rate_change_not_permitted' => 'You do not have permission to change the rate on a recorded sale.',
        'override_reason_required' => 'Give the reason for this rate.',

        // Sales
        'sale_cancelled' => 'That sale has already been withdrawn.',
        'sale_covered_by_settlement' => 'This delivery falls inside a finalized settlement for :from to :to. Withdraw that settlement first, then correct the delivery and settle the period again.',
        'date_covered_by_settlement' => 'That date falls inside a finalized settlement for :from to :to. Withdraw the settlement first if a delivery needs adding to that period.',

        // Settlements
        'settlement_period_reversed' => 'The period cannot end before it starts.',
        'settlement_overlaps' => 'This period overlaps an existing settlement for :from to :to. Withdraw that one first, or choose dates outside it.',
        'settlement_not_draft' => 'Only a draft settlement can be edited. Withdraw this one and open another if it needs changing.',
        'settlement_already_finalized' => 'That settlement has already been finalized.',
        'settlement_cancelled' => 'That settlement has been withdrawn.',
        'settlement_already_cancelled' => 'That settlement has already been withdrawn.',
        'settlement_not_cancellable' => 'A settlement that is :status cannot be withdrawn.',
        'settlement_has_payments' => 'This settlement has :count payment(s) recorded against it. Withdraw those receipts first: cancelling a settlement must not reverse money that was actually received.',
        'settlement_wrong_buyer' => 'That settlement belongs to another buyer.',
        'settlement_not_payable' => 'A settlement that is :status cannot take a payment.',
        'settlement_already_paid' => 'That settlement is already paid in full.',
        'payment_exceeds_settlement' => ':amount is more than the :remaining still outstanding on this settlement.',

        // Receivable adjustments
        'adjustment_amount_positive' => 'The adjustment amount must be greater than zero.',
        'adjustment_reason_required' => 'An adjustment must state why it is being made.',
        'adjustment_exceeds_outstanding' => 'A decrease of :amount would leave the buyer in credit; only :outstanding is outstanding. A credit balance needs its own authorised workflow, which does not exist yet.',
        'adjustment_already_cancelled' => 'That adjustment has already been withdrawn.',
        'cancellation_reason_required' => 'Give the reason this record is being withdrawn.',
    ],
];

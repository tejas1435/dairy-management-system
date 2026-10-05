<?php

return [
    'title' => 'Partners',
    'subtitle' => 'People who put money into the business or pay its expenses directly.',
    'create_title' => 'Add partner',
    'edit_title' => 'Edit partner',

    'created' => 'Partner :name has been added.',
    'updated' => 'Partner :name has been updated.',
    'activated' => 'Partner :name has been activated.',
    'deactivated' => 'Partner :name has been deactivated.',
    'contribution_recorded' => 'Contribution of :amount recorded.',
    'contribution_cancelled' => 'The contribution has been cancelled and the account credit reversed.',

    'columns' => [
        'name' => 'Partner',
        'mobile' => 'Mobile',
        'joining_date' => 'Joined',
        'total' => 'Total contributed',
        'status' => 'Status',
    ],

    'fields' => [
        'name' => 'Name',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'joining_date' => 'Joining date',
        'notes' => 'Notes',
        'is_active' => 'Partner is active',
        'contribution_date' => 'Date',
        'amount' => 'Amount',
        'account' => 'Into account',
        'payment_method' => 'Payment method',
        'reference' => 'Reference',
    ],

    'ledger' => [
        'title' => 'Partner ledger',
        'subtitle' => 'Derived from contributions and partner-funded expenses. Nothing here is entered twice.',
        'contribution' => 'Contribution from :partner',
        'contribution_cancelled' => 'Cancelled contribution from :partner',
        'into_account' => 'Into :account',
        'period_total' => 'Total for the period',
        'empty' => 'No entries in this period.',

        'types' => [
            'contribution' => 'Contribution',
            'expense' => 'Expense paid',
        ],

        'columns' => [
            'date' => 'Date',
            'type' => 'Type',
            'reference' => 'Reference',
            'description' => 'Description',
            'amount' => 'Amount',
        ],
    ],

    'contribution' => [
        'title' => 'Record a contribution',
        'submit' => 'Record contribution',
        'help' => 'This credits the selected business account and appears in the cashbook immediately.',
    ],

    'help' => [
        'unlimited' => 'Any number of partners is supported. There are no fixed partner slots.',
        'no_profit_share' => 'Ownership percentages and profit sharing are not part of this version.',
        'derived_total' => 'Totals are derived from the underlying records, never stored.',
        'no_delete' => 'Partners with financial history are deactivated rather than deleted.',
    ],

    'empty' => 'No partners match these filters.',

    'errors' => [
        'wrong_business' => 'That partner belongs to another business.',
        'inactive_partner' => 'That partner is inactive.',
        'reason_required' => 'Give a reason for cancelling.',
        'already_cancelled' => 'This contribution has already been cancelled.',
    ],
];

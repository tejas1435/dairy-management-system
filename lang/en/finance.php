<?php

return [
    'title' => 'Finance',

    'statuses' => [
        'active' => 'Active',
        'cancelled' => 'Cancelled',
    ],

    'fields' => [
        'cancellation_reason' => 'Reason for cancellation',
    ],

    'accounts' => [
        'title' => 'Financial Accounts',
        'subtitle' => 'Cash and bank accounts kept inside this application. No bank is connected.',
        'create_title' => 'Add account',
        'edit_title' => 'Edit account',

        'created' => 'Account :name has been created.',
        'updated' => 'Account :name has been updated.',
        'activated' => 'Account :name has been activated.',
        'deactivated' => 'Account :name has been deactivated.',

        'types' => [
            'cash' => 'Cash',
            'bank' => 'Bank',
            'other' => 'Other',
        ],

        'columns' => [
            'name' => 'Account',
            'type' => 'Type',
            'opening_balance' => 'Opening',
            'balance' => 'Balance',
            'status' => 'Status',
        ],

        'fields' => [
            'name' => 'Account name',
            'type' => 'Type',
            'opening_balance' => 'Opening balance',
            'notes' => 'Notes',
            'is_active' => 'Account is active',
        ],

        'help' => [
            'derived_balance' => 'The balance is calculated from the ledger every time it is shown. It is never stored, so it cannot drift from the entries behind it.',
            'opening_locked' => 'The opening balance cannot be changed once the account has ledger entries, because every balance since would silently change with it.',
            'no_delete' => 'Accounts are deactivated rather than deleted, so their history stays readable.',
        ],

        'recent_entries' => 'Recent entries',
        'empty' => 'No accounts yet.',

        'errors' => [
            'wrong_business' => 'That account belongs to another business.',
            'inactive_account' => 'That account is inactive.',
        ],
    ],

    'ledger' => [
        'title' => 'Ledger',

        'directions' => [
            'credit' => 'Credit',
            'debit' => 'Debit',
        ],

        'reversal_of' => 'Reversal of entry #:id',
        'immutable_note' => 'Ledger entries are never edited or deleted. A correction is posted as a reversal, so both the original and the correction remain visible.',
    ],

    'cashbook' => [
        'title' => 'Cashbook',
        'subtitle' => 'Every movement on an account, with a running balance derived from the ledger.',

        'columns' => [
            'date' => 'Date',
            'description' => 'Description',
            'reference' => 'Reference',
            'method' => 'Method',
            'debit' => 'Debit',
            'credit' => 'Credit',
            'balance' => 'Balance',
        ],

        'opening_balance' => 'Opening balance',
        'closing_balance' => 'Closing balance',
        'brought_forward' => 'Balance brought forward',
        'carried_forward' => 'Carried forward to the next page',
        'empty' => 'No movements in this period.',
        'no_accounts' => 'Create a financial account first.',
        'exports_note' => 'Excel, PDF and print output arrive with the Reports module.',
    ],

    'funding' => [
        'title' => 'Funding',
        'subtitle' => 'Who paid for this, and from where.',

        'add_source' => 'Add funding source',
        'remove_row' => 'Remove',

        'columns' => [
            'source_type' => 'Paid by',
            'source' => 'Source',
            'amount' => 'Amount',
            'method' => 'Method',
            'reference' => 'Reference',
        ],

        'source_types' => [
            'partner' => 'Partner',
            'financial_account' => 'Business account',
        ],

        'select_source' => 'Select...',
        'allocated' => 'Allocated',
        'remaining' => 'Remaining',
        'balanced' => 'Fully allocated',

        'help' => [
            'server_checked' => 'The totals here are a preview. The server checks the split again when you save, and rejects it unless it matches the amount exactly.',
            'partner_no_debit' => 'A partner-funded share does not move a business account, because the money never passed through one. It appears on the partner ledger instead.',
        ],

        'errors' => [
            'none_provided' => 'Record at least one funding source.',
            'invalid_source_type' => 'That funding source type is not supported.',
            'amount_positive' => 'Each funding amount must be greater than zero.',
            'duplicate_source' => 'This source is already listed. Combine the two rows into one amount.',
            'source_not_found' => 'That funding source does not exist.',
            'source_inactive' => ':name is inactive and cannot fund this.',
            'invalid_payment_method' => 'That payment method does not exist.',
            'unsupported_payable' => 'This kind of record cannot be funded.',
            'under_allocated' => 'The funding is :short short of the total.',
            'over_allocated' => 'The funding exceeds the total by :excess.',
        ],
    ],
];

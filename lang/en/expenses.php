<?php

return [
    'title' => 'Expenses',
    'subtitle' => 'What the business spent, and who paid for it.',
    'create_title' => 'Record an expense',
    'edit_title' => 'Edit expense',
    'detail_title' => 'Expense',

    'created' => 'Expense ":description" has been recorded.',
    'updated' => 'Expense ":description" has been updated.',
    'cancelled' => 'The expense has been cancelled and its account effects reversed.',

    'columns' => [
        'date' => 'Date',
        'description' => 'Description',
        'category' => 'Category',
        'payee' => 'Payee',
        'funding' => 'Funded by',
        'amount' => 'Amount',
        'status' => 'Status',
    ],

    'fields' => [
        'date' => 'Date',
        'category' => 'Category',
        'amount' => 'Amount',
        'description' => 'Description',
        'payee_name' => 'Paid to',
        'notes' => 'Notes',
        'funding' => 'Funding',
    ],

    'ledger' => [
        'funded' => 'Expense: :description',
        'cancelled' => 'Cancelled expense: :description',
    ],

    'totals' => [
        'active' => 'Active expenses in view',
        'note' => 'Counts expenses, not funding shares, and excludes cancelled records.',
    ],

    'cancel' => [
        'title' => 'Cancel this expense',
        'submit' => 'Cancel expense',
        'help' => 'The expense and its funding split are kept. Business account movements are reversed with matching entries, so the account nets to zero and the history stays readable.',
    ],

    'help' => [
        'one_expense' => 'One expense, however many sources paid for it. Funding shares are never counted as extra expenses.',
        'locked_fields' => 'Amount, date and funding cannot be changed after posting, because the account entries have already been made. Cancel and re-enter instead.',
    ],

    'empty' => 'No expenses match these filters.',

    'errors' => [
        'wrong_business' => 'That expense belongs to another business.',
        'reason_required' => 'Give a reason for cancelling.',
        'already_cancelled' => 'This expense has already been cancelled.',
        'cancelled_not_editable' => 'A cancelled expense cannot be edited.',
    ],
];

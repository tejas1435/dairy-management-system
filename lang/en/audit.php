<?php

return [
    'title' => 'Audit Log',
    'subtitle' => 'A read-only record of who changed what. Entries cannot be edited or deleted.',
    'detail_title' => 'Audit entry',

    'columns' => [
        'when' => 'When',
        'user' => 'User',
        'action' => 'Action',
        'type' => 'Record',
        'subject' => 'Subject',
        'summary' => 'Changed',
    ],

    'filters' => [
        'user' => 'User',
        'action' => 'Action',
        'type' => 'Record type',
        'from' => 'From',
        'to' => 'To',
        'search' => 'Search subject',
        'all_users' => 'All users',
        'all_actions' => 'All actions',
        'all_types' => 'All records',
    ],

    'actions' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'activated' => 'Activated',
        'deactivated' => 'Deactivated',
        'cancelled' => 'Cancelled',
        'deleted' => 'Deleted',
        'roles_changed' => 'Roles changed',
        'permissions_changed' => 'Permissions changed',
        'primary_changed' => 'Primary changed',
        'funded' => 'Funding recorded',
        'reversed' => 'Reversed',
    ],

    'detail' => [
        'field' => 'Field',
        'before' => 'Before',
        'after' => 'After',
        'context' => 'Request details',
        'ip' => 'IP address',
        'user_agent' => 'Browser',
        'system' => 'System',
        'no_changes' => 'No field values were recorded for this entry.',
    ],

    'empty' => 'No audit entries match these filters.',
    'redacted_note' => 'Sensitive values such as passwords and tokens are never recorded.',
];

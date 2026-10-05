<?php

return [
    'title' => 'Roles & Permissions',
    'subtitle' => 'A role is a set of permissions. Users receive permissions through the roles they hold.',
    'create_title' => 'Add role',
    'edit_title' => 'Edit role',

    'created' => 'Role :name has been created.',
    'updated' => 'Role :name has been updated.',
    'deleted' => 'Role :name has been deleted.',

    'columns' => [
        'name' => 'Role',
        'permissions' => 'Permissions',
        'users' => 'Users',
        'type' => 'Type',
    ],

    'fields' => [
        'name' => 'Role name',
        'permissions' => 'Permissions',
    ],

    'system' => 'System role',
    'custom' => 'Custom role',

    'help' => [
        'system_name' => 'This is a system role. Its name is fixed, but its permissions can be changed.',
        'super_admin' => 'Super Admin always holds every permission, including ones added by future updates. Its permissions cannot be edited.',
        'immediate' => 'Permission changes take effect on the next request made by the affected users.',
        'select_all' => 'Select all in this group',
    ],

    'groups' => [
        'dashboard' => 'Dashboard',
        'milk' => 'Milk',
        'customers' => 'Direct customers',
        'mandali' => 'Mandali',
        'vendors' => 'Vendors',
        'animals' => 'Animals',
        'expenses' => 'Expenses',
        'finance' => 'Finance',
        'partners' => 'Partners',
        'employees' => 'Employees',
        'reports' => 'Reports',
        'administration' => 'Administration',
        'settings' => 'Settings',
        'audit' => 'Audit log',
        'custom' => 'Other permissions',
    ],

    'errors' => [
        'system_role_protected' => 'System roles cannot be renamed or deleted.',
        'role_in_use' => 'This role is still assigned to users. Reassign them first.',
    ],
];

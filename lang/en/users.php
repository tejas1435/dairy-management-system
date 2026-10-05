<?php

return [
    'title' => 'Users',
    'subtitle' => 'Accounts that can sign in to this application.',
    'create_title' => 'Add user',
    'edit_title' => 'Edit user',

    'created' => 'User :name has been created.',
    'updated' => 'User :name has been updated.',
    'activated' => ':name can sign in again.',
    'deactivated' => ':name has been deactivated and signed out.',

    'columns' => [
        'name' => 'Name',
        'email' => 'Email',
        'roles' => 'Roles',
        'language' => 'Language',
        'status' => 'Status',
        'last_login' => 'Last sign-in',
    ],

    'fields' => [
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'locale' => 'Language',
        'roles' => 'Roles',
        'is_active' => 'Account is active',
    ],

    'filters' => [
        'search' => 'Search name or email',
        'status' => 'Status',
        'role' => 'Role',
        'all_roles' => 'All roles',
    ],

    'help' => [
        'password_optional' => 'Leave blank to keep the current password.',
        'roles' => 'Permissions are granted through roles. A user with no role can sign in but can do nothing.',
        'inactive' => 'A deactivated user cannot sign in, and any session they already hold ends on their next request.',
        'no_delete' => 'Users are deactivated rather than deleted, so the records they created stay attributable.',
    ],

    'empty' => 'No users match these filters.',

    'errors' => [
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    ],
];

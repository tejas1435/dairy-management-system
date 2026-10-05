<?php

/*
 * Only the attribute names are defined here. Laravel resolves rule messages
 * from the framework's own English translations, so repeating them would mean
 * maintaining a copy that drifts out of date.
 *
 * Attribute names matter because they appear inside validation messages, and
 * "The email address field is required" reads better than "The email field is
 * required". Form Requests also supply attributes() for their own fields; this
 * file covers the ones used in more than one place.
 */

return [
    'attributes' => [
        'name' => 'name',
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'locale' => 'language',
        'roles' => 'roles',
        'permissions' => 'permissions',
        'is_active' => 'active status',
        'code' => 'code',
        'address' => 'address',
        'mobile' => 'mobile',
        'currency' => 'currency',
        'timezone' => 'timezone',
        'date_format' => 'date format',
        'default_locale' => 'default language',
        'legal_name' => 'legal name',
    ],
];

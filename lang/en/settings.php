<?php

return [
    'title' => 'Settings',

    'business' => [
        'title' => 'Business',
        'subtitle' => 'Business identity and regional defaults.',
        'updated' => 'Business settings saved.',
        'regional_heading' => 'Regional defaults',
        'contact_heading' => 'Contact details',

        'fields' => [
            'name' => 'Business name',
            'legal_name' => 'Legal name',
            'mobile' => 'Mobile',
            'email' => 'Email',
            'address' => 'Address',
            'currency' => 'Currency',
            'timezone' => 'Timezone',
            'date_format' => 'Date format',
            'default_locale' => 'Default language',
            'is_active' => 'Business is active',
        ],

        'help' => [
            'default_locale' => 'Used for new users and for anyone who has not chosen a language.',
            'currency' => 'Only INR is supported in this version.',
        ],
    ],

    'farm' => [
        'title' => 'Farms',
        'subtitle' => 'This version operates on one primary farm, which operational screens use automatically.',
        'create_title' => 'Add farm',
        'edit_title' => 'Edit farm',

        'created' => 'Farm :name has been added.',
        'updated' => 'Farm :name has been updated.',
        'primary_changed' => ':name is now the primary farm.',
        'activated' => 'Farm :name has been activated.',
        'deactivated' => 'Farm :name has been deactivated.',

        'columns' => [
            'name' => 'Farm',
            'code' => 'Code',
            'address' => 'Address',
            'status' => 'Status',
        ],

        'fields' => [
            'name' => 'Farm name',
            'code' => 'Code',
            'address' => 'Address',
            'is_active' => 'Farm is active',
        ],

        'make_primary' => 'Make primary',

        'help' => [
            'code' => 'A short identifier, unique within the business. Letters, numbers, dashes and underscores.',
            'primary' => 'Exactly one farm is primary. Promoting another farm demotes the current one in the same operation.',
            'no_selector' => 'Operational screens never ask which farm to use; they resolve the primary farm automatically.',
        ],

        'errors' => [
            'cannot_deactivate_primary' => 'The primary farm cannot be deactivated. Make another farm primary first.',
            'primary_must_be_active' => 'Only an active farm can be made primary.',
        ],
    ],

    'payment_methods' => [
        'title' => 'Payment Methods',
        'subtitle' => 'How money moved. Recorded only: nothing here processes a payment.',
        'created' => 'Payment method :name has been added.',
        'updated' => 'Payment method :name has been updated.',
        'activated' => 'Payment method :name has been activated.',
        'deactivated' => 'Payment method :name has been deactivated.',
        'deleted' => 'Payment method :name has been removed.',
        'add' => 'Add a method',

        'columns' => [
            'name' => 'Method',
            'code' => 'Code',
            'usage' => 'In use',
            'status' => 'Status',
        ],

        'fields' => [
            'name' => 'Display name',
            'code' => 'Code',
        ],

        'help' => [
            'code_fixed' => 'The code is the stable identifier the application matches on. Rename the display label freely; the code never changes.',
            'no_processing' => 'Selecting a method records how a payment happened. No gateway, UPI or banking API is involved.',
        ],

        'errors' => [
            'system_protected' => 'Seeded payment methods cannot be deleted. Deactivate it instead.',
            'in_use' => 'This method appears on posted records and cannot be deleted. Deactivate it instead.',
        ],
    ],

    'expense_categories' => [
        'title' => 'Expense Categories',
        'subtitle' => 'How expenses are grouped for reporting.',
        'created' => 'Category :name has been added.',
        'updated' => 'Category :name has been updated.',
        'activated' => 'Category :name has been activated.',
        'deactivated' => 'Category :name has been deactivated.',
        'deleted' => 'Category :name has been removed.',
        'add' => 'Add a category',

        'columns' => [
            'name' => 'Category',
            'code' => 'Code',
            'expenses' => 'Expenses',
            'status' => 'Status',
        ],

        'fields' => [
            'name' => 'Display name',
            'code' => 'Code',
        ],

        'help' => [
            'code_fixed' => 'The code is the stable identifier. Later phases look up the animal purchase category by code rather than by label.',
        ],

        'errors' => [
            'system_protected' => 'Seeded categories cannot be deleted. Deactivate it instead.',
            'in_use' => 'This category is used by existing expenses and cannot be deleted. Deactivate it instead.',
        ],
    ],

    'sales_channels' => [
        'title' => 'Sales Channels',
        'subtitle' => 'Where milk is sold. Add your own channels alongside the built-in ones.',
        'created' => 'Channel :name has been added.',
        'updated' => 'Channel :name has been updated.',
        'activated' => 'Channel :name has been activated.',
        'deactivated' => 'Channel :name has been deactivated.',
        'deleted' => 'Channel :name has been removed.',
        'add' => 'Add a channel',
        'system' => 'Built in',
        'custom' => 'Custom',

        'columns' => [
            'name' => 'Channel',
            'slug' => 'Identifier',
            'type' => 'Type',
            'buyers' => 'Buyers',
            'status' => 'Status',
        ],

        'fields' => [
            'name' => 'Display name',
            'slug' => 'Identifier',
        ],

        'help' => [
            'system_fixed' => 'Mandali, Vendor and Direct Customer are built in because later phases give them their own workflows. Their identifiers are fixed; their names are yours to change.',
            'custom_generic' => 'Custom channels use the generic sale entry and follow customer permissions.',
        ],

        'errors' => [
            'system_protected' => 'Built-in channels cannot be deleted. Deactivate it instead.',
            'in_use' => 'This channel has buyers and cannot be deleted. Deactivate it instead.',
            'wrong_business' => 'That channel belongs to another business.',
        ],
    ],
];

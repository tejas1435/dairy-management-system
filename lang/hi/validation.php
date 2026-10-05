<?php

/*
 * The rules a user actually hits in Phase 1 are translated here. Anything not
 * listed falls back to the English message rather than being left as an
 * untranslated key.
 */

return [
    'required' => ':attribute फ़ील्ड भरना आवश्यक है।',
    'email' => ':attribute एक मान्य ईमेल पता होना चाहिए।',
    'unique' => 'यह :attribute पहले से उपयोग में है।',
    'confirmed' => ':attribute की पुष्टि मेल नहीं खाती।',
    'boolean' => ':attribute फ़ील्ड सही या गलत होना चाहिए।',
    'in' => 'चुना गया :attribute मान्य नहीं है।',
    'exists' => 'चुना गया :attribute मान्य नहीं है।',
    'lowercase' => ':attribute छोटे अक्षरों में होना चाहिए।',
    'alpha_dash' => ':attribute में केवल अक्षर, अंक, डैश और अंडरस्कोर हो सकते हैं।',
    'current_password' => 'पासवर्ड गलत है।',

    'string' => [
        'min' => ':attribute कम से कम :min अक्षरों का होना चाहिए।',
        'max' => ':attribute :max अक्षरों से अधिक नहीं होना चाहिए।',
    ],

    'password' => [
        'letters' => ':attribute में कम से कम एक अक्षर होना चाहिए।',
        'mixed' => ':attribute में कम से कम एक बड़ा और एक छोटा अक्षर होना चाहिए।',
        'numbers' => ':attribute में कम से कम एक अंक होना चाहिए।',
        'symbols' => ':attribute में कम से कम एक चिह्न होना चाहिए।',
    ],

    'attributes' => [
        'name' => 'नाम',
        'email' => 'ईमेल पता',
        'password' => 'पासवर्ड',
        'password_confirmation' => 'पासवर्ड की पुष्टि',
        'current_password' => 'वर्तमान पासवर्ड',
        'locale' => 'भाषा',
        'roles' => 'भूमिकाएँ',
        'permissions' => 'अनुमतियाँ',
        'is_active' => 'सक्रिय स्थिति',
        'code' => 'कोड',
        'address' => 'पता',
        'mobile' => 'मोबाइल',
        'currency' => 'मुद्रा',
        'timezone' => 'समय क्षेत्र',
        'date_format' => 'दिनांक प्रारूप',
        'default_locale' => 'डिफ़ॉल्ट भाषा',
        'legal_name' => 'कानूनी नाम',
    ],
];

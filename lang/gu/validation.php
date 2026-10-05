<?php

/*
 * The rules a user actually hits in Phase 1 are translated here. Anything not
 * listed falls back to the English message rather than being left as an
 * untranslated key, which is preferable to a machine-translated wall of text
 * covering rules this application never uses.
 */

return [
    'required' => ':attribute ક્ષેત્ર ભરવું જરૂરી છે.',
    'email' => ':attribute માન્ય ઈમેલ સરનામું હોવું જોઈએ.',
    'unique' => 'આ :attribute પહેલેથી વપરાયેલું છે.',
    'confirmed' => ':attribute ની પુષ્ટિ મેળ ખાતી નથી.',
    'boolean' => ':attribute ક્ષેત્ર સાચું અથવા ખોટું હોવું જોઈએ.',
    'in' => 'પસંદ કરેલું :attribute માન્ય નથી.',
    'exists' => 'પસંદ કરેલું :attribute માન્ય નથી.',
    'lowercase' => ':attribute નાના અક્ષરોમાં હોવું જોઈએ.',
    'alpha_dash' => ':attribute માં ફક્ત અક્ષરો, અંકો, ડેશ અને અન્ડરસ્કોર હોઈ શકે છે.',
    'current_password' => 'પાસવર્ડ ખોટો છે.',

    'string' => [
        'min' => ':attribute ઓછામાં ઓછા :min અક્ષરોનું હોવું જોઈએ.',
        'max' => ':attribute :max અક્ષરોથી વધુ ન હોવું જોઈએ.',
    ],

    'password' => [
        'letters' => ':attribute માં ઓછામાં ઓછો એક અક્ષર હોવો જોઈએ.',
        'mixed' => ':attribute માં ઓછામાં ઓછો એક મોટો અને એક નાનો અક્ષર હોવો જોઈએ.',
        'numbers' => ':attribute માં ઓછામાં ઓછો એક અંક હોવો જોઈએ.',
        'symbols' => ':attribute માં ઓછામાં ઓછું એક ચિહ્ન હોવું જોઈએ.',
    ],

    'attributes' => [
        'name' => 'નામ',
        'email' => 'ઈમેલ સરનામું',
        'password' => 'પાસવર્ડ',
        'password_confirmation' => 'પાસવર્ડની પુષ્ટિ',
        'current_password' => 'વર્તમાન પાસવર્ડ',
        'locale' => 'ભાષા',
        'roles' => 'ભૂમિકાઓ',
        'permissions' => 'પરવાનગીઓ',
        'is_active' => 'સક્રિય સ્થિતિ',
        'code' => 'કોડ',
        'address' => 'સરનામું',
        'mobile' => 'મોબાઇલ',
        'currency' => 'ચલણ',
        'timezone' => 'સમય ઝોન',
        'date_format' => 'તારીખ ફોર્મેટ',
        'default_locale' => 'ડિફોલ્ટ ભાષા',
        'legal_name' => 'કાનૂની નામ',
    ],
];

<?php

declare(strict_types=1);

return [
    'accepted' => 'Devi accettare :attribute.',
    'boolean' => 'Il campo :attribute deve essere sì o no.',
    'confirmed' => 'Le due :attribute non coincidono.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo :attribute non è una data valida.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'in' => 'Il valore di :attribute non è valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'max' => [
        'numeric' => 'Il campo :attribute non può superare :max.',
        'string' => 'Il campo :attribute non può superare :max caratteri.',
    ],
    'min' => [
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve avere almeno :min caratteri.',
    ],
    'numeric' => 'Il campo :attribute deve essere un numero.',
    'password' => [
        'letters' => 'La password deve contenere almeno una lettera.',
        'mixed' => 'La password deve contenere maiuscole e minuscole.',
        'numbers' => 'La password deve contenere almeno un numero.',
        'symbols' => 'La password deve contenere almeno un simbolo.',
        'uncompromised' => 'Questa password compare in violazioni di dati note: scegline un\'altra.',
    ],
    'required' => 'Il campo :attribute è obbligatorio.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'unique' => 'Il valore di :attribute è già in uso.',
    'uuid' => 'Il campo :attribute non è valido.',

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'conferma della password',
        'current_password' => 'password attuale',
        'code' => 'codice',
        'recovery_code' => 'codice di recupero',
        'name' => 'nome',
    ],
];

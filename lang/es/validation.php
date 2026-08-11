<?php

declare(strict_types=1);

/*
 * The rules this app actually uses, in Spanish. Anything else falls back to
 * English through APP_FALLBACK_LOCALE, which is better than showing a raw key.
 */

return [

    'confirmed' => 'Las dos contraseñas no coinciden.',
    'current_password' => 'La contraseña actual es incorrecta.',
    'date' => 'Esa fecha no es válida.',
    'email' => 'Ese email no parece válido.',
    'exists' => 'No encontramos eso.',
    'file' => 'Tiene que ser un archivo.',
    'image' => 'Tiene que ser una imagen.',
    'in' => 'Ese valor no está entre las opciones.',
    'integer' => 'Tiene que ser un número entero.',
    'mimetypes' => 'Ese formato de archivo no lo podemos leer.',
    'required' => 'Falta completar esto.',
    'string' => 'Tiene que ser texto.',
    'unique' => 'Eso ya está en uso.',

    'max' => [
        'array' => 'No puede tener más de :max elementos.',
        'file' => 'El archivo no puede pesar más de :max kilobytes.',
        'numeric' => 'No puede ser mayor que :max.',
        'string' => 'No puede tener más de :max caracteres.',
    ],

    'min' => [
        'array' => 'Tiene que tener al menos :min elementos.',
        'file' => 'El archivo tiene que pesar al menos :min kilobytes.',
        'numeric' => 'Tiene que ser al menos :min.',
        'string' => 'Tiene que tener al menos :min caracteres.',
    ],

    'password' => [
        'letters' => 'La contraseña tiene que tener al menos una letra.',
        'mixed' => 'La contraseña tiene que tener mayúsculas y minúsculas.',
        'numbers' => 'La contraseña tiene que tener al menos un número.',
        'symbols' => 'La contraseña tiene que tener al menos un símbolo.',
        'uncompromised' => 'Esa contraseña apareció en una filtración. Elegí otra.',
    ],

    'attributes' => [
        'current_password' => 'contraseña actual',
        'email' => 'email',
        'goalEmoji' => 'emoji',
        'goalName' => 'nombre',
        'name' => 'nombre',
        'note' => 'nota',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'photo' => 'foto',
        'timezone' => 'zona horaria',
    ],

];

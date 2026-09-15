<?php

/*
|--------------------------------------------------------------------------
| Authentication language lines
|--------------------------------------------------------------------------
|
| APP_LOCALE is already "es", but the app shipped without a lang directory,
| so every line fell through to Laravel's English defaults and the login page
| answered a Spanish form in English. These are the three lines Fortify puts
| in front of a visitor.
|
*/

return [
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña es incorrecta.',
    'throttle' => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',
];

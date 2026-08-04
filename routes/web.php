<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| The marketing site (home, servicios, blog, podcast…) lands here later.
| For now the root sends you to the portal so the auth flow is testable.
*/

Route::get('/', function () {
    return redirect()->route('portal.home');
})->name('home');

Route::view('/legal/privacidad', 'legal.placeholder')->name('legal.privacy');
Route::view('/legal/terminos', 'legal.placeholder')->name('legal.terms');

/*
|--------------------------------------------------------------------------
| Portal (cliente)
|--------------------------------------------------------------------------
| Placeholder only. The real portal — dashboard, proyecto, estrategia,
| entregas, IA Studio — is built out in the next phase. This exists so
| Fortify has somewhere to land after a successful login.
*/

Route::middleware(['auth'])->group(function () {
    Route::view('/portal', 'portal.placeholder')->name('portal.home');
});

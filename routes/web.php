<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ContextDocumentController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| The marketing site (home, servicios, blog, podcast…) lands here later.
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->to(auth()->user()->homeRoute())
        : redirect()->route('login');
})->name('home');

Route::view('/legal/privacidad', 'legal.placeholder')->name('legal.privacy');
Route::view('/legal/terminos', 'legal.placeholder')->name('legal.terms');

/*
|--------------------------------------------------------------------------
| Admin — Breakfast staff only
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'breakfast'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('home');

        Route::get('clientes', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clientes/nueva', [ClientController::class, 'create'])->name('clients.create');
        Route::post('clientes', [ClientController::class, 'store'])->name('clients.store');
        Route::get('clientes/{client}', [ClientController::class, 'show'])->name('clients.show');

        Route::post('clientes/{client}/contexto', [ContextDocumentController::class, 'store'])
            ->name('clients.context.store');
        Route::get('clientes/{client}/contexto/{document}', [ContextDocumentController::class, 'download'])
            ->name('clients.context.download');
        Route::delete('clientes/{client}/contexto/{document}', [ContextDocumentController::class, 'destroy'])
            ->name('clients.context.destroy');
    });

/*
|--------------------------------------------------------------------------
| Portal — client users
|--------------------------------------------------------------------------
| Placeholder. Dashboard, proyecto, estrategia, entregas and the IA Studio
| are built out next.
*/

Route::middleware(['auth'])->group(function () {
    Route::view('/portal', 'portal.placeholder')->name('portal.home');
});

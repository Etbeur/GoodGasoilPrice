<?php

use App\Http\Controllers\FuelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web — GoodGasoilPrice
|--------------------------------------------------------------------------
|
| Une seule route publique en V1 :
|   GET / → Affichage des prix théoriques des carburants
|
| Aucune authentification, aucune session utilisateur nécessaire.
| Le cache est géré au niveau du service (FuelPriceService).
|
*/

Route::get('/', [FuelController::class, 'index'])->name('fuel.index');

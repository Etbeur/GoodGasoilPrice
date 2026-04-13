<?php

namespace App\Http\Controllers;

use App\Services\FuelPriceService;
use Illuminate\View\View;

/**
 * Contrôleur principal de l'application GoodGasoilPrice.
 *
 * Rôle unique : déléguer le calcul au FuelPriceService,
 * puis passer les données formatées à la vue Blade.
 *
 * Aucune logique métier ici — tout est dans le service.
 */
class FuelController extends Controller
{
    public function __construct(
        private readonly FuelPriceService $fuelService
    ) {}

    /**
     * Affiche la page principale avec les prix théoriques des carburants.
     *
     * Route : GET /
     */
    public function index(): View
    {
        // Appel au service : récupération des données marché + calcul des prix
        $donnees = $this->fuelService->getPrixTheorique();

        return view('fuel.index', [
            'carburants'          => $donnees['carburants'],
            'brentUsd'            => $donnees['brent_usd'],
            'eurUsd'              => $donnees['eur_usd'],
            'gasoilRotterdamUsd'  => $donnees['gasoil_rotterdam_usd'],
            'miseAJour'           => $donnees['mise_a_jour'],
            'sources'             => $donnees['sources'],
            'erreur'              => $donnees['erreur'],
        ]);
    }
}

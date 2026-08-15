<?php

namespace App\Http\Controllers;

use App\Services\FuelPriceService;
use App\Services\ObservedFuelPriceService;
use Illuminate\View\View;

/**
 * Contrôleur principal de l'application GoodGasoilPrice.
 *
 * Rôle : orchestrer la récupération des prix théoriques estimés
 * et des moyennes nationales observées (flux officiel DGCCRF / Open Data),
 * puis passer les données à la vue Blade.
 */
class FuelController extends Controller
{
    public function __construct(
        private readonly FuelPriceService $fuelService,
        private readonly ObservedFuelPriceService $observedFuelService
    ) {}

    /**
     * Affiche la page principale avec les prix théoriques et les prix moyens observés.
     *
     * Route : GET /
     */
    public function index(): View
    {
        // 1. Récupération des prix théoriques calculés (Brent, USD vers EUR, taxes)
        $donneesTheoriques = $this->fuelService->getPrixTheorique();

        // 2. Récupération des prix moyens nationaux constatés (Open Data Ministère de l'Économie)
        $donneesObservees = $this->observedFuelService->getPrixObserves();

        return view('fuel.index', [
            'carburants' => $donneesTheoriques['carburants'],
            'brentUsd' => $donneesTheoriques['brent_usd'],
            'usdEur' => $donneesTheoriques['usd_eur'],
            'gasoilRotterdamUsd' => $donneesTheoriques['gasoil_rotterdam_usd'],
            'miseAJour' => $donneesTheoriques['mise_a_jour'],
            'sources' => $donneesTheoriques['sources'],
            'erreur' => $donneesTheoriques['erreur'],
            'prixObserves' => $donneesObservees,
        ]);
    }
}

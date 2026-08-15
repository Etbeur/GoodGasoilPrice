<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service de calcul du prix théorique des carburants à la pompe en France.
 *
 * Ce service orchestre :
 *  1. La récupération du cours du pétrole Brent (Yahoo Finance, fallback Alpha Vantage)
 *  2. La récupération du taux de change USD vers EUR (Frankfurter API / BCE, dernier jour ouvré)
 *  3. Le calcul du prix théorique par carburant selon la formule UFIP/FIPECO/CLCV
 *
 * Toutes les données sont mises en cache 1 heure (driver fichier).
 * Aucune clé API n'est exposée côté client.
 */
class FuelPriceService
{
    /**
     * URL de l'API Yahoo Finance pour le cours du Brent (symbole BZ=F).
     * API non officielle, gratuite, sans clé requise.
     */
    private const YAHOO_FINANCE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F';

    /**
     * URL de l'API Frankfurter pour le taux USD vers EUR.
     * API adossée aux données de référence de la BCE (dernier jour ouvré), gratuite, sans clé requise.
     */
    private const FRANKFURTER_URL = 'https://api.frankfurter.app/latest?from=USD&to=EUR';

    /**
     * URL de l'API Yahoo Finance pour le Heating Oil NYMEX (ticker HO=F).
     * Cotation en USD par gallon US — proxy du gasoil ARA Rotterdam.
     * HO=F (ULSD, Ultra Low Sulfur Diesel) est fortement corrélé à l'ICE Gasoil
     * et constitue la meilleure alternative gratuite disponible sur Yahoo Finance.
     * Utilisé exclusivement pour le calcul du Gazole, en remplacement du Brent.
     * Note : ICE Low Sulphur Gasoil Futures (LSG=F) n'est pas disponible sur Yahoo Finance.
     */
    private const YAHOO_GASOIL_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/HO=F';

    /**
     * URL de l'API Alpha Vantage pour le cours du Brent (fallback).
     * Clé API gratuite requise dans .env : ALPHA_VANTAGE_KEY
     */
    private const ALPHA_VANTAGE_URL = 'https://www.alphavantage.co/query';

    /**
     * Durée de mise en cache des données marché (en secondes).
     * Récupérée depuis config/fuel.php — 3600s = 1 heure.
     */
    private int $cacheTtl;

    public function __construct()
    {
        $this->cacheTtl = config('fuel.cache_ttl', 3600);
    }

    // -------------------------------------------------------------------------
    // Point d'entrée principal
    // -------------------------------------------------------------------------

    /**
     * Calcule les prix théoriques de tous les carburants configurés.
     *
     * @return array{
     *   carburants: array,
     *   brent_usd: float|null,
     *   usd_eur: float|null,
     *   gasoil_rotterdam_usd: float|null,
     *   mise_a_jour: string,
     *   sources: array,
     *   erreur: string|null
     * }
     */
    public function getPrixTheorique(): array
    {
        // Récupération des données marché (avec cache 1 heure)
        $brentUsd = $this->getBrentPrice();
        $usdEur = $this->getUsdEurRate();
        $gasoilUsdPerGallon = $this->getGasoilRotterdamPrice(); // HO=F en USD/gallon, null si indisponible (fallback Brent)

        // Si les données marché de base sont indisponibles, on retourne une erreur propre
        if ($brentUsd === null || $usdEur === null) {
            return $this->reponseErreur(
                'Les données de marché sont temporairement indisponibles. Veuillez réessayer dans quelques minutes.'
            );
        }

        // Calcul du prix théorique pour chaque carburant configuré
        $carburants = [];
        foreach (config('fuel.carburants') as $cle => $meta) {
            $carburants[$cle] = $this->calculerPrixCarburant($cle, $brentUsd, $usdEur, $meta, $gasoilUsdPerGallon);
        }

        return [
            'carburants' => $carburants,
            'brent_usd' => round($brentUsd, 2),
            'usd_eur' => round($usdEur, 4),
            'gasoil_rotterdam_usd' => $gasoilUsdPerGallon !== null ? round($gasoilUsdPerGallon, 4) : null,
            'mise_a_jour' => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'sources' => $this->getSources($gasoilUsdPerGallon !== null),
            'erreur' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Récupération du cours du Brent
    // -------------------------------------------------------------------------

    /**
     * Retourne le cours du pétrole Brent en USD.
     * Source principale : Yahoo Finance (BZ=F).
     * Fallback : Alpha Vantage si Yahoo Finance échoue.
     * Résultat mis en cache 1 heure.
     *
     * @return float|null Cours en USD, ou null si toutes les sources échouent
     */
    private function getBrentPrice(): ?float
    {
        return Cache::remember('brent_price', $this->cacheTtl, function () {
            // Tentative 1 : Yahoo Finance (gratuit, sans clé)
            $prix = $this->fetchBrentYahoo();

            if ($prix !== null) {
                return $prix;
            }

            // Tentative 2 : Alpha Vantage (fallback, clé requise dans .env)
            Log::warning('[FuelService] Yahoo Finance indisponible, tentative Alpha Vantage');
            $prix = $this->fetchBrentAlphaVantage();

            if ($prix === null) {
                Log::error('[FuelService] Impossible de récupérer le cours du Brent (toutes sources épuisées)');
            }

            return $prix;
        });
    }

    // -------------------------------------------------------------------------
    // Récupération de la cotation Gasoil Rotterdam (proxy NYMEX HO=F)
    // -------------------------------------------------------------------------

    /**
     * Retourne la cotation Heating Oil NYMEX en USD par gallon.
     * Ticker Yahoo Finance : HO=F — proxy du marché ARA (Amsterdam-Rotterdam-Anvers).
     * Résultat mis en cache 1 heure.
     *
     * @return float|null Cotation en USD/gallon, ou null si indisponible
     */
    private function getGasoilRotterdamPrice(): ?float
    {
        return Cache::remember('gasoil_rotterdam_price', $this->cacheTtl, function () {
            $prix = $this->fetchGasoilRotterdamYahoo();

            if ($prix === null) {
                Log::warning('[FuelService] Cotation HO=F indisponible — fallback Brent activé pour le Gazole');
            }

            return $prix;
        });
    }

    /**
     * Récupère la cotation Heating Oil NYMEX depuis Yahoo Finance.
     * Ticker : HO=F — unité retournée : USD par gallon US.
     */
    private function fetchGasoilRotterdamYahoo(): ?float
    {
        try {
            $reponse = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; GoodGasoilPrice/1.0)',
                ])
                ->get(self::YAHOO_GASOIL_URL, [
                    'interval' => '1m',
                    'range' => '1d',
                ]);

            if (! $reponse->successful()) {
                Log::warning('[FuelService] Yahoo Finance HO=F HTTP '.$reponse->status());

                return null;
            }

            $donnees = $reponse->json();
            $prix = $donnees['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;

            if ($prix === null || $prix <= 0) {
                Log::warning('[FuelService] Cotation HO=F introuvable dans la réponse JSON Yahoo');

                return null;
            }

            return (float) $prix;

        } catch (\Throwable $e) {
            Log::warning('[FuelService] Cotation HO=F Yahoo Finance indisponible', [
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Récupère le cours du Brent depuis Yahoo Finance (API non officielle).
     * Symbole : BZ=F (Brent Crude Oil Futures).
     */
    private function fetchBrentYahoo(): ?float
    {
        try {
            $reponse = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; GoodGasoilPrice/1.0)',
                ])
                ->get(self::YAHOO_FINANCE_URL, [
                    'interval' => '1m',
                    'range' => '1d',
                ]);

            if (! $reponse->successful()) {
                Log::warning('[FuelService] Yahoo Finance HTTP '.$reponse->status());

                return null;
            }

            $donnees = $reponse->json();
            $prix = $donnees['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;

            if ($prix === null || $prix <= 0) {
                Log::warning('[FuelService] Cours Brent Yahoo introuvable dans la réponse JSON');

                return null;
            }

            return (float) $prix;

        } catch (\Throwable $e) {
            Log::warning('[FuelService] Cours Brent Yahoo Finance indisponible', [
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Récupère le cours du Brent depuis Alpha Vantage (fallback).
     */
    private function fetchBrentAlphaVantage(): ?float
    {
        $cle = env('ALPHA_VANTAGE_KEY');

        if (empty($cle)) {
            Log::warning('[FuelService] ALPHA_VANTAGE_KEY absente dans .env — fallback désactivé');

            return null;
        }

        try {
            $reponse = Http::timeout(10)->get(self::ALPHA_VANTAGE_URL, [
                'function' => 'BRENT',
                'interval' => 'daily',
                'apikey' => $cle,
            ]);

            if (! $reponse->successful()) {
                Log::warning('[FuelService] Alpha Vantage HTTP '.$reponse->status());

                return null;
            }

            $donnees = $reponse->json();
            $derniere = $donnees['data'][0]['value'] ?? null;

            if ($derniere === null || $derniere === '.' || (float) $derniere <= 0) {
                Log::warning('[FuelService] Cours Brent Alpha Vantage introuvable dans la réponse JSON');

                return null;
            }

            return (float) $derniere;

        } catch (\Throwable $e) {
            Log::warning('[FuelService] Cours Brent Alpha Vantage indisponible', [
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Récupération du taux de change USD vers EUR
    // -------------------------------------------------------------------------

    /**
     * Retourne le taux de change USD vers EUR depuis l'API Frankfurter (BCE, dernier jour ouvré).
     * Gratuit, sans clé, adossé aux taux de référence de la Banque Centrale Européenne.
     * Résultat mis en cache 1 heure.
     *
     * @return float|null Nombre d'euros pour 1 USD, ou null si indisponible
     */
    private function getUsdEurRate(): ?float
    {
        return Cache::remember('usd_eur_rate', $this->cacheTtl, function () {
            try {
                $reponse = Http::timeout(10)->get(self::FRANKFURTER_URL);

                if (! $reponse->successful()) {
                    Log::error('[FuelService] Frankfurter API HTTP '.$reponse->status());

                    return null;
                }

                $donnees = $reponse->json();

                // Structure Frankfurter pour from=USD&to=EUR : {"amount":1.0,"base":"USD","date":"...","rates":{"EUR":0.8645}}
                $taux = $donnees['rates']['EUR'] ?? null;

                if ($taux === null || ! is_numeric($taux) || (float) $taux <= 0) {
                    Log::error('[FuelService] Taux USD vers EUR introuvable dans la réponse Frankfurter');

                    return null;
                }

                return (float) $taux;

            } catch (\Throwable $e) {
                Log::error('[FuelService] Taux USD vers EUR Frankfurter API indisponible', [
                    'exception_type' => $e::class,
                ]);

                return null;
            }
        });
    }

    // -------------------------------------------------------------------------
    // Calcul du prix théorique
    // -------------------------------------------------------------------------

    /**
     * Calcule le prix théorique TTC d'un carburant donné.
     *
     * Formule générale (source : UFIP / FIPECO / CLCV) :
     *   1. Coût matière par litre (méthode selon filière)
     *   2. + Marge distribution (0.10–0.32 €/L selon filière)
     *   3. + Accise fixe (loi de finances 2026)
     *   4. TVA 20 % sur (HT + accise)
     *
     * @param  string  $cle  Identifiant du carburant (ex: 'sp95_e10')
     * @param  float  $brentUsd  Cours du Brent en USD/baril
     * @param  float  $usdEur  Taux de change USD vers EUR (nombre d'euros pour 1 USD)
     * @param  array  $meta  Métadonnées du carburant (nom, description, couleur)
     * @param  float|null  $gasoilUsdPerGallon  Cotation NYMEX HO=F en USD/gallon (null = fallback Brent)
     */
    private function calculerPrixCarburant(
        string $cle,
        float $brentUsd,
        float $usdEur,
        array $meta,
        ?float $gasoilUsdPerGallon = null
    ): array {

        // -- Étape 1 : Coût matière par litre (méthode selon filière) ----------

        if ($cle === 'gazole' && $gasoilUsdPerGallon !== null) {

            // --- Gazole : cotation NYMEX Heating Oil (HO=F) — proxy gasoil ARA Rotterdam ---
            $litresParGallon = config('fuel.gasoil_litres_per_gallon', 3.78541);
            $araPremium = config('fuel.gasoil_ara_premium', 0.06);
            $coutAvantDistrib = ($gasoilUsdPerGallon / $litresParGallon) * $usdEur + $araPremium;
            $labelMatiere = 'Cotation NYMEX Heating Oil (proxy gasoil ARA)';
            $lsgUsdTonne = round($gasoilUsdPerGallon, 4);

        } else {

            // --- Formule standard : Brent + marge raffinage ---
            // Brent USD → EUR/litre : (USD/baril / 159 litres) × taux USD vers EUR
            $litresParBaril = config('fuel.litres_par_baril', 159);
            $coutBrutEur = ($brentUsd / $litresParBaril) * $usdEur;

            // -- Exception E85 : formule hybride pétrole + éthanol agricole ----
            if ($cle === 'e85') {
                $ethanolEurL = config('fuel.ethanol_cost_per_liter', 0.42);
                $coutBrutEur = (0.15 * $coutBrutEur) + (0.85 * $ethanolEurL);
            }

            $margeRaffinage = config("fuel.marges_raffinage.{$cle}", 0.07);
            $coutAvantDistrib = $coutBrutEur + $margeRaffinage;
            $labelMatiere = 'Brut + raffinage';
            $lsgUsdTonne = null;
        }

        // -- Étape 2 : Marge de distribution ----------------------------------
        $margeDistrib = config("fuel.marges_distribution.{$cle}", 0.32);
        $coutHtSansAccise = $coutAvantDistrib + $margeDistrib;

        // -- Étape 3 : Accise (TICPE) -----------------------------------------
        $accise = config("fuel.accises.{$cle}", 0.6829);

        // -- Étape 4 : TVA 20 % sur (HT + accise) ----------------------------
        $tva = config('fuel.tva', 0.20);
        $prixTtc = ($coutHtSansAccise + $accise) * (1 + $tva);

        // -- Fourchette d'incertitude ±0.10 € ---------------------------------
        $fourchette = config('fuel.fourchette', 0.10);

        // Part TVA en euros (pour l'affichage pédagogique)
        $montantTva = ($coutHtSansAccise + $accise) * $tva;

        return [
            // Identifiant et métadonnées
            'cle' => $cle,
            'nom' => $meta['nom'],
            'description' => $meta['description'],
            'couleur' => $meta['couleur'],

            // Prix final TTC et fourchette
            'prix_ttc' => round($prixTtc, 3),
            'prix_min' => round($prixTtc - $fourchette, 3),
            'prix_max' => round($prixTtc + $fourchette, 3),

            // Libellé dynamique de la première ligne de décomposition
            'label_matiere' => $labelMatiere,

            // Cotation en USD/gallon pour le Gazole
            'lsg_usd_tonne' => $lsgUsdTonne,

            // Décomposition pour l'affichage pédagogique
            'detail' => [
                'brut_raffinage' => round($coutAvantDistrib, 4),
                'distribution' => round($margeDistrib, 4),
                'accise' => round($accise, 4),
                'tva' => round($montantTva, 4),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Métadonnées et utilitaires
    // -------------------------------------------------------------------------

    /**
     * Retourne la liste des sources de données utilisées pour l'affichage.
     */
    private function getSources(bool $gasoilIce = false): array
    {
        $sources = [
            'Cours Brent' => 'Yahoo Finance (BZ=F) — marché à terme ICE (dernières données disponibles)',
            'Taux de change USD vers EUR' => 'Frankfurter API — taux de référence de la Banque Centrale Européenne (dernier jour ouvré)',
            'Accises (TICPE)' => 'UFIP / FIPECO / DGDDI — Loi de finances 2026',
            'Marges raffinage' => 'Estimations moyennes 2025-2026 (UFIP / IFPen)',
            'Marge distribution' => 'CLCV — Rapport marges distribution mars 2026',
        ];

        if ($gasoilIce) {
            $sources['Gazole — proxy NYMEX'] = 'Yahoo Finance (HO=F) — Heating Oil NYMEX (ULSD), proxy du gasoil ARA Rotterdam';
        }

        return $sources;
    }

    /**
     * Structure de réponse en cas d'erreur de récupération des données marché.
     */
    private function reponseErreur(string $message): array
    {
        return [
            'carburants' => [],
            'brent_usd' => null,
            'usd_eur' => null,
            'gasoil_rotterdam_usd' => null,
            'mise_a_jour' => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'sources' => $this->getSources(),
            'erreur' => $message,
        ];
    }
}

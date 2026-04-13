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
 *  2. La récupération du taux EUR/USD (Frankfurter API / BCE)
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
     * URL de l'API Frankfurter pour le taux EUR/USD.
     * API officielle BCE, gratuite, sans clé requise.
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
     *   brent_usd: float,
     *   eur_usd: float,
     *   mise_a_jour: string,
     *   sources: array,
     *   erreur: string|null
     * }
     */
    public function getPrixTheorique(): array
    {
        // Récupération des données marché (avec cache 1 heure)
        $brentUsd         = $this->getBrentPrice();
        $eurUsd           = $this->getEurUsdRate();
        $gasoilUsdPerGallon = $this->getGasoilRotterdamPrice(); // HO=F en USD/gallon, null si indisponible (fallback Brent)

        // Si les données marché de base sont indisponibles, on retourne une erreur propre
        if ($brentUsd === null || $eurUsd === null) {
            return $this->reponseErreur(
                'Les données de marché sont temporairement indisponibles. Veuillez réessayer dans quelques minutes.'
            );
        }

        // Calcul du prix théorique pour chaque carburant configuré
        $carburants = [];
        foreach (config('fuel.carburants') as $cle => $meta) {
            $carburants[$cle] = $this->calculerPrixCarburant($cle, $brentUsd, $eurUsd, $meta, $gasoilUsdPerGallon);
        }

        return [
            'carburants'           => $carburants,
            'brent_usd'            => round($brentUsd, 2),
            'eur_usd'              => round($eurUsd, 4),
            'gasoil_rotterdam_usd' => $gasoilUsdPerGallon !== null ? round($gasoilUsdPerGallon, 4) : null,
            'mise_a_jour'          => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'sources'              => $this->getSources($gasoilUsdPerGallon !== null),
            'erreur'               => null,
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
    // Récupération de la cotation Gasoil Rotterdam (ICE LSG=F)
    // -------------------------------------------------------------------------

    /**
     * Retourne la cotation ICE Low Sulphur Gasoil Futures en USD par tonne métrique.
     * Ticker Yahoo Finance : LSG=F — marché ARA (Amsterdam-Rotterdam-Anvers).
     * Référence de prix utilisée par l'UFIP pour le gasoil européen.
     * Résultat mis en cache 1 heure.
     *
     * Si la cotation est indisponible, retourne null : le calculateur bascule
     * automatiquement sur le fallback Brent + marge raffinage 0.43 €/L.
     *
     * @return float|null Cotation en USD/tonne, ou null si indisponible
     */
    private function getGasoilRotterdamPrice(): ?float
    {
        return Cache::remember('gasoil_rotterdam_price', $this->cacheTtl, function () {
            $prix = $this->fetchGasoilRotterdamYahoo();

            if ($prix === null) {
                Log::warning('[FuelService] Cotation LSG=F indisponible — fallback Brent activé pour le Gazole');
            }

            return $prix;
        });
    }

    /**
     * Récupère la cotation ICE Low Sulphur Gasoil depuis Yahoo Finance.
     * Ticker : LSG=F — unité retournée : USD par tonne métrique.
     *
     * @return float|null
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
                    'range'    => '1d',
                ]);

            if (! $reponse->successful()) {
                Log::warning('[FuelService] Yahoo Finance LSG=F HTTP ' . $reponse->status());
                return null;
            }

            $donnees = $reponse->json();

            // Structure identique à BZ=F : result[0] -> meta -> regularMarketPrice
            $prix = $donnees['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;

            if ($prix === null || $prix <= 0) {
                Log::warning('[FuelService] Cotation LSG=F introuvable dans la réponse JSON Yahoo');
                return null;
            }

            return (float) $prix;

        } catch (\Exception $e) {
            Log::warning('[FuelService] Exception Yahoo Finance LSG=F : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère le cours du Brent depuis Yahoo Finance (API non officielle).
     * Symbole : BZ=F (Brent Crude Oil Futures).
     *
     * @return float|null
     */
    private function fetchBrentYahoo(): ?float
    {
        try {
            $reponse = Http::timeout(10)
                ->withHeaders([
                    // En-tête User-Agent requis par Yahoo Finance pour éviter le blocage
                    'User-Agent' => 'Mozilla/5.0 (compatible; GoodGasoilPrice/1.0)',
                ])
                ->get(self::YAHOO_FINANCE_URL, [
                    'interval' => '1m',
                    'range'    => '1d',
                ]);

            if (! $reponse->successful()) {
                Log::warning('[FuelService] Yahoo Finance HTTP ' . $reponse->status());
                return null;
            }

            $donnees = $reponse->json();

            // Extraction du dernier cours depuis la structure JSON Yahoo Finance
            // Chemin : result[0] -> meta -> regularMarketPrice
            $prix = $donnees['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;

            if ($prix === null || $prix <= 0) {
                Log::warning('[FuelService] Cours Brent Yahoo introuvable dans la réponse JSON');
                return null;
            }

            return (float) $prix;

        } catch (\Exception $e) {
            Log::warning('[FuelService] Exception Yahoo Finance : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère le cours du Brent depuis Alpha Vantage (fallback).
     * Nécessite ALPHA_VANTAGE_KEY dans .env.
     * Fonction : BRENT (prix quotidien du brut Brent).
     *
     * @return float|null
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
                'apikey'   => $cle,
            ]);

            if (! $reponse->successful()) {
                Log::warning('[FuelService] Alpha Vantage HTTP ' . $reponse->status());
                return null;
            }

            $donnees = $reponse->json();

            // Extraction de la dernière valeur journalière
            // Structure Alpha Vantage BRENT : data[0].value
            $derniere = $donnees['data'][0]['value'] ?? null;

            if ($derniere === null || $derniere === '.' || (float) $derniere <= 0) {
                Log::warning('[FuelService] Cours Brent Alpha Vantage introuvable dans la réponse JSON');
                return null;
            }

            return (float) $derniere;

        } catch (\Exception $e) {
            Log::warning('[FuelService] Exception Alpha Vantage : ' . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Récupération du taux EUR/USD
    // -------------------------------------------------------------------------

    /**
     * Retourne le taux EUR/USD depuis l'API Frankfurter (BCE).
     * Gratuit, sans clé, maintenu par la Banque Centrale Européenne.
     * Résultat mis en cache 1 heure.
     *
     * @return float|null Nombre d'euros pour 1 USD, ou null si indisponible
     */
    private function getEurUsdRate(): ?float
    {
        return Cache::remember('eur_usd_rate', $this->cacheTtl, function () {
            try {
                $reponse = Http::timeout(10)->get(self::FRANKFURTER_URL);

                if (! $reponse->successful()) {
                    Log::error('[FuelService] Frankfurter API HTTP ' . $reponse->status());
                    return null;
                }

                $donnees = $reponse->json();

                // Structure Frankfurter : {"rates": {"EUR": 0.862}}
                $taux = $donnees['rates']['EUR'] ?? null;

                if ($taux === null || $taux <= 0) {
                    Log::error('[FuelService] Taux EUR/USD introuvable dans la réponse Frankfurter');
                    return null;
                }

                return (float) $taux;

            } catch (\Exception $e) {
                Log::error('[FuelService] Exception Frankfurter API : ' . $e->getMessage());
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
     *   1. Coût matière par litre (méthode selon filière, voir ci-dessous)
     *   2. + Marge distribution (0.10–0.32 €/L selon filière)
     *   3. + Accise fixe (loi de finances 2026)
     *   4. TVA 20 % sur (HT + accise)
     *
     * Méthode de calcul du coût matière :
     *   - Gazole (avec HO=F disponible) : cotation NYMEX Heating Oil en USD/gallon,
     *     convertie en EUR/litre via 1 gallon US = 3.78541 litres. Proxy ARA Rotterdam.
     *   - Gazole (fallback) : Brent + marge raffinage 0.43 €/L si HO=F indisponible.
     *   - E85 : formule hybride 15 % Brent + 85 % éthanol agricole. Source : UFC-Que Choisir.
     *   - Autres : (Brent USD / 159 litres) × EUR/USD + marge raffinage.
     *
     * @param  string     $cle                Identifiant du carburant (ex: 'sp95_e10')
     * @param  float      $brentUsd           Cours du Brent en USD/baril
     * @param  float      $eurUsd             Taux EUR/USD (euros pour 1 dollar, source Frankfurter/BCE)
     * @param  array      $meta               Métadonnées du carburant (nom, description, couleur)
     * @param  float|null $gasoilUsdPerGallon Cotation NYMEX HO=F en USD/gallon (null = fallback Brent)
     * @return array
     */
    private function calculerPrixCarburant(
        string $cle,
        float $brentUsd,
        float $eurUsd,
        array $meta,
        ?float $gasoilUsdPerGallon = null
    ): array {

        // -- Étape 1 : Coût matière par litre (méthode selon filière) ----------

        if ($cle === 'gazole' && $gasoilUsdPerGallon !== null) {

            // --- Gazole : cotation NYMEX Heating Oil (HO=F) — proxy gasoil ARA Rotterdam ---
            // ICE Low Sulphur Gasoil Futures (LSG=F) n'étant pas disponible gratuitement,
            // on utilise le Heating Oil NYMEX (ULSD), distillat fortement corrélé au gasoil ARA.
            //
            // Conversion USD/gallon → EUR/litre + prime ARA :
            //   1 gallon US = 3.78541 litres (constante)
            //   EUR/L       = (USD/gallon / 3.78541) × (EUR/USD) + prime_ARA
            //
            // La prime ARA (+0.06 €/L) corrige le spread structurel entre le marché US
            // (NYMEX New York) et le marché européen ARA (Amsterdam-Rotterdam-Anvers) :
            // coûts logistiques transatlantiques, tensions d'approvisionnement européennes,
            // obligations TIRUERT spécifiques à la France.
            // La cotation intègre le raffinage : pas de marge raffinage ajoutée.
            $litresParGallon  = config('fuel.gasoil_litres_per_gallon', 3.78541);
            $araPremium       = config('fuel.gasoil_ara_premium', 0.06);
            $coutAvantDistrib = ($gasoilUsdPerGallon / $litresParGallon) * $eurUsd + $araPremium;
            $labelMatiere     = 'Cotation NYMEX Heating Oil (proxy gasoil ARA)';
            $lsgUsdTonne      = round($gasoilUsdPerGallon, 4); // stocké en USD/gallon malgré le nom

        } else {

            // --- Formule standard : Brent + marge raffinage ---
            // Fallback pour Gazole si LSG=F indisponible, et méthode normale pour tous les autres.
            //
            // Brent USD → EUR/litre : (USD/baril / 159 litres) × EUR/USD
            // L'API Frankfurter retourne "EUR par USD" → multiplication directe.
            // Source calcul : méthode standard UFIP / IFP Énergies nouvelles.
            $litresParBaril = config('fuel.litres_par_baril', 159);
            $coutBrutEur    = ($brentUsd / $litresParBaril) * $eurUsd;

            // -- Exception E85 : formule hybride pétrole + éthanol agricole ----
            // L'E85 contient 85 % d'éthanol agricole (blé/betterave, découplé du Brent)
            // et seulement 15 % d'essence. Appliquer Brent sur 100 % surestime l'E85.
            // Source : Connaissance des Energies, UFC-Que Choisir mars 2026.
            if ($cle === 'e85') {
                $ethanolEurL = config('fuel.ethanol_cost_per_liter', 0.60);
                $coutBrutEur = (0.15 * $coutBrutEur) + (0.85 * $ethanolEurL);
            }

            // Marge de raffinage selon carburant — volatiles en période de tension.
            // Source : estimations UFIP / IFP Énergies nouvelles 2025-2026.
            $margeRaffinage   = config("fuel.marges_raffinage.{$cle}", 0.07);
            $coutAvantDistrib = $coutBrutEur + $margeRaffinage;
            $labelMatiere     = 'Brut + raffinage';
            $lsgUsdTonne      = null;
        }

        // -- Étape 2 : Marge de distribution ----------------------------------
        // Couvre : transport dépôt, stockage, transfert station, exploitation station,
        // CEE (jusqu'à 0.15 €/L en 2026) et TIRUERT.
        // Sources : UFC-Que Choisir 10/04/2026, UFIP / Connaissance des Energies mars 2026.
        $margeDistrib     = config("fuel.marges_distribution.{$cle}", 0.32);
        $coutHtSansAccise = $coutAvantDistrib + $margeDistrib;

        // -- Étape 3 : Accise (TICPE) -----------------------------------------
        // Taxe fixe définie par la loi de finances, indépendante du prix du brut.
        // Source : UFIP / FIPECO / Direction Générale des Douanes (DGDDI)
        $accise  = config("fuel.accises.{$cle}", 0.6829);

        // -- Étape 4 : TVA 20 % sur (HT + accise) ----------------------------
        // La TVA s'applique sur la totalité : produit HT + accise.
        // Source : Code Général des Impôts, article 278
        $tva      = config('fuel.tva', 0.20);
        $prixTtc  = ($coutHtSansAccise + $accise) * (1 + $tva);

        // -- Fourchette d'incertitude ±0.10 € ---------------------------------
        $fourchette = config('fuel.fourchette', 0.10);

        // Part TVA en euros (pour l'affichage pédagogique)
        $montantTva = ($coutHtSansAccise + $accise) * $tva;

        return [
            // Identifiant et métadonnées
            'cle'              => $cle,
            'nom'              => $meta['nom'],
            'description'      => $meta['description'],
            'couleur'          => $meta['couleur'],

            // Prix final TTC et fourchette
            'prix_ttc'         => round($prixTtc, 3),
            'prix_min'         => round($prixTtc - $fourchette, 3),
            'prix_max'         => round($prixTtc + $fourchette, 3),

            // Libellé dynamique de la première ligne de décomposition
            'label_matiere'    => $labelMatiere,

            // Cotation LSG=F en USD/tonne (non null uniquement pour le Gazole via ICE)
            'lsg_usd_tonne'    => $lsgUsdTonne,

            // Décomposition pour l'affichage pédagogique
            'detail' => [
                'brut_raffinage'  => round($coutAvantDistrib, 4),  // Matière (Brent+raffinage ou cotation ICE)
                'distribution'    => round($margeDistrib, 4),      // Marge distribution
                'accise'          => round($accise, 4),            // TICPE fixe
                'tva'             => round($montantTva, 4),        // TVA calculée
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Métadonnées et utilitaires
    // -------------------------------------------------------------------------

    /**
     * Retourne la liste des sources de données utilisées pour l'affichage.
     *
     * @param bool $gasoilIce Indique si la cotation ICE LSG=F est active pour le Gazole
     */
    private function getSources(bool $gasoilIce = false): array
    {
        $sources = [
            'Cours Brent'        => 'Yahoo Finance (BZ=F) — marché à terme ICE',
            'Taux EUR/USD'       => 'Frankfurter API — Banque Centrale Européenne',
            'Accises (TICPE)'    => 'UFIP / FIPECO — Loi de finances 2026',
            'Marges raffinage'   => 'Estimations moyennes 2025-2026 (UFIP / IFPen)',
            'Marge distribution' => 'CLCV — Rapport marges distribution mars 2026',
        ];

        if ($gasoilIce) {
            // La cotation NYMEX HO=F remplace Brent + marge raffinage pour le Gazole
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
            'carburants'           => [],
            'brent_usd'            => null,
            'eur_usd'              => null,
            'gasoil_rotterdam_usd' => null,
            'mise_a_jour'          => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'sources'              => $this->getSources(),
            'erreur'               => $message,
        ];
    }
}

<?php

namespace App\Services;

use Carbon\Carbon;
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
     * URL de l'API Frankfurter v2 officielle pour le taux USD vers EUR issu de la BCE.
     */
    private const FRANKFURTER_URL = 'https://api.frankfurter.dev/v2/rates?base=USD&quotes=EUR&providers=ECB&expand=providers';

    /**
     * URL de l'API Yahoo Finance pour le Heating Oil NYMEX (ticker HO=F).
     * Cotation en USD par gallon US — indicateur de repli du marché américain (NY Harbor ULSD).
     * HO=F (ULSD, Ultra Low Sulfur Diesel) est un distillat américain corrélé aux marchés mondiaux,
     * utilisé comme repère indicatif en l'absence de flux public temps réel pour la cotation ARA.
     */
    private const YAHOO_GASOIL_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/HO=F';

    /**
     * URL de l'API Alpha Vantage pour le cours du Brent (fallback).
     * Clé API gratuite requise dans .env : ALPHA_VANTAGE_KEY
     */
    private const ALPHA_VANTAGE_URL = 'https://www.alphavantage.co/query';

    /**
     * Clés de cache pour le taux de change USD vers EUR.
     */
    public const CACHE_KEY_USD_EUR = 'usd_eur_rate_data';

    public const CACHE_KEY_USD_EUR_LAST_VALID = 'usd_eur_last_valid_data';

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
     *   usd_eur_date: string|null,
     *   gasoil_nymex_usd: float|null,
     *   gazole_source_type: string,
     *   mise_a_jour: string,
     *   sources: array,
     *   erreur: string|null
     * }
     */
    public function getPrixTheorique(): array
    {
        // Récupération des données marché (avec cache 1 heure et fallback)
        $brentData = $this->getBrentData();
        $usdEurData = $this->getUsdEurData();
        $gasoilNymexData = $this->getGasoilNymexData();

        // Si le Brent ou le taux de change est indisponible, calcul impossible
        if ($brentData === null || $usdEurData === null) {
            return $this->reponseErreur(
                'Les données de marché sont temporairement indisponibles. Veuillez réessayer dans quelques minutes.'
            );
        }

        $brentUsd = $brentData['price'];
        $usdEur = $usdEurData['rate'];
        $usdEurDate = $usdEurData['date'];
        $gasoilUsdPerGallon = $gasoilNymexData['price'] ?? null;
        $gazoleSourceType = ($gasoilUsdPerGallon !== null) ? 'nymex' : 'brent_fallback';

        // Calcul du prix théorique pour chaque carburant configuré
        $carburants = [];
        foreach (config('fuel.carburants') as $cle => $meta) {
            $carburants[$cle] = $this->calculerPrixCarburant($cle, $brentUsd, $usdEur, $meta, $gasoilNymexData, $brentData);
        }

        return [
            'carburants' => $carburants,
            'brent_usd' => round($brentUsd, 2),
            'usd_eur' => round($usdEur, 4),
            'usd_eur_date' => $usdEurDate,
            'gasoil_nymex_usd' => $gasoilUsdPerGallon !== null ? round($gasoilUsdPerGallon, 4) : null,
            'gazole_source_type' => $gazoleSourceType,
            'mise_a_jour' => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'sources' => $this->getSources($gazoleSourceType),
            'erreur' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Récupération du cours du Brent
    // -------------------------------------------------------------------------

    /**
     * Retourne les données du pétrole Brent (cours en USD et date de marché).
     *
     * @return array{price: float, date: string|null}|null
     */
    public function getBrentData(): ?array
    {
        return Cache::remember('brent_data', $this->cacheTtl, function () {
            // Tentative 1 : Yahoo Finance (gratuit, sans clé)
            $data = $this->fetchBrentYahoo();

            if ($data !== null) {
                return $data;
            }

            // Tentative 2 : Alpha Vantage (fallback, clé requise dans .env)
            Log::warning('[FuelService] Yahoo Finance indisponible, tentative Alpha Vantage');
            $data = $this->fetchBrentAlphaVantage();

            if ($data === null) {
                Log::error('[FuelService] Impossible de récupérer le cours du Brent (toutes sources épuisées)');
            }

            return $data;
        });
    }

    /**
     * Retourne le cours du Brent seul (compatibilité).
     */
    public function getBrentPrice(): ?float
    {
        $data = $this->getBrentData();

        return $data['price'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Récupération de la cotation Gazole NYMEX (indicateur HO=F)
    // -------------------------------------------------------------------------

    /**
     * Retourne les données de cotation Heating Oil NYMEX (USD/gallon et date).
     *
     * @return array{price: float, date: string|null}|null
     */
    public function getGasoilNymexData(): ?array
    {
        return Cache::remember('gasoil_nymex_data', $this->cacheTtl, function () {
            $data = $this->fetchGasoilNymexYahoo();

            if ($data === null) {
                Log::warning('[FuelService] Cotation HO=F indisponible — repli Brent explicite activé pour le Gazole');
            }

            return $data;
        });
    }

    public function getGasoilNymexPrice(): ?float
    {
        $data = $this->getGasoilNymexData();

        return $data['price'] ?? null;
    }

    /**
     * Récupère la cotation Heating Oil NYMEX depuis Yahoo Finance.
     * Ticker : HO=F — unité retournée : USD par gallon US.
     *
     * @return array{price: float, date: string}|null
     */
    public function fetchGasoilNymexYahoo(): ?array
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
            $timestamp = $donnees['chart']['result'][0]['meta']['regularMarketTime'] ?? null;

            if ($prix === null || ! is_numeric($prix) || ! is_finite((float) $prix) || (float) $prix <= 0) {
                Log::warning('[FuelService] Cotation HO=F introuvable ou invalide dans la réponse JSON Yahoo');

                return null;
            }

            if (! is_int($timestamp) || $timestamp <= 0 || $timestamp > now()->timestamp) {
                Log::warning('[FuelService] Horodatage HO=F introuvable ou invalide dans la réponse JSON Yahoo');

                return null;
            }

            $date = Carbon::createFromTimestamp($timestamp, 'Europe/Paris')->format('d/m/Y à H:i');

            return [
                'price' => (float) $prix,
                'date' => $date,
            ];

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
     *
     * @return array{price: float, date: string}|null
     */
    public function fetchBrentYahoo(): ?array
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
            $timestamp = $donnees['chart']['result'][0]['meta']['regularMarketTime'] ?? null;

            if ($prix === null || ! is_numeric($prix) || ! is_finite((float) $prix) || (float) $prix <= 0) {
                Log::warning('[FuelService] Cours Brent Yahoo introuvable ou invalide dans la réponse JSON');

                return null;
            }

            if (! is_int($timestamp) || $timestamp <= 0 || $timestamp > now()->timestamp) {
                Log::warning('[FuelService] Horodatage Brent Yahoo introuvable ou invalide dans la réponse JSON');

                return null;
            }

            $date = Carbon::createFromTimestamp($timestamp, 'Europe/Paris')->format('d/m/Y à H:i');

            return [
                'price' => (float) $prix,
                'date' => $date,
            ];

        } catch (\Throwable $e) {
            Log::warning('[FuelService] Cours Brent Yahoo Finance indisponible', [
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Récupère le cours du Brent depuis Alpha Vantage (fallback).
     *
     * @return array{price: float, date: string|null}|null
     */
    public function fetchBrentAlphaVantage(): ?array
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
            $dateRaw = $donnees['data'][0]['date'] ?? null;

            if ($derniere === null || $derniere === '.' || ! is_numeric($derniere) || ! is_finite((float) $derniere) || (float) $derniere <= 0) {
                Log::warning('[FuelService] Cours Brent Alpha Vantage introuvable ou invalide dans la réponse JSON');

                return null;
            }

            if (! $this->isValidDate($dateRaw)) {
                Log::warning('[FuelService] Date cours Brent Alpha Vantage introuvable ou invalide');

                return null;
            }

            $formattedDate = Carbon::parse($dateRaw)->format('d/m/Y');

            return [
                'price' => (float) $derniere,
                'date' => $formattedDate,
            ];

        } catch (\Throwable $e) {
            Log::warning('[FuelService] Cours Brent Alpha Vantage indisponible', [
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Récupération du taux de change USD vers EUR (Frankfurter v2 / BCE)
    // -------------------------------------------------------------------------

    /**
     * Retourne le taux de change USD vers EUR et sa date de référence (BCE).
     * Source : API Frankfurter v2 (adossée aux publications de la Banque Centrale Européenne).
     * Cache 1 heure (normal) et cache 7 jours (repli si l'API est temporairement indisponible).
     *
     * @return array{rate: float, date: string, provider_key: string}|null
     */
    public function getUsdEurData(): ?array
    {
        // 1. Tenter le cache normal 1 heure
        $cached = Cache::get(self::CACHE_KEY_USD_EUR);
        if ($this->isValidCachedRate($cached)) {
            return $cached;
        }

        // 2. Appel API Frankfurter v2
        try {
            $reponse = Http::timeout(10)->get(self::FRANKFURTER_URL);

            if ($reponse->successful()) {
                $donnees = $reponse->json();
                $result = $this->extractFrankfurterV2Rate($donnees);

                if ($result !== null) {
                    Cache::put(self::CACHE_KEY_USD_EUR, $result, $this->cacheTtl);
                    Cache::put(self::CACHE_KEY_USD_EUR_LAST_VALID, $result, 86400 * 7);

                    return $result;
                }
            }

            Log::error('[FuelService] Frankfurter v2 API réponse invalide ou HTTP '.$reponse->status());

        } catch (\Throwable $e) {
            Log::error('[FuelService] Taux USD vers EUR Frankfurter v2 API indisponible', [
                'exception_type' => $e::class,
            ]);
        }

        // 3. Fallback sur le dernier taux valide conservé
        $lastValid = Cache::get(self::CACHE_KEY_USD_EUR_LAST_VALID);
        if ($this->isValidCachedRate($lastValid)) {
            Log::warning('[FuelService] Utilisation du dernier taux de change USD vers EUR valide en cache', [
                'date_source' => $lastValid['date'],
            ]);

            return $lastValid;
        }

        return null;
    }

    /**
     * Valide et extrait le taux USD vers EUR issu de l'observation BCE depuis la réponse Frankfurter v2.
     *
     * @return array{rate: float, date: string, provider_key: string}|null
     */
    public function extractFrankfurterV2Rate(mixed $jsonData): ?array
    {
        if (! is_array($jsonData) || ! array_is_list($jsonData) || empty($jsonData)) {
            return null;
        }

        foreach ($jsonData as $item) {
            if (! is_array($item)) {
                continue;
            }

            $base = $item['base'] ?? null;
            $quote = $item['quote'] ?? null;
            $rate = $item['rate'] ?? null;
            $date = $item['date'] ?? null;
            $providers = $item['providers'] ?? null;

            if ($base !== 'USD' || $quote !== 'EUR') {
                continue;
            }

            if (! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate <= 0) {
                continue;
            }

            if (! $this->isValidDate($date)) {
                continue;
            }

            if (! is_array($providers) || ! array_is_list($providers) || empty($providers)) {
                continue;
            }

            // Recherche stricte de l'observation dont key === 'ECB'
            foreach ($providers as $provider) {
                if (! is_array($provider)) {
                    continue;
                }

                // Strictement le champ 'key' et la valeur 'ECB'
                if (($provider['key'] ?? null) !== 'ECB') {
                    continue;
                }

                $ecbRate = $provider['rate'] ?? null;
                $ecbDate = $provider['date'] ?? null;

                if (! is_numeric($ecbRate) || ! is_finite((float) $ecbRate) || (float) $ecbRate <= 0) {
                    continue;
                }

                if (! $this->isValidDate($ecbDate)) {
                    continue;
                }

                return [
                    'rate' => (float) $ecbRate,
                    'date' => (string) $ecbDate,
                    'provider_key' => 'ECB',
                ];
            }
        }

        return null;
    }

    /**
     * Valide l'intégrité et la provenance d'une donnée de taux en cache.
     */
    public function isValidCachedRate(mixed $cached): bool
    {
        if (! is_array($cached)) {
            return false;
        }

        if (! isset($cached['rate'], $cached['date'], $cached['provider_key'])) {
            return false;
        }

        if (! is_numeric($cached['rate']) || ! is_finite((float) $cached['rate']) || (float) $cached['rate'] <= 0) {
            return false;
        }

        if (! $this->isValidDate($cached['date'])) {
            return false;
        }

        if ($cached['provider_key'] !== 'ECB') {
            return false;
        }

        return true;
    }

    /**
     * Valide strictement une date au format YYYY-MM-DD dans le calendrier réel et non future.
     */
    public function isValidDate(mixed $date): bool
    {
        if (! is_string($date) || $date === '' || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return false;
        }

        // Rejeter toute date future
        $today = now()->timezone('Europe/Paris')->format('Y-m-d');
        if ($date > $today) {
            return false;
        }

        return true;
    }

    /**
     * Retourne le taux de change USD vers EUR seul (pour compatibilité interne).
     */
    public function getUsdEurRate(): ?float
    {
        $data = $this->getUsdEurData();

        return $data['rate'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Calcul du prix théorique
    // -------------------------------------------------------------------------

    /**
     * Calcule le prix théorique TTC d'un carburant donné.
     *
     * @param  string  $cle  Identifiant du carburant (ex: 'sp95_e10')
     * @param  float  $brentUsd  Cours du Brent en USD/baril
     * @param  float  $usdEur  Taux de change USD vers EUR (nombre d'euros pour 1 USD)
     * @param  array  $meta  Métadonnées du carburant (nom, description, couleur)
     * @param  array|null  $gasoilNymexData  Cotation NYMEX HO=F (null = fallback Brent)
     * @param  array|null  $brentData  Données de marché du Brent
     */
    private function calculerPrixCarburant(
        string $cle,
        float $brentUsd,
        float $usdEur,
        array $meta,
        ?array $gasoilNymexData = null,
        ?array $brentData = null
    ): array {

        // -- Étape 1 : Coût matière par litre (méthode selon filière) ----------

        if ($cle === 'gazole') {
            if ($gasoilNymexData !== null && isset($gasoilNymexData['price']) && (float) $gasoilNymexData['price'] > 0) {
                // Chemin nominal : NY Harbor ULSD
                $gasoilUsdPerGallon = (float) $gasoilNymexData['price'];
                $litresParGallon = config('fuel.gasoil_litres_per_gallon', 3.785411784);
                $coutAvantDistrib = ($gasoilUsdPerGallon / $litresParGallon) * $usdEur;
                $sourceType = 'nymex';
                $sourceDate = $gasoilNymexData['date'] ?? null;
                $labelMatiere = 'NY Harbor ULSD (indicateur US)';
                $nymexUsdGallon = round($gasoilUsdPerGallon, 4);
                $nymexConvertiEur = round($coutAvantDistrib, 4);
            } else {
                // Chemin de repli explicite : Brent + marge de raffinage gazole
                $litresParBaril = config('fuel.litres_par_baril', 159);
                $coutBrutEur = ($brentUsd / $litresParBaril) * $usdEur;
                $margeRaffinage = config('fuel.marges_raffinage.gazole', 0.43);
                $coutAvantDistrib = $coutBrutEur + $margeRaffinage;
                $sourceType = 'brent_fallback';
                $sourceDate = $brentData['date'] ?? null;
                $labelMatiere = 'Brent + marge raffinage (repli)';
                $nymexUsdGallon = null;
                $nymexConvertiEur = null;
            }
        } else {
            // Formule standard : Brent + marge raffinage
            $litresParBaril = config('fuel.litres_par_baril', 159);
            $coutBrutEur = ($brentUsd / $litresParBaril) * $usdEur;

            // Exception E85 : formule hybride pétrole + éthanol agricole
            if ($cle === 'e85') {
                $ethanolEurL = config('fuel.ethanol_cost_per_liter', 0.42);
                $coutBrutEur = (0.15 * $coutBrutEur) + (0.85 * $ethanolEurL);
            }

            $margeRaffinage = config("fuel.marges_raffinage.{$cle}", 0.07);
            $coutAvantDistrib = $coutBrutEur + $margeRaffinage;
            $sourceType = 'brent';
            $sourceDate = $brentData['date'] ?? null;
            $labelMatiere = 'Brut + raffinage';
            $nymexUsdGallon = null;
            $nymexConvertiEur = null;
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

            // Traçabilité de la source
            'source_type' => $sourceType,
            'source_date' => $sourceDate,

            // Prix final TTC et fourchette
            'prix_ttc' => round($prixTtc, 3),
            'prix_min' => round($prixTtc - $fourchette, 3),
            'prix_max' => round($prixTtc + $fourchette, 3),

            // Libellé dynamique de la première ligne de décomposition
            'label_matiere' => $labelMatiere,

            // Cotation en USD/gallon pour le Gazole et valeur convertie EUR/L
            'nymex_usd_gallon' => $nymexUsdGallon,
            'nymex_converti_eur' => $nymexConvertiEur,

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
    private function getSources(string $gazoleSourceType = 'nymex'): array
    {
        $sources = [
            'Cours Brent' => 'Yahoo Finance (BZ=F) — marché à terme ICE (dernières données disponibles)',
            'Taux de change USD vers EUR' => 'Frankfurter API v2 — taux de référence de la Banque Centrale Européenne (BCE)',
            'Accises (TICPE)' => 'UFIP / FIPECO / DGDDI — Loi de finances 2026',
            'Marges raffinage' => 'Estimations moyennes 2025-2026 (UFIP / IFPen)',
            'Marge distribution' => 'CLCV — Rapport marges distribution mars 2026',
        ];

        if ($gazoleSourceType === 'nymex') {
            $sources['Gazole — indicateur US'] = 'Yahoo Finance (HO=F) — NY Harbor ULSD (indicateur de repli du marché américain, non ARA)';
        } elseif ($gazoleSourceType === 'brent_fallback') {
            $sources['Gazole — repli'] = 'Brent ICE (BZ=F) + marge de raffinage moyenne — repli temporaire suite à indisponibilité de l\'indicateur US';
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
            'usd_eur_date' => null,
            'gasoil_nymex_usd' => null,
            'gazole_source_type' => 'unavailable',
            'mise_a_jour' => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'sources' => $this->getSources('none'),
            'erreur' => $message,
        ];
    }
}

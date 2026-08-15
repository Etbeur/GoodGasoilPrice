<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service de récupération des prix moyens nationaux constatés à la pompe.
 *
 * Source officielle : Ministère de l'Économie / DGCCRF via le portail Open Data
 * Jeu de données : prix-des-carburants-en-france-flux-instantane-v2
 * Licence : Licence Ouverte 2.0
 *
 * Fréquence de rafraîchissement officielle : toutes les 10 minutes.
 * Stratégie de cache :
 *  - Cache courant : 30 minutes (1800 s)
 *  - Dernier résultat valide de repli : 24 heures (86400 s)
 *  - Temporisation d'échec : 1 minute (60 s) pour éviter les requêtes en boucle en cas de panne
 */
class ObservedFuelPriceService
{
    /**
     * URL de l'API Open Data Explore v2.1 du Ministère de l'Économie.
     */
    public const API_URL = 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/prix-des-carburants-en-france-flux-instantane-v2/records';

    /**
     * Requête SQL d'agrégation demandée à l'API Open Data.
     */
    public const AGGREGATE_SELECT = 'avg(gazole_prix) as gazole_avg, count(gazole_prix) as gazole_count, max(gazole_maj) as gazole_maj, avg(e10_prix) as e10_avg, count(e10_prix) as e10_count, max(e10_maj) as e10_maj, avg(sp95_prix) as sp95_avg, count(sp95_prix) as sp95_count, max(sp95_maj) as sp95_maj, avg(sp98_prix) as sp98_avg, count(sp98_prix) as sp98_count, max(sp98_maj) as sp98_maj, avg(e85_prix) as e85_avg, count(e85_prix) as e85_count, max(e85_maj) as e85_maj, avg(gplc_prix) as gplc_avg, count(gplc_prix) as gplc_count, max(gplc_maj) as gplc_maj';

    /**
     * Mapping entre les clés internes de carburants et les préfixes du jeu de données officiel.
     */
    public const FUEL_MAPPING = [
        'sp95_e10' => [
            'prefix' => 'e10',
            'nom' => 'SP95-E10',
        ],
        'sp95' => [
            'prefix' => 'sp95',
            'nom' => 'SP95',
        ],
        'sp98' => [
            'prefix' => 'sp98',
            'nom' => 'SP98',
        ],
        'gazole' => [
            'prefix' => 'gazole',
            'nom' => 'Gazole',
        ],
        'e85' => [
            'prefix' => 'e85',
            'nom' => 'E85',
        ],
        'gpl' => [
            'prefix' => 'gplc',
            'nom' => 'GPL',
        ],
    ];

    public const CACHE_KEY_CURRENT = 'observed_fuel_prices_current';

    public const CACHE_KEY_LAST_VALID = 'observed_fuel_prices_last_valid';

    public const CACHE_KEY_FAILURE_COOLDOWN = 'observed_fuel_prices_failure_cooldown';

    public const CACHE_TTL_CURRENT = 1800;      // 30 minutes

    public const CACHE_TTL_LAST_VALID = 86400;  // 24 heures

    public const CACHE_TTL_FAILURE = 60;        // 1 minute de temporisation

    /**
     * Récupère les prix moyens nationaux observés avec gestion de cache à 2 niveaux.
     *
     * @return array{
     *   status: string,
     *   is_fallback: bool,
     *   recupere_le: string|null,
     *   carburants: array<string, array{
     *     cle: string,
     *     nom: string,
     *     prix_moyen: float|null,
     *     nb_declarations: int,
     *     derniere_maj: string|null,
     *     disponible: bool
     *   }>,
     *   source: string,
     *   licence: string,
     *   message: string|null
     * }
     */
    public function getPrixObserves(): array
    {
        // 1. Si le cache courant est présent, le retourner immédiatement
        if (Cache::has(self::CACHE_KEY_CURRENT)) {
            $cached = Cache::get(self::CACHE_KEY_CURRENT);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // 2. Si une temporisation d'échec récente est active, tenter le fallback sans rappeler l'API
        if (Cache::has(self::CACHE_KEY_FAILURE_COOLDOWN)) {
            return $this->getFallbackOrUnavailable();
        }

        // 3. Appel de l'API officielle
        $freshData = $this->fetchFromOfficialApi();

        if ($freshData !== null) {
            Cache::put(self::CACHE_KEY_CURRENT, $freshData, self::CACHE_TTL_CURRENT);
            Cache::put(self::CACHE_KEY_LAST_VALID, $freshData, self::CACHE_TTL_LAST_VALID);
            Cache::forget(self::CACHE_KEY_FAILURE_COOLDOWN);

            return $freshData;
        }

        // 4. En cas d'échec, activer la temporisation et utiliser le fallback
        Cache::put(self::CACHE_KEY_FAILURE_COOLDOWN, true, self::CACHE_TTL_FAILURE);

        return $this->getFallbackOrUnavailable();
    }

    /**
     * Interroge l'API officielle du Ministère de l'Économie et valide la réponse.
     */
    private function fetchFromOfficialApi(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(6)
                ->retry(1, 100, throw: false)
                ->get(self::API_URL, [
                    'select' => self::AGGREGATE_SELECT,
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                Log::warning('[ObservedFuelService] Échec HTTP API Open Data : '.$response->status());

                return null;
            }

            $json = $response->json();

            if (! is_array($json) || ! isset($json['results']) || ! is_array($json['results']) || empty($json['results'])) {
                Log::warning('[ObservedFuelService] Structure JSON invalide ou résultats vides');

                return null;
            }

            $record = $json['results'][0];
            if (! is_array($record)) {
                Log::warning('[ObservedFuelService] Premier enregistrement de résultat non valide');

                return null;
            }

            return $this->parseRecord($record);

        } catch (\Throwable $e) {
            Log::warning('[ObservedFuelService] Exception lors de la requête Open Data', [
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Parse et valide les données arithmétiques pour chacun des 6 carburants.
     * Une moyenne n'est disponible que si la moyenne, le compteur (>0) et la date sont valides.
     */
    private function parseRecord(array $record): ?array
    {
        $carburants = [];
        $hasAtLeastOneValid = false;

        foreach (self::FUEL_MAPPING as $cle => $config) {
            $prefix = $config['prefix'];
            $avgKey = "{$prefix}_avg";
            $countKey = "{$prefix}_count";
            $majKey = "{$prefix}_maj";

            $rawAvg = $record[$avgKey] ?? null;
            $rawCount = $record[$countKey] ?? null;
            $rawMaj = $record[$majKey] ?? null;

            $prixMoyen = null;
            $nbDeclarations = 0;
            $derniereMaj = null;
            $disponible = false;

            // 1. Validation de la date de mise à jour
            $dateValide = false;
            if (! empty($rawMaj) && is_string($rawMaj)) {
                try {
                    $derniereMaj = Carbon::parse($rawMaj)
                        ->setTimezone('Europe/Paris')
                        ->format('d/m/Y à H:i');
                    $dateValide = true;
                } catch (\Throwable) {
                    $derniereMaj = null;
                    $dateValide = false;
                }
            }

            // 2. Validation du compteur de déclarations (strictement positif)
            $countValide = false;
            if ($rawCount !== null && is_numeric($rawCount)) {
                $intCount = (int) $rawCount;
                if ($intCount > 0) {
                    $nbDeclarations = $intCount;
                    $countValide = true;
                }
            }

            // 3. Validation de la moyenne numérique (entre 0.10 € et 10.00 €)
            $avgValide = false;
            $tempAvg = null;
            if ($rawAvg !== null && is_numeric($rawAvg)) {
                $floatAvg = (float) $rawAvg;
                if ($floatAvg >= 0.10 && $floatAvg <= 10.00) {
                    $tempAvg = round($floatAvg, 3);
                    $avgValide = true;
                }
            }

            // Les trois conditions doivent être cumulativement valides
            if ($dateValide && $countValide && $avgValide) {
                $prixMoyen = $tempAvg;
                $disponible = true;
                $hasAtLeastOneValid = true;
            } else {
                $prixMoyen = null;
                $disponible = false;
            }

            $carburants[$cle] = [
                'cle' => $cle,
                'nom' => $config['nom'],
                'prix_moyen' => $prixMoyen,
                'nb_declarations' => $nbDeclarations,
                'derniere_maj' => $derniereMaj,
                'disponible' => $disponible,
            ];
        }

        if (! $hasAtLeastOneValid) {
            Log::warning('[ObservedFuelService] Aucune moyenne de carburant valide extraite');

            return null;
        }

        return [
            'status' => 'fresh',
            'is_fallback' => false,
            'recupere_le' => now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'carburants' => $carburants,
            'source' => 'DGCCRF – prix-carburants.gouv.fr',
            'licence' => 'Licence Ouverte 2.0',
            'message' => null,
        ];
    }

    /**
     * Retourne le dernier résultat valide mis en cache ou une structure d'indisponibilité propre.
     */
    private function getFallbackOrUnavailable(): array
    {
        if (Cache::has(self::CACHE_KEY_LAST_VALID)) {
            $lastValid = Cache::get(self::CACHE_KEY_LAST_VALID);
            if (is_array($lastValid) && isset($lastValid['carburants'])) {
                $lastValid['status'] = 'cached';
                $lastValid['is_fallback'] = true;

                return $lastValid;
            }
        }

        return $this->reponseIndisponible();
    }

    /**
     * Structure retournée lorsqu'aucune donnée officielle n'est disponible.
     */
    private function reponseIndisponible(): array
    {
        $carburants = [];
        foreach (self::FUEL_MAPPING as $cle => $config) {
            $carburants[$cle] = [
                'cle' => $cle,
                'nom' => $config['nom'],
                'prix_moyen' => null,
                'nb_declarations' => 0,
                'derniere_maj' => null,
                'disponible' => false,
            ];
        }

        return [
            'status' => 'unavailable',
            'is_fallback' => false,
            'recupere_le' => null,
            'carburants' => $carburants,
            'source' => 'DGCCRF – prix-carburants.gouv.fr',
            'licence' => 'Licence Ouverte 2.0',
            'message' => 'Prix moyen national momentanément indisponible.',
        ];
    }
}

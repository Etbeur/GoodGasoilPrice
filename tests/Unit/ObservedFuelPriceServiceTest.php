<?php

namespace Tests\Unit;

use App\Services\ObservedFuelPriceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ObservedFuelPriceServiceTest extends TestCase
{
    private ObservedFuelPriceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        $this->service = new ObservedFuelPriceService;
    }

    private function getValidApiResponse(): array
    {
        return [
            'total_count' => 9800,
            'results' => [
                [
                    'gazole_avg' => 2.20937,
                    'gazole_count' => 9600,
                    'gazole_maj' => '2026-08-15T14:08:22+00:00',
                    'e10_avg' => 2.00754,
                    'e10_count' => 7500,
                    'e10_maj' => '2026-08-15T14:08:23+00:00',
                    'sp95_avg' => 2.04879,
                    'sp95_count' => 3000,
                    'sp95_maj' => '2026-08-15T14:00:00+00:00',
                    'sp98_avg' => 2.09077,
                    'sp98_count' => 7500,
                    'sp98_maj' => '2026-08-15T14:08:23+00:00',
                    'e85_avg' => 0.86333,
                    'e85_count' => 3900,
                    'e85_maj' => '2026-08-15T13:36:50+00:00',
                    'gplc_avg' => 1.05098,
                    'gplc_count' => 1500,
                    'gplc_maj' => '2026-08-15T14:08:23+00:00',
                ],
            ],
        ];
    }

    /**
     * 1. Réponse nominale et vérification stricte de la requête HTTP envoyée
     */
    public function test_nominal_api_response_returns_fresh_status_and_verifies_exact_http_request(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($this->getValidApiResponse(), 200),
        ]);

        $result = $this->service->getPrixObserves();

        $this->assertSame('fresh', $result['status']);
        $this->assertFalse($result['is_fallback']);
        $this->assertSame('DGCCRF – prix-carburants.gouv.fr', $result['source']);
        $this->assertSame('Licence Ouverte 2.0', $result['licence']);
        $this->assertNull($result['message']);
        $this->assertNotNull($result['recupere_le']);

        // Vérification stricte de la requête HTTP émise
        Http::assertSent(function (Request $request) {
            // 1. Endpoint exact sans motif générique
            $urlSansQuery = strtok($request->url(), '?');
            $this->assertSame(ObservedFuelPriceService::API_URL, $urlSansQuery);

            // 2. Méthode GET
            $this->assertSame('GET', $request->method());

            // 3. Paramètres attendus : uniquement 'select' et 'limit'
            $queryParams = $request->data();
            $this->assertArrayHasKey('select', $queryParams);
            $this->assertArrayHasKey('limit', $queryParams);
            $this->assertCount(2, $queryParams, 'Aucun paramètre inattendu ne doit être envoyé.');
            $this->assertSame('1', (string) $queryParams['limit']);

            // 4. Vérification de toutes les agrégations avg, count et max des 6 carburants
            $select = $queryParams['select'];
            $expectedClauses = [
                'avg(gazole_prix) as gazole_avg', 'count(gazole_prix) as gazole_count', 'max(gazole_maj) as gazole_maj',
                'avg(e10_prix) as e10_avg', 'count(e10_prix) as e10_count', 'max(e10_maj) as e10_maj',
                'avg(sp95_prix) as sp95_avg', 'count(sp95_prix) as sp95_count', 'max(sp95_maj) as sp95_maj',
                'avg(sp98_prix) as sp98_avg', 'count(sp98_prix) as sp98_count', 'max(sp98_maj) as sp98_maj',
                'avg(e85_prix) as e85_avg', 'count(e85_prix) as e85_count', 'max(e85_maj) as e85_maj',
                'avg(gplc_prix) as gplc_avg', 'count(gplc_prix) as gplc_count', 'max(gplc_maj) as gplc_maj',
            ];

            foreach ($expectedClauses as $clause) {
                $this->assertStringContainsString($clause, $select);
            }

            return true;
        });
    }

    /**
     * 2. Mapping des six carburants
     */
    public function test_mapping_of_all_six_fuels(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($this->getValidApiResponse(), 200),
        ]);

        $result = $this->service->getPrixObserves();
        $fuels = $result['carburants'];

        $this->assertArrayHasKey('sp95_e10', $fuels);
        $this->assertArrayHasKey('sp95', $fuels);
        $this->assertArrayHasKey('sp98', $fuels);
        $this->assertArrayHasKey('gazole', $fuels);
        $this->assertArrayHasKey('e85', $fuels);
        $this->assertArrayHasKey('gpl', $fuels);
    }

    /**
     * 3. Calcul et format des moyennes (3 décimales arrondies)
     */
    public function test_calculation_and_formatting_of_averages(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($this->getValidApiResponse(), 200),
        ]);

        $result = $this->service->getPrixObserves();
        $fuels = $result['carburants'];

        $this->assertSame(2.209, $fuels['gazole']['prix_moyen']);
        $this->assertSame(2.008, $fuels['sp95_e10']['prix_moyen']);
        $this->assertSame(2.049, $fuels['sp95']['prix_moyen']);
        $this->assertSame(2.091, $fuels['sp98']['prix_moyen']);
        $this->assertSame(0.863, $fuels['e85']['prix_moyen']);
        $this->assertSame(1.051, $fuels['gpl']['prix_moyen']);
    }

    /**
     * 4. Nombres de déclarations
     */
    public function test_number_of_declarations(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($this->getValidApiResponse(), 200),
        ]);

        $result = $this->service->getPrixObserves();
        $fuels = $result['carburants'];

        $this->assertSame(9600, $fuels['gazole']['nb_declarations']);
        $this->assertSame(7500, $fuels['sp95_e10']['nb_declarations']);
        $this->assertSame(3000, $fuels['sp95']['nb_declarations']);
        $this->assertSame(7500, $fuels['sp98']['nb_declarations']);
        $this->assertSame(3900, $fuels['e85']['nb_declarations']);
        $this->assertSame(1500, $fuels['gpl']['nb_declarations']);
    }

    /**
     * 5. Dates de mise à jour converties en Europe/Paris
     */
    public function test_update_dates_converted_to_paris_timezone(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($this->getValidApiResponse(), 200),
        ]);

        $result = $this->service->getPrixObserves();
        $fuels = $result['carburants'];

        // 2026-08-15T14:08:22+00:00 en été (UTC+2) -> 16:08
        $this->assertStringContainsString('15/08/2026 à 16:08', $fuels['gazole']['derniere_maj']);
        $this->assertStringContainsString('15/08/2026 à 16:08', $fuels['sp95_e10']['derniere_maj']);
        $this->assertStringContainsString('15/08/2026 à 16:00', $fuels['sp95']['derniere_maj']);
    }

    /**
     * 6. Cache courant (30 minutes)
     */
    public function test_current_cache_prevents_subsequent_http_calls(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::sequence()
                ->push($this->getValidApiResponse(), 200)
                ->push(['error' => 'should not be called'], 500),
        ]);

        $firstCall = $this->service->getPrixObserves();
        $secondCall = $this->service->getPrixObserves();

        $this->assertSame('fresh', $firstCall['status']);
        $this->assertSame('fresh', $secondCall['status']);
        $this->assertSame(2.209, $secondCall['carburants']['gazole']['prix_moyen']);
        Http::assertSentCount(1);
    }

    /**
     * 7. Conservation du dernier résultat valide (24 heures)
     */
    public function test_preservation_of_last_valid_result(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($this->getValidApiResponse(), 200),
        ]);

        $this->service->getPrixObserves();

        $this->assertTrue(Cache::has(ObservedFuelPriceService::CACHE_KEY_LAST_VALID));
        $cached = Cache::get(ObservedFuelPriceService::CACHE_KEY_LAST_VALID);
        $this->assertSame(2.209, $cached['carburants']['gazole']['prix_moyen']);
    }

    /**
     * 8. Échec HTTP avec dernier résultat valide
     */
    public function test_http_failure_uses_last_valid_cached_result(): void
    {
        // 1. Succès initial
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::sequence()
                ->push($this->getValidApiResponse(), 200)
                ->push(['error' => 'API down'], 500),
        ]);

        $this->service->getPrixObserves();

        // Expiration du cache courant
        Cache::forget(ObservedFuelPriceService::CACHE_KEY_CURRENT);

        // 2. Second appel en échec -> repli
        $fallbackResult = $this->service->getPrixObserves();

        $this->assertSame('cached', $fallbackResult['status']);
        $this->assertTrue($fallbackResult['is_fallback']);
        $this->assertSame(2.209, $fallbackResult['carburants']['gazole']['prix_moyen']);
    }

    /**
     * 9. Échec HTTP sans résultat valide
     */
    public function test_http_failure_without_valid_cached_result_returns_unavailable(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response(['error' => 'server error'], 500),
        ]);

        $result = $this->service->getPrixObserves();

        $this->assertSame('unavailable', $result['status']);
        $this->assertFalse($result['is_fallback']);
        $this->assertSame('Prix moyen national momentanément indisponible.', $result['message']);
        $this->assertNull($result['carburants']['gazole']['prix_moyen']);
        $this->assertFalse($result['carburants']['gazole']['disponible']);
    }

    /**
     * 10. JSON malformé
     */
    public function test_malformed_json_handled_gracefully(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response('<html>Error 502 Bad Gateway</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->getPrixObserves();

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['carburants']['gazole']['prix_moyen']);
    }

    /**
     * 11. Résultat incomplet (champ results manquant ou vide)
     */
    public function test_incomplete_json_response_handled_gracefully(): void
    {
        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response(['total_count' => 0, 'results' => []], 200),
        ]);

        $result = $this->service->getPrixObserves();

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['carburants']['gazole']['prix_moyen']);
    }

    /**
     * 12. Date absente ou invalide : le carburant doit être marqué indisponible sans inventer de date
     */
    public function test_fuel_is_marked_unavailable_if_date_is_missing_or_invalid(): void
    {
        $response = [
            'total_count' => 1,
            'results' => [
                [
                    // Gazole : date absente
                    'gazole_avg' => 2.209,
                    'gazole_count' => 9600,
                    'gazole_maj' => null,
                    // SP95-E10 : date invalide (non parsable)
                    'e10_avg' => 2.008,
                    'e10_count' => 7500,
                    'e10_maj' => 'NOT_A_VALID_DATE',
                    // SP98 : tout valide
                    'sp98_avg' => 2.091,
                    'sp98_count' => 7500,
                    'sp98_maj' => '2026-08-15T14:08:23+00:00',
                ],
            ],
        ];

        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($response, 200),
        ]);

        $result = $this->service->getPrixObserves();
        $fuels = $result['carburants'];

        // Gazole : indisponible, pas de moyenne affichée, pas de date inventée
        $this->assertFalse($fuels['gazole']['disponible']);
        $this->assertNull($fuels['gazole']['prix_moyen']);
        $this->assertNull($fuels['gazole']['derniere_maj']);

        // E10 : indisponible, pas de moyenne affichée, pas de date inventée
        $this->assertFalse($fuels['sp95_e10']['disponible']);
        $this->assertNull($fuels['sp95_e10']['prix_moyen']);
        $this->assertNull($fuels['sp95_e10']['derniere_maj']);

        // SP98 : valide
        $this->assertTrue($fuels['sp98']['disponible']);
        $this->assertSame(2.091, $fuels['sp98']['prix_moyen']);
        $this->assertNotNull($fuels['sp98']['derniere_maj']);
    }

    /**
     * 13. Compteur absent, non numérique, nul ou négatif : le carburant doit être marqué indisponible
     */
    public function test_fuel_is_marked_unavailable_if_count_is_invalid_or_zero(): void
    {
        $response = [
            'total_count' => 1,
            'results' => [
                [
                    // Gazole : compteur nul (0)
                    'gazole_avg' => 2.209,
                    'gazole_count' => 0,
                    'gazole_maj' => '2026-08-15T14:08:23+00:00',
                    // SP95-E10 : compteur négatif (-10)
                    'e10_avg' => 2.008,
                    'e10_count' => -10,
                    'e10_maj' => '2026-08-15T14:08:23+00:00',
                    // SP95 : compteur absent (null)
                    'sp95_avg' => 2.049,
                    'sp95_count' => null,
                    'sp95_maj' => '2026-08-15T14:08:23+00:00',
                    // E85 : compteur non numérique
                    'e85_avg' => 0.863,
                    'e85_count' => 'invalid_count_string',
                    'e85_maj' => '2026-08-15T14:08:23+00:00',
                    // SP98 : tout valide
                    'sp98_avg' => 2.091,
                    'sp98_count' => 7500,
                    'sp98_maj' => '2026-08-15T14:08:23+00:00',
                ],
            ],
        ];

        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($response, 200),
        ]);

        $result = $this->service->getPrixObserves();
        $fuels = $result['carburants'];

        $this->assertFalse($fuels['gazole']['disponible']);
        $this->assertNull($fuels['gazole']['prix_moyen']);

        $this->assertFalse($fuels['sp95_e10']['disponible']);
        $this->assertNull($fuels['sp95_e10']['prix_moyen']);

        $this->assertFalse($fuels['sp95']['disponible']);
        $this->assertNull($fuels['sp95']['prix_moyen']);

        $this->assertFalse($fuels['e85']['disponible']);
        $this->assertNull($fuels['e85']['prix_moyen']);

        $this->assertTrue($fuels['sp98']['disponible']);
        $this->assertSame(2.091, $fuels['sp98']['prix_moyen']);
    }

    /**
     * 14. Si tous les carburants ont des dates/compteurs invalides, la réponse globale bascule sur indisponible/repli
     */
    public function test_all_invalid_fuels_triggers_unavailable(): void
    {
        $response = [
            'total_count' => 1,
            'results' => [
                [
                    'gazole_avg' => 2.209,
                    'gazole_count' => 9600,
                    'gazole_maj' => null, // pas de date
                    'e10_avg' => 2.008,
                    'e10_count' => 0, // compteur nul
                    'e10_maj' => '2026-08-15T14:08:23+00:00',
                ],
            ],
        ];

        Http::fake([
            ObservedFuelPriceService::API_URL.'*' => Http::response($response, 200),
        ]);

        $result = $this->service->getPrixObserves();

        $this->assertSame('unavailable', $result['status']);
    }
}

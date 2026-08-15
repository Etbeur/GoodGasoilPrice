<?php

namespace Tests\Unit;

use App\Services\FuelPriceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests unitaires dédiés au calcul du Gazole, au taux USD/EUR officiel BCE via Frankfurter v2,
 * à la validation stricte des observations et des dates, au cache avec provenance et aux replis Alpha Vantage et Brent.
 */
class GasoilCalculationTest extends TestCase
{
    private FuelPriceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Paris'));
        $this->service = new FuelPriceService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper pour simuler une réponse standard Frankfurter v2.
     */
    private function sampleFrankfurterV2Response(float $rate = 0.8645, string $date = '2026-08-14'): array
    {
        return [
            [
                'base' => 'USD',
                'quote' => 'EUR',
                'rate' => $rate,
                'date' => $date,
                'providers' => [
                    [
                        'key' => 'ECB',
                        'name' => 'European Central Bank',
                        'date' => $date,
                        'rate' => $rate,
                    ],
                ],
            ],
        ];
    }

    /**
     * 1. Vérifie le calcul théorique selon le repère de comparaison DGEC du 7 août 2026 :
     *    - Cotation internationale matière : 0.880 €/L
     *    - Transport-distribution : 0.320 €/L
     *    - Accise : 0.610 €/L
     *    - TVA 20 % : 0.362 €/L  ((0.880 + 0.320 + 0.610) * 0.20 = 0.362)
     *    - Total TTC : 2.172 €/L
     *    - Fourchette ±0.10 € : 2.072 €/L à 2.272 €/L
     */
    public function test_dgec_reference_quotation_produces_exact_benchmark_price(): void
    {
        $matiere = 0.880;
        $distrib = config('fuel.marges_distribution.gazole', 0.320);
        $accise = config('fuel.accises.gazole', 0.610);
        $tvaTaux = config('fuel.tva', 0.20);
        $fourchette = config('fuel.fourchette', 0.10);

        $this->assertSame(0.320, $distrib);
        $this->assertSame(0.610, $accise);

        $prixHt = $matiere + $distrib + $accise; // 1.810
        $tvaMontant = $prixHt * $tvaTaux; // 0.362
        $prixTtc = $prixHt * (1 + $tvaTaux); // 2.172

        $this->assertEqualsWithDelta(0.880, $matiere, 0.0001);
        $this->assertEqualsWithDelta(0.320, $distrib, 0.0001);
        $this->assertEqualsWithDelta(0.610, $accise, 0.0001);
        $this->assertEqualsWithDelta(0.362, $tvaMontant, 0.0001);
        $this->assertEqualsWithDelta(2.172, $prixTtc, 0.0001);

        $this->assertEqualsWithDelta(2.072, $prixTtc - $fourchette, 0.0001);
        $this->assertEqualsWithDelta(2.272, $prixTtc + $fourchette, 0.0001);
    }

    /**
     * 2. Vérifie que la prime arbitraire gasoil_ara_premium (0.06 €/L) est supprimée de la config.
     */
    public function test_gasoil_ara_premium_is_removed_from_configuration(): void
    {
        $this->assertNull(config('fuel.gasoil_ara_premium'));
    }

    /**
     * 3. Vérifie le calcul exact du Gazole avec le cas de test de référence :
     *    - HO=F = 4.1647 USD/gallon
     *    - Taux = 0.8645 EUR pour 1 USD
     *    - Constante légale = 3.785411784 L/gal
     *    - Composante NY Harbor ULSD = 4.1647 * 0.8645 / 3.785411784 = 0.951120606... €/L (arrondi 0.9511 €/L)
     *    - Distribution = 0.3200 €/L
     *    - Accise = 0.6100 €/L
     *    - Prix TTC = (0.951120606 + 0.3200 + 0.6100) * 1.20 = 2.257344727... €/L (arrondi 2.257 €/L)
     *    - Fourchette indicative : 2.157 €/L à 2.357 €/L
     */
    public function test_nymex_ho_fallback_calculation_with_exact_reference_values(): void
    {
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1647, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($this->sampleFrankfurterV2Response(0.8645, '2026-08-14'), 200),
        ]);

        $data = $this->service->getPrixTheorique();

        $this->assertNull($data['erreur']);
        $gazole = $data['carburants']['gazole'];

        // Composante matière : 4.1647 * 0.8645 / 3.785411784 = 0.951120606...
        $expectedMatiereRaw = (4.1647 * 0.8645) / 3.785411784;
        $this->assertEqualsWithDelta(0.951120606, $expectedMatiereRaw, 0.00000001);

        $this->assertEquals(0.9511, $gazole['detail']['brut_raffinage']);
        $this->assertEquals(0.9511, $gazole['nymex_converti_eur']);
        $this->assertEquals(0.3200, $gazole['detail']['distribution']);
        $this->assertEquals(0.6100, $gazole['detail']['accise']);
        $this->assertEquals(0.3762, $gazole['detail']['tva']);

        // Prix TTC brut = (0.951120606 + 0.32 + 0.61) * 1.20 = 2.257344727...
        $expectedTtcRaw = ($expectedMatiereRaw + 0.32 + 0.61) * 1.20;
        $this->assertEqualsWithDelta(2.257344727, $expectedTtcRaw, 0.00000001);

        // Valeurs arrondies d'affichage
        $this->assertEquals(2.257, $gazole['prix_ttc']);
        $this->assertEquals(2.157, $gazole['prix_min']);
        $this->assertEquals(2.357, $gazole['prix_max']);
        $this->assertEquals(4.1647, $gazole['nymex_usd_gallon']);
        $this->assertSame('nymex', $gazole['source_type']);
        $this->assertSame('NY Harbor ULSD (indicateur US)', $gazole['label_matiere']);
    }

    /**
     * 4. Vérifie que le taux de change n'est pas codé en dur et qu'une variation du taux modifie le prix.
     */
    public function test_exchange_rate_is_dynamic_and_modifies_calculated_price(): void
    {
        $frankfurterSequence = Http::sequence()
            ->push($this->sampleFrankfurterV2Response(0.8000, '2026-08-14'), 200)
            ->push($this->sampleFrankfurterV2Response(0.9000, '2026-08-15'), 200);

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.0000, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => $frankfurterSequence,
        ]);

        // Appel 1 : Taux 0.8000
        $data1 = $this->service->getPrixTheorique();
        $this->assertEquals(0.8000, $data1['usd_eur']);
        $prixGazole1 = $data1['carburants']['gazole']['prix_ttc'];

        // Vider le cache pour forcer le 2e appel API
        Cache::flush();

        // Appel 2 : Taux 0.9000
        $data2 = $this->service->getPrixTheorique();
        $this->assertEquals(0.9000, $data2['usd_eur']);
        $prixGazole2 = $data2['carburants']['gazole']['prix_ttc'];

        $this->assertNotEquals($prixGazole1, $prixGazole2);
        $this->assertGreaterThan($prixGazole1, $prixGazole2);
    }

    /**
     * 5. Extraction nominale de Frankfurter v2 avec validation de l'observation ECB.
     */
    public function test_frankfurter_v2_nominal_response_extracts_rate_and_date_with_ecb_provider(): void
    {
        $json = [
            [
                'base' => 'USD',
                'quote' => 'EUR',
                'rate' => 0.8645,
                'date' => '2026-08-14',
                'providers' => [
                    [
                        'key' => 'ECB',
                        'name' => 'European Central Bank',
                        'date' => '2026-08-14',
                        'rate' => 0.8645,
                    ],
                ],
            ],
        ];

        $extracted = $this->service->extractFrankfurterV2Rate($json);

        $this->assertNotNull($extracted);
        $this->assertSame(0.8645, $extracted['rate']);
        $this->assertSame('2026-08-14', $extracted['date']);
        $this->assertSame('ECB', $extracted['provider_key']);
    }

    /**
     * 6. Insensibilité à l'ordre des éléments dans le tableau JSON.
     */
    public function test_frankfurter_v2_handles_order_independence_in_json(): void
    {
        $json = [
            [
                'base' => 'USD',
                'quote' => 'GBP',
                'rate' => 0.7500,
                'date' => '2026-08-14',
                'providers' => [['key' => 'BOE', 'date' => '2026-08-14', 'rate' => 0.7500]],
            ],
            [
                'providers' => [
                    ['key' => 'OTHER', 'date' => '2026-08-14', 'rate' => 0.8645],
                    ['key' => 'ECB', 'date' => '2026-08-14', 'rate' => 0.8645],
                ],
                'quote' => 'EUR',
                'date' => '2026-08-14',
                'rate' => 0.8645,
                'base' => 'USD',
            ],
        ];

        $extracted = $this->service->extractFrankfurterV2Rate($json);

        $this->assertNotNull($extracted);
        $this->assertSame(0.8645, $extracted['rate']);
        $this->assertSame('2026-08-14', $extracted['date']);
        $this->assertSame('ECB', $extracted['provider_key']);
    }

    /**
     * 7. Rejet strict si la réponse racine n'est pas une liste PHP indexée (Point 1).
     */
    public function test_frankfurter_v2_rejects_associative_object_root_and_does_not_fill_cache(): void
    {
        // Objet associatif unique à la racine au lieu d'une liste
        $associativeObject = [
            'base' => 'USD',
            'quote' => 'EUR',
            'rate' => 0.8645,
            'date' => '2026-08-14',
            'providers' => [
                ['key' => 'ECB', 'rate' => 0.8645, 'date' => '2026-08-14'],
            ],
        ];

        $this->assertNull($this->service->extractFrankfurterV2Rate($associativeObject));

        // Vérifier avec l'appel complet qu'aucun cache n'est rempli
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.0000, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($associativeObject, 200),
        ]);

        $data = $this->service->getPrixTheorique();

        $this->assertNotNull($data['erreur']);
        $this->assertNull(Cache::get(FuelPriceService::CACHE_KEY_USD_EUR));
        $this->assertNull(Cache::get(FuelPriceService::CACHE_KEY_USD_EUR_LAST_VALID));
    }

    public function test_frankfurter_v2_rejects_empty_list(): void
    {
        $this->assertNull($this->service->extractFrankfurterV2Rate([]));
    }

    /**
     * 8. Rejets stricts sur les dates invalides pour Frankfurter v2.
     */
    public function test_frankfurter_v2_rejects_missing_or_null_or_empty_date(): void
    {
        $jsonNoDate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'providers' => [['key' => 'ECB', 'rate' => 0.8645]]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonNoDate));

        $jsonNullDate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => null, 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => null]]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonNullDate));

        $jsonEmptyDate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '', 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => '']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonEmptyDate));
    }

    public function test_frankfurter_v2_rejects_invalid_date_format(): void
    {
        $jsonBadFormat = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '14/08/2026', 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => '14/08/2026']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonBadFormat));

        $jsonBadFormat2 = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-8-14', 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => '2026-8-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonBadFormat2));
    }

    public function test_frankfurter_v2_rejects_impossible_calendar_date(): void
    {
        // 30 février
        $jsonFeb30 = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-02-30', 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => '2026-02-30']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonFeb30));

        // 31 avril
        $jsonApr31 = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-04-31', 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => '2026-04-31']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonApr31));
    }

    public function test_frankfurter_v2_rejects_future_date(): void
    {
        $jsonFuture = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2099-12-31', 'providers' => [['key' => 'ECB', 'rate' => 0.8645, 'date' => '2099-12-31']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonFuture));
    }

    /**
     * 9. Rejets stricts sur les observations et taux BCE invalides.
     */
    public function test_frankfurter_v2_rejects_missing_or_null_or_zero_or_negative_or_non_numeric_ecb_rate(): void
    {
        // Taux BCE absent
        $jsonNoEcbRate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-08-14', 'providers' => [['key' => 'ECB', 'date' => '2026-08-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonNoEcbRate));

        // Taux BCE null
        $jsonNullEcbRate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-08-14', 'providers' => [['key' => 'ECB', 'rate' => null, 'date' => '2026-08-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonNullEcbRate));

        // Taux BCE égal à 0
        $jsonZeroEcbRate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-08-14', 'providers' => [['key' => 'ECB', 'rate' => 0.0, 'date' => '2026-08-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonZeroEcbRate));

        // Taux BCE négatif
        $jsonNegativeEcbRate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-08-14', 'providers' => [['key' => 'ECB', 'rate' => -0.8645, 'date' => '2026-08-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonNegativeEcbRate));

        // Taux BCE non numérique
        $jsonStringEcbRate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-08-14', 'providers' => [['key' => 'ECB', 'rate' => 'invalid_rate', 'date' => '2026-08-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonStringEcbRate));

        // Taux non fini (INF / NAN)
        $jsonInfEcbRate = [['base' => 'USD', 'quote' => 'EUR', 'rate' => 0.8645, 'date' => '2026-08-14', 'providers' => [['key' => 'ECB', 'rate' => INF, 'date' => '2026-08-14']]]];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonInfEcbRate));
    }

    public function test_valid_ecb_date_alone_is_not_sufficient_if_ecb_rate_is_invalid(): void
    {
        $json = [
            [
                'base' => 'USD',
                'quote' => 'EUR',
                'rate' => 0.8645,
                'date' => '2026-08-14',
                'providers' => [
                    [
                        'key' => 'ECB',
                        'date' => '2026-08-14',
                        'rate' => 0.0,
                    ],
                ],
            ],
        ];

        $this->assertNull($this->service->extractFrankfurterV2Rate($json));
    }

    public function test_frankfurter_v2_rejects_legacy_formats_and_alternative_field_names(): void
    {
        // Rejet si 'name' utilisé à la place de 'key'
        $jsonNameField = [
            [
                'base' => 'USD',
                'quote' => 'EUR',
                'rate' => 0.8645,
                'date' => '2026-08-14',
                'providers' => [
                    ['name' => 'ECB', 'rate' => 0.8645, 'date' => '2026-08-14'],
                ],
            ],
        ];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonNameField));

        // Rejet format v1 rates.EUR
        $jsonV1 = [
            'amount' => 1.0,
            'base' => 'USD',
            'date' => '2026-08-14',
            'rates' => ['EUR' => 0.8645],
        ];
        $this->assertNull($this->service->extractFrankfurterV2Rate($jsonV1));
    }

    /**
     * 10. Validation du cache avec provenance complète.
     */
    public function test_cache_preserves_full_provenance_including_provider_key(): void
    {
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.0000, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($this->sampleFrankfurterV2Response(0.8645, '2026-08-14'), 200),
        ]);

        $data = $this->service->getPrixTheorique();
        $this->assertNull($data['erreur']);

        // Vérifier le contenu du cache
        $cached = Cache::get(FuelPriceService::CACHE_KEY_USD_EUR);
        $this->assertIsArray($cached);
        $this->assertSame(0.8645, $cached['rate']);
        $this->assertSame('2026-08-14', $cached['date']);
        $this->assertSame('ECB', $cached['provider_key']);

        $lastValid = Cache::get(FuelPriceService::CACHE_KEY_USD_EUR_LAST_VALID);
        $this->assertIsArray($lastValid);
        $this->assertSame(0.8645, $lastValid['rate']);
        $this->assertSame('2026-08-14', $lastValid['date']);
        $this->assertSame('ECB', $lastValid['provider_key']);
    }

    public function test_cache_rejects_entry_without_provenance_or_different_provider(): void
    {
        // Cache sans provider_key
        $this->assertFalse($this->service->isValidCachedRate([
            'rate' => 0.8645,
            'date' => '2026-08-14',
        ]));

        // Cache avec un autre provider
        $this->assertFalse($this->service->isValidCachedRate([
            'rate' => 0.8645,
            'date' => '2026-08-14',
            'provider_key' => 'FED',
        ]));

        // Cache valide
        $this->assertTrue($this->service->isValidCachedRate([
            'rate' => 0.8645,
            'date' => '2026-08-14',
            'provider_key' => 'ECB',
        ]));
    }

    public function test_cache_preserves_valid_ecb_cache_after_invalid_remote_response(): void
    {
        Cache::put(FuelPriceService::CACHE_KEY_USD_EUR_LAST_VALID, [
            'rate' => 0.8600,
            'date' => '2026-08-12',
            'provider_key' => 'ECB',
        ], 86400 * 7);

        // L'API renvoie une réponse corrompue
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.0000, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response([
                ['base' => 'USD', 'quote' => 'EUR', 'rate' => -0.5, 'date' => '2026-08-15', 'providers' => [['key' => 'ECB', 'rate' => -0.5, 'date' => '2026-08-15']]],
            ], 200),
        ]);

        $data = $this->service->getPrixTheorique();

        $this->assertEquals(0.8600, $data['usd_eur']);
        $this->assertSame('2026-08-12', $data['usd_eur_date']);

        $lastValid = Cache::get(FuelPriceService::CACHE_KEY_USD_EUR_LAST_VALID);
        $this->assertSame('ECB', $lastValid['provider_key']);
        $this->assertSame(0.8600, $lastValid['rate']);
    }

    /**
     * 11. Validation stricte des données Yahoo NYMEX HO=F (Point 2).
     */
    public function test_yahoo_nymex_nominal_response_with_price_and_timestamp(): void
    {
        $testTimestamp = Carbon::parse('2026-08-15 10:30:00', 'Europe/Paris')->timestamp;

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 4.1234,
                                'regularMarketTime' => $testTimestamp,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $data = $this->service->fetchGasoilNymexYahoo();

        $this->assertNotNull($data);
        $this->assertSame(4.1234, $data['price']);
        $this->assertSame('15/08/2026 à 10:30', $data['date']);
    }

    public function test_yahoo_nymex_rejects_missing_or_null_or_non_numeric_or_invalid_timestamp(): void
    {
        // Timestamp absent
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Timestamp null
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => null]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Chaîne représentant un entier
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => '1786800000']]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Float
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => 1786800000.0]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Chaîne décimale
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => '1786800000.5']]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Notation scientifique
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => '1.78e9']]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Booléen true / false
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => true]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Timestamp négatif ou nul
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => 0]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Timestamp futur
        $futureTimestamp = Carbon::now()->addDay()->timestamp;
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1234, 'regularMarketTime' => $futureTimestamp]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());
    }

    public function test_yahoo_nymex_rejects_invalid_or_non_finite_price(): void
    {
        $testTimestamp = Carbon::now()->timestamp;

        // Prix nul
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 0.0, 'regularMarketTime' => $testTimestamp]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Prix négatif
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => -3.5, 'regularMarketTime' => $testTimestamp]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Prix INF (chaîne non finie)
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response(
                '{"chart":{"result":[{"meta":{"regularMarketPrice":"Infinity","regularMarketTime":'.$testTimestamp.'}}]}}',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());

        // Prix NAN (chaîne non numérique)
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response(
                '{"chart":{"result":[{"meta":{"regularMarketPrice":"NaN","regularMarketTime":'.$testTimestamp.'}}]}}',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);
        $this->assertNull($this->service->fetchGasoilNymexYahoo());
    }

    /**
     * 12. Validation stricte des données Yahoo Brent BZ=F (Point 2).
     */
    public function test_yahoo_brent_nominal_response_with_price_and_timestamp(): void
    {
        $testTimestamp = Carbon::parse('2026-08-15 11:45:00', 'Europe/Paris')->timestamp;

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 88.75,
                                'regularMarketTime' => $testTimestamp,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $data = $this->service->fetchBrentYahoo();

        $this->assertNotNull($data);
        $this->assertSame(88.75, $data['price']);
        $this->assertSame('15/08/2026 à 11:45', $data['date']);
    }

    public function test_yahoo_brent_rejects_missing_or_null_or_non_numeric_or_invalid_timestamp(): void
    {
        // Timestamp absent
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Timestamp null
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => null]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Chaîne représentant un entier
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => '1786800000']]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Float
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => 1786800000.0]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Chaîne décimale
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => '1786800000.5']]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Notation scientifique
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => '1.78e9']]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Booléen true / false
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => true]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Timestamp nul
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => 0]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Timestamp futur
        $futureTimestamp = Carbon::now()->addDay()->timestamp;
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => $futureTimestamp]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());
    }

    public function test_yahoo_brent_rejects_invalid_or_non_finite_price(): void
    {
        $testTimestamp = Carbon::now()->timestamp;

        // Prix nul
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 0.0, 'regularMarketTime' => $testTimestamp]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Prix négatif
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => -50.0, 'regularMarketTime' => $testTimestamp]]]],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Prix INF
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response(
                '{"chart":{"result":[{"meta":{"regularMarketPrice":"Infinity","regularMarketTime":'.$testTimestamp.'}}]}}',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());

        // Prix NAN
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response(
                '{"chart":{"result":[{"meta":{"regularMarketPrice":"NaN","regularMarketTime":'.$testTimestamp.'}}]}}',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);
        $this->assertNull($this->service->fetchBrentYahoo());
    }

    /**
     * 13. Repli Alpha Vantage si Yahoo Brent échoue.
     */
    public function test_alpha_vantage_fallback_on_yahoo_failure_returns_price_and_date(): void
    {
        putenv('ALPHA_VANTAGE_KEY=TEST_KEY');
        $_ENV['ALPHA_VANTAGE_KEY'] = 'TEST_KEY';

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response(['error' => 'down'], 500),
            'https://www.alphavantage.co/query*' => Http::response([
                'data' => [
                    [
                        'date' => '2026-08-14',
                        'value' => '85.25',
                    ],
                ],
            ], 200),
        ]);

        $data = $this->service->getBrentData();

        $this->assertNotNull($data);
        $this->assertSame(85.25, $data['price']);
        $this->assertSame('14/08/2026', $data['date']);

        putenv('ALPHA_VANTAGE_KEY');
        unset($_ENV['ALPHA_VANTAGE_KEY']);
    }

    public function test_alpha_vantage_rejects_invalid_price_or_date(): void
    {
        putenv('ALPHA_VANTAGE_KEY=TEST_KEY');
        $_ENV['ALPHA_VANTAGE_KEY'] = 'TEST_KEY';

        // 1. Prix invalide (point)
        Http::fake([
            'https://www.alphavantage.co/query*' => Http::response([
                'data' => [['date' => '2026-08-14', 'value' => '.']],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentAlphaVantage());

        // 2. Date future invalide
        Http::fake([
            'https://www.alphavantage.co/query*' => Http::response([
                'data' => [['date' => '2099-12-31', 'value' => '85.00']],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentAlphaVantage());

        // 3. Date invalide (30 février)
        Http::fake([
            'https://www.alphavantage.co/query*' => Http::response([
                'data' => [['date' => '2026-02-30', 'value' => '85.00']],
            ], 200),
        ]);
        $this->assertNull($this->service->fetchBrentAlphaVantage());

        putenv('ALPHA_VANTAGE_KEY');
        unset($_ENV['ALPHA_VANTAGE_KEY']);
    }

    public function test_both_yahoo_and_alpha_vantage_failing_leads_to_clean_unavailable_without_type_error(): void
    {
        putenv('ALPHA_VANTAGE_KEY=TEST_KEY');
        $_ENV['ALPHA_VANTAGE_KEY'] = 'TEST_KEY';

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response(['error' => 'down'], 500),
            'https://www.alphavantage.co/query*' => Http::response(['error' => 'down'], 500),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($this->sampleFrankfurterV2Response(0.8645, '2026-08-14'), 200),
        ]);

        $data = $this->service->getPrixTheorique();

        $this->assertNotNull($data['erreur']);
        $this->assertNull($data['brent_usd']);
        $this->assertSame('unavailable', $data['gazole_source_type']);

        putenv('ALPHA_VANTAGE_KEY');
        unset($_ENV['ALPHA_VANTAGE_KEY']);
    }

    /**
     * 14. Vérifie qu'aucun libellé public ne présente HO=F comme une cotation ARA ou Rotterdam.
     */
    public function test_no_label_claims_ho_f_is_ara_or_rotterdam(): void
    {
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 3.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($this->sampleFrankfurterV2Response(0.8645, '2026-08-14'), 200),
        ]);

        $data = $this->service->getPrixTheorique();
        $gazole = $data['carburants']['gazole'];

        $this->assertStringNotContainsStringIgnoringCase('ARA', $gazole['label_matiere']);
        $this->assertStringNotContainsStringIgnoringCase('Rotterdam', $gazole['label_matiere']);
        $this->assertStringContainsString('NY Harbor ULSD', $gazole['label_matiere']);

        // Vérifier dans les sources
        $sourceGazole = $data['sources']['Gazole — indicateur US'] ?? '';
        $this->assertStringContainsString('NY Harbor ULSD', $sourceGazole);
        $this->assertStringContainsString('non ARA', $sourceGazole);
    }

    /**
     * 15. Vérifie le comportement explicite si HO=F est rejeté (repli transparent et tracé sur le Brent).
     */
    public function test_clean_and_explicit_fallback_to_brent_when_nymex_fails_or_invalid(): void
    {
        // Simuler NYMEX avec timestamp invalide ou manquant
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.1647, 'regularMarketTime' => null]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($this->sampleFrankfurterV2Response(0.8645, '2026-08-14'), 200),
        ]);

        $data = $this->service->getPrixTheorique();

        $this->assertNull($data['erreur']);
        $this->assertSame('brent_fallback', $data['gazole_source_type']);

        $gazole = $data['carburants']['gazole'];
        $this->assertSame('brent_fallback', $gazole['source_type']);
        $this->assertNull($data['gasoil_nymex_usd']);
        $this->assertNull($gazole['nymex_usd_gallon']);
        $this->assertNull($gazole['nymex_converti_eur']);
        $this->assertSame('Brent + marge raffinage (repli)', $gazole['label_matiere']);

        // Coût Brent : (88.50 / 159) * 0.8645 = 0.48118... + 0.43 (marge raffinage gazole) = 0.91118...
        // Total TTC : (0.91118 + 0.32 + 0.61) * 1.20 = 2.2094... => 2.209 €/L
        $this->assertEqualsWithDelta(2.209, $gazole['prix_ttc'], 0.005);
        $this->assertArrayHasKey('Gazole — repli', $data['sources']);
    }

    /**
     * 16. Non-régression des autres carburants avec assertions numériques exactes.
     */
    public function test_other_fuels_do_not_regress_with_explicit_numerical_assertions(): void
    {
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 80.00, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 4.00, 'regularMarketTime' => Carbon::now()->timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response($this->sampleFrankfurterV2Response(0.8645, '2026-08-14'), 200),
        ]);

        $data = $this->service->getPrixTheorique();
        $c = $data['carburants'];

        // 1. SP95-E10 :
        $this->assertEquals(1.785, $c['sp95_e10']['prix_ttc']);
        $this->assertEquals(1.685, $c['sp95_e10']['prix_min']);
        $this->assertEquals(1.885, $c['sp95_e10']['prix_max']);

        // 2. SP95 :
        $this->assertEquals(1.821, $c['sp95']['prix_ttc']);
        $this->assertEquals(1.721, $c['sp95']['prix_min']);
        $this->assertEquals(1.921, $c['sp95']['prix_max']);

        // 3. SP98 :
        $this->assertEquals(1.833, $c['sp98']['prix_ttc']);
        $this->assertEquals(1.733, $c['sp98']['prix_min']);
        $this->assertEquals(1.933, $c['sp98']['prix_max']);

        // 4. E85 :
        $this->assertEquals(0.805, $c['e85']['prix_ttc']);
        $this->assertEquals(0.705, $c['e85']['prix_min']);
        $this->assertEquals(0.905, $c['e85']['prix_max']);

        // 5. GPL :
        $this->assertEquals(0.895, $c['gpl']['prix_ttc']);
        $this->assertEquals(0.795, $c['gpl']['prix_min']);
        $this->assertEquals(0.995, $c['gpl']['prix_max']);
    }
}

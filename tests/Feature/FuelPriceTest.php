<?php

namespace Tests\Feature;

use App\Services\ObservedFuelPriceService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuelPriceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Paris'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Configure tous les fakes HTTP nominaux nécessaires pour le rendu complet de la page.
     */
    private function fakeAllNominalApis(): void
    {
        $timestamp = Carbon::now()->timestamp;

        Http::fake([
            // Yahoo Finance Brent
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 88.50,
                                'regularMarketTime' => $timestamp,
                            ],
                        ],
                    ],
                ],
            ], 200),

            // Yahoo Finance Gasoil (HO=F)
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 2.45,
                                'regularMarketTime' => $timestamp,
                            ],
                        ],
                    ],
                ],
            ], 200),

            // Frankfurter API v2 USD vers EUR
            'https://api.frankfurter.dev/v2/rates*' => Http::response([
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
            ], 200),

            // API Open Data officiel DGCCRF
            ObservedFuelPriceService::API_URL.'*' => Http::response([
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
            ], 200),
        ]);
    }

    /**
     * 13. Présence des nouveaux textes publics sur la page d'accueil
     */
    public function test_page_displays_updated_public_texts(): void
    {
        $this->fakeAllNominalApis();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Quel pourrait être le prix du carburant aujourd’hui', false);
        $response->assertSee('Estimation indicative calculée à partir des dernières données disponibles pour le pétrole Brent et le taux de change du dollar vers l’euro.', false);
        $response->assertSee('Ce résultat constitue un repère et non un prix garanti.', false);
        $response->assertSee('Fourchette indicative :', false);
        $response->assertSee('Prix moyen national déclaré', false);
        $response->assertSee('DGCCRF – prix-carburants.gouv.fr', false);
        $response->assertSee('Licence Ouverte 2.0', false);
        $response->assertSee('Moyenne arithmétique non pondérée des prix actuellement présents dans le flux officiel.', false);
    }

    /**
     * 14. Absence totale de balises iframe dans le HTML rendu
     */
    public function test_rendered_page_contains_no_iframe(): void
    {
        $this->fakeAllNominalApis();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('<iframe', false);
        $response->assertDontSee('</iframe>', false);
    }

    /**
     * 15. Absence de prix-carburant.eu dans le HTML rendu
     */
    public function test_rendered_page_contains_no_reference_to_prix_carburant_eu(): void
    {
        $this->fakeAllNominalApis();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('prix-carburant.eu', false);
    }

    /**
     * 16. Libellé explicite du taux de référence BCE et date de valeur
     */
    public function test_explicit_usd_to_eur_exchange_rate_label(): void
    {
        $this->fakeAllNominalApis();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Taux de référence BCE', false);
        $response->assertSee('1 USD = 0,8645', false);
        $response->assertSee('Date du taux BCE : 14/08/2026', false);
        $response->assertSee('Page générée le', false);
        $response->assertDontSee('Dernière valeur disponible', false);
    }

    /**
     * 16b. Contrôle strict et exact de la requête HTTP émise vers l'API officielle Frankfurter v2
     */
    public function test_frankfurter_v2_exact_request_parameters(): void
    {
        $this->fakeAllNominalApis();

        $this->get('/');

        Http::assertSent(function (Request $request) {
            $url = $request->url();
            $parsed = parse_url($url);

            $hostMatches = ($parsed['host'] ?? '') === 'api.frankfurter.dev';
            $pathMatches = ($parsed['path'] ?? '') === '/v2/rates';

            parse_str($parsed['query'] ?? '', $queryParams);

            $baseMatches = ($queryParams['base'] ?? null) === 'USD';
            $quotesMatches = ($queryParams['quotes'] ?? null) === 'EUR';
            $providersMatches = ($queryParams['providers'] ?? null) === 'ECB';
            $expandMatches = ($queryParams['expand'] ?? null) === 'providers';

            return $hostMatches && $pathMatches && $baseMatches && $quotesMatches && $providersMatches && $expandMatches;
        });
    }

    /**
     * 17. Rendu de la carte Gazole : texte explicite NY Harbor ULSD, valeur convertie et absence de fausse mention ARA
     */
    public function test_gazole_card_renders_nymex_note_without_ara_misleading_labels(): void
    {
        $this->fakeAllNominalApis();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Prix calculé à partir du <strong>NY Harbor ULSD (HO=F)</strong>, indicateur de repli du marché américain (New York). Ce contrat reflète les distillats américains corrélés aux marchés mondiaux, mais ne constitue pas la cotation physique européenne ARA Rotterdam ni le coût d’approvisionnement réel en France.', false);
        $response->assertSee('NYMEX HO=F&nbsp;: 2,4500&nbsp;USD/gallon', false);
        $response->assertSee('Valeur convertie&nbsp;: 0,5595&nbsp;EUR/L', false);
        $response->assertDontSee('proxy gasoil ARA', false);
        $response->assertDontSee('prime ARA', false);
        $response->assertDontSee('prix ARA', false);
        $response->assertDontSee('temps réel européen', false);
    }

    /**
     * 18. Rendu de la carte Gazole en mode repli explicite sur le Brent si HO=F échoue
     */
    public function test_gazole_card_renders_brent_fallback_explicitly_when_nymex_fails(): void
    {
        $timestamp = Carbon::now()->timestamp;

        Http::fake([
            // Yahoo Brent OK
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 88.50,
                                'regularMarketTime' => $timestamp,
                            ],
                        ],
                    ],
                ],
            ], 200),

            // Yahoo HO=F DOWN
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response(['error' => 'down'], 500),

            // Frankfurter API v2
            'https://api.frankfurter.dev/v2/rates*' => Http::response([
                [
                    'base' => 'USD',
                    'quote' => 'EUR',
                    'rate' => 0.8645,
                    'date' => '2026-08-14',
                    'providers' => [
                        ['key' => 'ECB', 'date' => '2026-08-14', 'rate' => 0.8645],
                    ],
                ],
            ], 200),

            ObservedFuelPriceService::API_URL.'*' => Http::response(['total_count' => 0, 'results' => []], 200),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Information repli :', false);
        $response->assertSee('cette estimation est temporairement calculée à partir du <strong>cours du Brent</strong> avec marge de raffinage moyenne.', false);
        $response->assertSee('Brent + marge raffinage (repli)', false);
    }

    /**
     * 19. Affichage des prix moyens nationaux déclarés et des métadonnées
     */
    public function test_observed_national_averages_rendered_correctly(): void
    {
        $this->fakeAllNominalApis();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('2,209', false); // Gazole moyen
        $response->assertSee('9 600 déclarations prises en compte', false);
        $response->assertSee('2,008', false); // SP95-E10 moyen
        $response->assertSee('7 500 déclarations prises en compte', false);
    }

    /**
     * 20. Affichage du message de repli lorsque le cache valide de repli est utilisé
     */
    public function test_fallback_banner_displayed_when_using_cached_last_valid_result(): void
    {
        $timestamp = Carbon::now()->timestamp;

        // 1. Préparer un jeu de données de repli dans le cache LAST_VALID
        $cachedData = [
            'status' => 'fresh',
            'is_fallback' => false,
            'recupere_le' => '15/08/2026 à 12:00',
            'carburants' => [
                'sp95_e10' => ['cle' => 'sp95_e10', 'nom' => 'SP95-E10', 'prix_moyen' => 2.008, 'nb_declarations' => 7500, 'derniere_maj' => '15/08/2026 à 12:00', 'disponible' => true],
                'sp95' => ['cle' => 'sp95', 'nom' => 'SP95', 'prix_moyen' => 2.049, 'nb_declarations' => 3000, 'derniere_maj' => '15/08/2026 à 12:00', 'disponible' => true],
                'sp98' => ['cle' => 'sp98', 'nom' => 'SP98', 'prix_moyen' => 2.091, 'nb_declarations' => 7500, 'derniere_maj' => '15/08/2026 à 12:00', 'disponible' => true],
                'gazole' => ['cle' => 'gazole', 'nom' => 'Gazole', 'prix_moyen' => 2.209, 'nb_declarations' => 9600, 'derniere_maj' => '15/08/2026 à 12:00', 'disponible' => true],
                'e85' => ['cle' => 'e85', 'nom' => 'E85', 'prix_moyen' => 0.863, 'nb_declarations' => 3900, 'derniere_maj' => '15/08/2026 à 12:00', 'disponible' => true],
                'gpl' => ['cle' => 'gpl', 'nom' => 'GPL', 'prix_moyen' => 1.051, 'nb_declarations' => 1500, 'derniere_maj' => '15/08/2026 à 12:00', 'disponible' => true],
            ],
            'source' => 'DGCCRF – prix-carburants.gouv.fr',
            'licence' => 'Licence Ouverte 2.0',
            'message' => null,
        ];
        Cache::put(ObservedFuelPriceService::CACHE_KEY_LAST_VALID, $cachedData, 86400);

        // 2. Simuler une panne de l'API Open Data
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => $timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 2.45, 'regularMarketTime' => $timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response([
                [
                    'base' => 'USD',
                    'quote' => 'EUR',
                    'rate' => 0.8645,
                    'date' => '2026-08-14',
                    'providers' => [['key' => 'ECB', 'date' => '2026-08-14', 'rate' => 0.8645]],
                ],
            ], 200),
            ObservedFuelPriceService::API_URL.'*' => Http::response(['error' => 'API down'], 500),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Dernières données officielles disponibles, récupérées le 15/08/2026 à 12:00', false);
        $response->assertSee('2,209', false);
    }

    /**
     * 21. Affichage du bloc d'indisponibilité propre lorsque aucune donnée observée n'est disponible
     */
    public function test_clean_unavailable_block_displayed_when_no_data_available(): void
    {
        $timestamp = Carbon::now()->timestamp;

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50, 'regularMarketTime' => $timestamp]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 2.45, 'regularMarketTime' => $timestamp]]]],
            ], 200),
            'https://api.frankfurter.dev/v2/rates*' => Http::response([
                [
                    'base' => 'USD',
                    'quote' => 'EUR',
                    'rate' => 0.8645,
                    'date' => '2026-08-14',
                    'providers' => [['key' => 'ECB', 'date' => '2026-08-14', 'rate' => 0.8645]],
                ],
            ], 200),
            ObservedFuelPriceService::API_URL.'*' => Http::response(['error' => 'API down'], 500),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Prix moyen national momentanément indisponible.', false);
    }
}

<?php

namespace Tests\Feature;

use App\Services\ObservedFuelPriceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 88.50]]]],
            ], 200),
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => Http::response([
                'chart' => ['result' => [['meta' => ['regularMarketPrice' => 2.45]]]],
            ], 200),
            'https://api.frankfurter.app/latest?from=USD&to=EUR' => Http::response([
                'rates' => ['EUR' => 0.8645],
            ], 200),
            ObservedFuelPriceService::API_URL.'*' => Http::response([
                'total_count' => 9800,
                'results' => [
                    [
                        'gazole_avg' => 2.209,
                        'gazole_count' => 9600,
                        'gazole_maj' => '2026-08-15T14:08:22+00:00',
                        'e10_avg' => 2.008,
                        'e10_count' => 7500,
                        'e10_maj' => '2026-08-15T14:08:23+00:00',
                        'sp95_avg' => 2.049,
                        'sp95_count' => 3000,
                        'sp95_maj' => '2026-08-15T14:00:00+00:00',
                        'sp98_avg' => 2.091,
                        'sp98_count' => 7500,
                        'sp98_maj' => '2026-08-15T14:08:23+00:00',
                        'e85_avg' => 0.863,
                        'e85_count' => 3900,
                        'e85_maj' => '2026-08-15T13:36:50+00:00',
                        'gplc_avg' => 1.051,
                        'gplc_count' => 1500,
                        'gplc_maj' => '2026-08-15T14:08:23+00:00',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}

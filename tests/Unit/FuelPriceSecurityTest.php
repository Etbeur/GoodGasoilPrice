<?php

namespace Tests\Unit;

use App\Services\FuelPriceService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FuelPriceSecurityTest extends TestCase
{
    private FuelPriceService $service;

    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $loggedRecords = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        $this->service = new FuelPriceService;
        $this->loggedRecords = [];

        // Écoute de tous les événements de log
        Log::listen(function ($level, $message = null, $context = []) {
            // Support formats Laravel log events
            if (is_object($level) && property_exists($level, 'message')) {
                $this->loggedRecords[] = [
                    'level' => $level->level ?? 'unknown',
                    'message' => (string) ($level->message ?? ''),
                    'context' => (array) ($level->context ?? []),
                ];
            } else {
                $this->loggedRecords[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => (array) $context,
                ];
            }
        });
    }

    /**
     * Test de non-divulgation de secret en cas d'exception réseau (Alpha Vantage).
     */
    public function test_sensitive_secret_is_never_logged_on_alpha_vantage_exception(): void
    {
        $secretKey = 'TEST_SECRET_ALPHA_VANTAGE_DO_NOT_LOG';
        putenv("ALPHA_VANTAGE_KEY={$secretKey}");
        $_ENV['ALPHA_VANTAGE_KEY'] = $secretKey;

        // Yahoo Finance échoue -> bascule sur Alpha Vantage qui lève une exception contenant le secret
        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/BZ=F*' => Http::response(null, 500),
            'https://www.alphavantage.co/query*' => function () use ($secretKey) {
                throw new ConnectionException("Failed to connect to AlphaVantage host with apikey={$secretKey}");
            },
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getBrentPrice');
        $method->setAccessible(true);

        // Exécution de l'appel protégé avec fallback sur Alpha Vantage
        $result = $method->invoke($this->service);

        // 1. Le résultat doit être null sans exception non rattrapée
        $this->assertNull($result);

        // 2. Vérification stricte que le secret n'apparaît dans aucun log (message ou contexte)
        $this->assertNotEmpty($this->loggedRecords, 'Des logs doivent avoir été émis.');

        foreach ($this->loggedRecords as $record) {
            $this->assertStringNotContainsString(
                $secretKey,
                $record['message'],
                "Le secret a été détecté dans le message de log : {$record['message']}"
            );

            $contextJson = json_encode($record['context']);
            $this->assertStringNotContainsString(
                $secretKey,
                $contextJson,
                "Le secret a été détecté dans le contexte du log : {$contextJson}"
            );
        }

        // Nettoyage de l'environnement
        putenv('ALPHA_VANTAGE_KEY');
        unset($_ENV['ALPHA_VANTAGE_KEY']);
    }

    /**
     * Test de non-divulgation sur exception Yahoo Finance (HO=F et BZ=F).
     */
    public function test_exception_messages_are_never_logged_on_yahoo_exceptions(): void
    {
        $sensitiveMessage = 'SENSITIVE_INTERNAL_HOST_OR_TOKEN_LEAK_HO_F';

        Http::fake([
            'https://query1.finance.yahoo.com/v8/finance/chart/HO=F*' => function () use ($sensitiveMessage) {
                throw new ConnectionException("Connection refused : {$sensitiveMessage}");
            },
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('fetchGasoilRotterdamYahoo');
        $method->setAccessible(true);

        $result = $method->invoke($this->service);

        $this->assertNull($result);

        foreach ($this->loggedRecords as $record) {
            $this->assertStringNotContainsString($sensitiveMessage, $record['message']);
            $this->assertStringNotContainsString($sensitiveMessage, json_encode($record['context']));
        }
    }

    /**
     * Test de non-divulgation sur exception Frankfurter API.
     */
    public function test_exception_messages_are_never_logged_on_frankfurter_exception(): void
    {
        $sensitiveMessage = 'SENSITIVE_FRANKFURTER_NETWORK_TRACE_TOKEN';

        Http::fake([
            'https://api.frankfurter.app/latest?from=USD&to=EUR' => function () use ($sensitiveMessage) {
                throw new ConnectionException("Socket error with query token : {$sensitiveMessage}");
            },
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getUsdEurRate');
        $method->setAccessible(true);

        $result = $method->invoke($this->service);

        $this->assertNull($result);

        foreach ($this->loggedRecords as $record) {
            $this->assertStringNotContainsString($sensitiveMessage, $record['message']);
            $this->assertStringNotContainsString($sensitiveMessage, json_encode($record['context']));
        }
    }
}

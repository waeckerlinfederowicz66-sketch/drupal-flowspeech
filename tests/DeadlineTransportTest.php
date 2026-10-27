<?php
declare(strict_types=1);

use Drupal\flowspeech\Api\FlowSpeechClient;
use Drupal\flowspeech\Api\FlowSpeechException;
use Drupal\flowspeech\FlowSpeechApiService;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;

final class DeadlineTransportTest extends TestCase {
    private FlowSpeechApiService $service;
    private string $endpoint;

    protected function setUp(): void {
        $endpoint = getenv('FLOWSPEECH_DEADLINE_ENDPOINT');
        if (!$endpoint) self::markTestSkipped('Run scripts/run-deadline.mjs for the verified TLS fixture.');
        self::assertSame('127.0.0.1', parse_url($endpoint, PHP_URL_HOST));
        $this->endpoint = $endpoint;
        $reflection = new ReflectionClass(FlowSpeechApiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($this->service, new Client());
        $reflection->getProperty('transportClient')->setValue($this->service, null);
    }

    public function testSlowHeadersAndBodyHaveOneHardTimeout(): void {
        foreach (['slow-headers', 'slow-body'] as $path) {
            $started = hrtime(true) / 1e9;
            try {
                $this->service->transport(['url' => $this->endpoint . '/' . $path, 'method' => 'GET',
                    'headers' => [], 'body' => null, 'timeout' => 0.2, 'maxBytes' => 1024]);
                self::fail('Slow transfer was accepted.');
            } catch (FlowSpeechException $error) {
                self::assertSame('network_error', $error->errorCode);
                self::assertTrue($error->retryable);
                self::assertNull($error->getPrevious());
            }
            $elapsed = hrtime(true) / 1e9 - $started;
            self::assertGreaterThan(0.1, $elapsed);
            self::assertLessThan(0.8, $elapsed, $path . ' must not wait for all headers/body chunks.');
            fwrite(STDOUT, sprintf("%s: budget=0.2s elapsed=%.3fs\n", $path, $elapsed));
        }
        $response = $this->service->transport(['url' => $this->endpoint . '/ok', 'method' => 'GET',
            'headers' => [], 'body' => null, 'timeout' => 1, 'maxBytes' => 1024]);
        self::assertSame('{}', $response['body'], 'Transport remains usable after cancellation.');
    }

    public function testClientDeadlineRetainsRecoveryId(): void {
        $requestId = '51382b81-9d36-49a5-994e-9b67686c6bd1';
        $client = new FlowSpeechClient('fixture-only', $this->endpoint, 1, [$this->service, 'transport'],
            ['max_retries' => 0, 'request_timeout' => 1]);
        $started = hrtime(true) / 1e9;
        try {
            $client->createJob(['mode' => 'single', 'text' => 'Fixture', 'voiceName' => 'Kore'], $requestId);
            self::fail('Slow submission was accepted.');
        } catch (FlowSpeechException $error) {
            self::assertSame('network_error', $error->errorCode);
            self::assertSame($requestId, $error->requestId);
            self::assertNull($error->jobId);
        }
        self::assertLessThan(1.6, hrtime(true) / 1e9 - $started);
    }

    public function testDrupalDefaultAndCustomStreamHandlerCannotOverrideCurlDeadline(): void {
        new Settings([]);
        foreach ([HandlerStack::create(), HandlerStack::create(new StreamHandler())] as $stack) {
            $http = (new ClientFactory($stack))->fromOptions();
            $reflection = new ReflectionClass(FlowSpeechApiService::class);
            $service = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('httpClient')->setValue($service, $http);
            $reflection->getProperty('transportClient')->setValue($service, null);
            $started = hrtime(true) / 1e9;
            try {
                $service->transport(['url' => $this->endpoint . '/slow-headers', 'method' => 'GET',
                    'headers' => [], 'body' => null, 'timeout' => 0.2, 'maxBytes' => 1024]);
                self::fail('Site handler replaced the bounded cURL transport.');
            } catch (FlowSpeechException $error) {
                self::assertSame('network_error', $error->errorCode);
            }
            self::assertLessThan(0.8, hrtime(true) / 1e9 - $started);
            self::assertSame($stack, $http->getConfig('handler'), 'The caller-owned stack must not be mutated.');
        }
    }
}

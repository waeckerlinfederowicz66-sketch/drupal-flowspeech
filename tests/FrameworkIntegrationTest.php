<?php
declare(strict_types=1);

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\flowspeech\Api\FlowSpeechException;
use Drupal\flowspeech\FlowSpeechApiService;
use Drupal\flowspeech\Plugin\AiProvider\FlowSpeechProvider;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class FrameworkIntegrationTest extends TestCase
{
    private array $endpoints;
    private FlowSpeechApiService $service;
    private ConfigFactoryInterface $config;
    private Client $http;
    private KeyRepositoryInterface $keys;
    private LoggerChannelFactoryInterface $logger;

    protected function setUp(): void
    {
        if (!getenv('FLOWSPEECH_FIXTURE_ENDPOINTS')) self::markTestSkipped('Requires the loopback fixture runner.');
        $this->endpoints = json_decode(getenv('FLOWSPEECH_FIXTURE_ENDPOINTS'), true);
        $this->control('success');
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $uri = $request->getUri();
            if ($uri->getHost() === 'flowspeech.io') {
                return $request->withUri(new Uri($this->endpoints['api'] . $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : '')));
            }
            if ($uri->getHost() !== '127.0.0.1') throw new LogicException('Non-loopback network access blocked by test.');
            return $request;
        }));
        $this->http = new Client(['handler' => $stack, 'headers' => ['Authorization' => 'must-not-leak', 'Cookie' => 'must-not-leak']]);
        $settings = $this->createMock(ImmutableConfig::class);
        $settings->method('get')->willReturnCallback(static fn($key) => $key === 'timeout_seconds' ? 4 : null);
        $this->config = $this->createMock(ConfigFactoryInterface::class);
        $this->config->method('get')->willReturn($settings);
        $this->keys = $this->createMock(KeyRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerChannelFactoryInterface::class);
        $this->service = new FlowSpeechApiService($this->config, $this->http, $this->keys, $this->logger, $this->http);
        $this->service->setApiKey('fixture-only');
    }

    private function control(?string $scenario = null): array
    {
        $context = $scenario === null ? null : stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['scenario' => $scenario]), 'timeout' => 2,
        ]]);
        return json_decode(file_get_contents($this->endpoints['http'] . '/control', false, $context), true);
    }

    public function testActualFrameworkTypesAndGuzzleWav(): void
    {
        $quota = $this->service->getQuota();
        self::assertSame(20, $quota['quota']['available']);
        self::assertArrayHasKey('subscription', $quota);
        self::assertArrayNotHasKey('entitlement', $quota);
        $this->control('quota-active');
        self::assertSame(['plan' => 'basic', 'status' => 'active'], $this->service->getQuota()['subscription']);
        self::assertSame('Kore', $this->service->getVoices()['voices'][0]['name']);
        $audio = $this->service->textToSpeech('Hello');
        self::assertSame('RIFF', substr($audio['binary'], 0, 4));
        self::assertSame('audio/wav', $audio['mime_type']);
        foreach ($this->control()['records'] as $request) {
            self::assertArrayNotHasKey('cookie', $request['headers']);
            if ($request['role'] === 'api') self::assertSame('Bearer fixture-only', $request['headers']['authorization']);
            else self::assertArrayNotHasKey('authorization', $request['headers']);
        }
    }

    public function testInterruptedProductionResponseRetainsStatusAndCooldown(): void
    {
        $service = new FlowSpeechApiService($this->config, $this->http, $this->keys, $this->logger);
        $requestId = '51382b81-9d36-49a5-994e-9b67686c6bd1';
        foreach (['truncated', 'slow-body'] as $mode) {
            foreach ([401, 429, 503] as $status) {
                $this->control('api-' . $mode . '-' . $status);
                $waits = [];
                $client = new \Drupal\flowspeech\Api\FlowSpeechClient('fixture-only',
                    $this->endpoints['api'], 120, [$service, 'transport'], [
                        'max_retries' => 1, 'request_timeout' => 1,
                        'sleep' => static function (float $seconds) use (&$waits): void { $waits[] = $seconds; },
                    ]);
                try {
                    $client->createJob(['mode' => 'single', 'text' => 'Hello', 'voiceName' => 'Kore'], $requestId);
                    self::fail('Interrupted response was accepted.');
                } catch (FlowSpeechException $error) {
                    self::assertSame($status, $error->getCode());
                    self::assertSame($status !== 401, $error->retryable);
                    self::assertSame(30.0, $error->retryAfterSeconds);
                    self::assertSame($requestId, $error->requestId);
                }
                self::assertSame($status === 401 ? [] : [30.0], $waits);
                $records = $this->control()['records'];
                self::assertCount($status === 401 ? 1 : 2, $records);
                if ($status !== 401) self::assertSame($records[0]['body'], $records[1]['body']);
            }
        }
    }

    public function testRealAiProviderOutputAndConstructorContract(): void
    {
        $provider = new FlowSpeechProvider('flowspeech', ['provider' => 'flowspeech'], $this->http,
            $this->config, $this->logger, $this->createMock(CacheBackendInterface::class), $this->keys,
            $this->createMock(ModuleHandlerInterface::class), new EventDispatcher(),
            $this->createMock(FileSystemInterface::class));
        (new ReflectionProperty($provider, 'client'))->setValue($provider, $this->service);
        $provider->configuration = ['voice' => 'Kore'];
        $output = $provider->textToSpeech('Hello', 'flowspeech-tts');
        self::assertSame('audio/wav', $output->getNormalized()[0]->getMimeType());
        self::assertSame('RIFF', substr($output->getNormalized()[0]->getBinary(), 0, 4));
        self::assertSame('success', $output->getRawOutput()['status']);
        self::assertSame(['flowspeech-tts' => 'FlowSpeech Text to Speech'], $provider->getConfiguredModels('text_to_speech'));
    }

    public function testRealGuzzleRedirectRefusal(): void
    {
        foreach (['api-redirect', 'storage-redirect'] as $scenario) {
            $this->control($scenario);
            try { $this->service->textToSpeech('Hello'); self::fail('Redirect was accepted'); }
            catch (FlowSpeechException $error) { self::assertSame('redirect_refused', $error->errorCode); }
            foreach ($this->control()['records'] as $request) self::assertNotSame('/sink', $request['path']);
        }
    }

    public function testRealAiExceptionChainDoesNotReflectRemoteErrorsOrSignedUrls(): void
    {
        $provider = new FlowSpeechProvider('flowspeech', ['provider' => 'flowspeech'], $this->http,
            $this->config, $this->logger, $this->createMock(CacheBackendInterface::class), $this->keys,
            $this->createMock(ModuleHandlerInterface::class), new EventDispatcher(),
            $this->createMock(FileSystemInterface::class));
        (new ReflectionProperty($provider, 'client'))->setValue($provider, $this->service);
        $provider->configuration = ['voice' => 'Kore'];
        $previousMax = ini_set('zend.exception_string_param_max_len', '4096');
        try {
            foreach (['privacy-http-key', 'privacy-http-url', 'privacy-terminal', 'privacy-download'] as $scenario) {
                $this->control($scenario);
                try {
                    $provider->textToSpeech('Hello', 'flowspeech-tts');
                    self::fail('Expected a real framework exception.');
                } catch (\Drupal\ai\Exception\AiBadRequestException $error) {
                    $cause = $error->getPrevious();
                    self::assertInstanceOf(FlowSpeechException::class, $cause);
                    $terminal = $scenario === 'privacy-terminal';
                    self::assertSame($terminal ? 'failed' : 'http_error', $cause->errorCode);
                    self::assertSame($terminal ? 'FlowSpeech job failed.' :
                        'FlowSpeech ' . ($scenario === 'privacy-download' ? 'download' : 'API') . ' returned HTTP 403.', $error->getMessage());
                    self::assertNull($cause->traceId);
                    self::assertNotNull($cause->requestId);
                    if ($terminal || $scenario === 'privacy-download') self::assertNotNull($cause->jobId);
                    foreach ([$error, $cause] as $part) {
                        self::assertStringNotContainsString('fixture-only', (string) $part);
                        self::assertStringNotContainsString('private.example.test', (string) $part);
                        self::assertStringNotContainsString('signature=private-fixture', (string) $part);
                        self::assertStringContainsString('Stack trace:', (string) $part);
                    }
                    self::assertNull($cause->getPrevious());
                }
            }
        } finally {
            ini_set('zend.exception_string_param_max_len', $previousMax);
        }
    }

    public function testRealGuzzleStreamCapTimeoutAndTruncation(): void
    {
        $request = ['url' => $this->endpoints['storage'] . '/recording.wav', 'method' => 'GET',
            'headers' => ['Accept' => 'audio/wav'], 'body' => null, 'timeout' => 1, 'maxBytes' => 64];
        foreach (['oversize' => 'response_too_large', 'truncated' => 'network_error', 'slow' => 'network_error'] as $scenario => $code) {
            $this->control($scenario);
            $started = microtime(true);
            try { $this->service->transport($request); self::fail('Invalid transfer was accepted: ' . $scenario); }
            catch (FlowSpeechException $error) { self::assertSame($code, $error->errorCode); }
            self::assertLessThan(1.8, microtime(true) - $started);
        }
    }

    public function testIsolatedProductionCurlRejectsBadTlsAndOversize(): void
    {
        $service = new FlowSpeechApiService($this->config, $this->http, $this->keys, $this->logger);
        $request = ['method' => 'GET', 'headers' => ['Accept' => 'audio/wav'],
            'body' => null, 'timeout' => 1, 'maxBytes' => 64];
        foreach (['untrusted', 'wrongHost'] as $endpoint) {
            try {
                $service->transport($request + ['url' => $this->endpoints[$endpoint] . '/recording.wav']);
                self::fail('Invalid TLS certificate was accepted.');
            } catch (FlowSpeechException $error) {
                self::assertSame('network_error', $error->errorCode);
                self::assertNull($error->getPrevious());
            }
        }
        $this->control('oversize');
        try {
            $service->transport($request + ['url' => $this->endpoints['storage'] . '/recording.wav']);
            self::fail('The cURL sink accepted an oversized body.');
        } catch (FlowSpeechException $error) {
            self::assertSame('response_too_large', $error->errorCode);
            self::assertFalse($error->retryable);
        }
        $this->control('success');
        $response = $service->transport($request + ['url' => $this->endpoints['storage'] . '/recording.wav']);
        self::assertSame(46, strlen($response['body']));
        foreach ($this->control()['records'] as $record) {
            self::assertArrayNotHasKey('authorization', $record['headers']);
            self::assertArrayNotHasKey('cookie', $record['headers']);
        }
    }
}

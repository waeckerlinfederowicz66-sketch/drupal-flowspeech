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
        $this->service = new FlowSpeechApiService($this->config, $this->http, $this->keys, $this->logger);
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
}

<?php
declare(strict_types=1);

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\flowspeech\FlowSpeechApiService;
use Drupal\flowspeech_ci\FixtureTransport;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/flowspeech_ci/src/FixtureTransport.php';

/** Validates the fixture locally; this is NOT a full-site test. */
final class FixtureTransportTest extends TestCase {
    public function testProductionAdapterUsesFixtureAndIsolatesCredentials(): void {
        $transport = new FixtureTransport();
        $http = new Client(['handler' => HandlerStack::create($transport),
            'headers' => ['Authorization' => 'must-not-leak', 'Cookie' => 'must-not-leak']]);
        $settings = $this->createMock(ImmutableConfig::class);
        $settings->method('get')->willReturnCallback(static fn($key) => $key === 'timeout_seconds' ? 120 : null);
        $config = $this->createMock(ConfigFactoryInterface::class);
        $config->method('get')->willReturn($settings);
        $service = new FlowSpeechApiService($config, $http, $this->createMock(KeyRepositoryInterface::class),
            $this->createMock(LoggerChannelFactoryInterface::class));
        $service->setApiKey(FixtureTransport::KEY);
        self::assertSame('active', $service->getQuota()['subscription']['status']);
        self::assertSame('Kore', $service->getVoices()['voices'][0]['name']);
        $audio = $service->textToSpeech('Hello from the full-site fixture.');
        self::assertSame(FixtureTransport::wav(), $audio['binary']);
        self::assertSame('success', $audio['response']['status']);
        self::assertCount(4, $transport->requests);
    }

    public function testUnexpectedNetworkHasNoFallback(): void {
        $transport = new FixtureTransport();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('External HTTP is disabled');
        $transport(new Request('GET', 'https://blocked.invalid/'), []);
    }

    public function testStorageRejectsAuthorization(): void {
        $transport = new FixtureTransport();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Authorization leaked to storage');
        $transport(new Request('GET', 'https://flowspeech-ci.invalid/recording.wav', ['Authorization' => 'fixture-only']), []);
    }

    public function testRequestsRejectCookies(): void {
        $transport = new FixtureTransport();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cookie leaked');
        $transport(new Request('GET', 'https://flowspeech-ci.invalid/recording.wav', ['Cookie' => 'fixture-only']), []);
    }
}

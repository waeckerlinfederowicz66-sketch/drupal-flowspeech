<?php
declare(strict_types=1);

namespace Drupal\flowspeech_ci;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** In-memory handler: it has no network implementation or fallback. */
final class FixtureTransport {
    public const KEY = 'fixture-only-not-a-live-key';
    public array $requests = [];

    public function __invoke(RequestInterface $request, array $options): PromiseInterface {
        $uri = (string) $request->getUri();
        $method = $request->getMethod();
        $this->requests[] = ['method' => $method, 'url' => $uri,
            'headers' => $request->getHeaders(), 'body' => (string) $request->getBody()];
        if ($request->getHeaderLine('Cookie') !== '') {
            throw new \LogicException('Cookie leaked into the fixture transport.');
        }
        if ($uri === 'https://flowspeech-ci.invalid/recording.wav' && $method === 'GET') {
            if ($request->hasHeader('Authorization')) throw new \LogicException('Authorization leaked to storage.');
            return $this->response(self::wav(), 'audio/wav');
        }
        if ($request->getUri()->getHost() !== 'flowspeech.io' ||
            $request->getUri()->getScheme() !== 'https' ||
            $request->getHeaderLine('Authorization') !== 'Bearer ' . self::KEY) {
            throw new \LogicException('External HTTP is disabled by the full-site fixture.');
        }
        if ($method === 'GET' && $uri === 'https://flowspeech.io/api/v1/quota') {
            return $this->json(['quota' => ['available' => 20], 'subscription' => ['plan' => 'basic', 'status' => 'active'], 'limits' => []]);
        }
        if ($method === 'GET' && $uri === 'https://flowspeech.io/api/v1/voices') {
            return $this->json(['voices' => [['name' => 'Kore']]]);
        }
        if ($method === 'POST' && $uri === 'https://flowspeech.io/api/v1/tts/jobs') {
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if ($body !== ['input' => ['mode' => 'single', 'text' => 'Hello from the full-site fixture.', 'voiceName' => 'Kore']]) {
                throw new \LogicException('Unexpected v1 input from the installed AI provider.');
            }
            $requestId = $request->getHeaderLine('Idempotency-Key');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId)) {
                throw new \LogicException('Missing normalized idempotency UUID.');
            }
            return $this->json(['id' => '12345678-1234-4234-8234-123456789abc',
                'requestId' => $requestId, 'status' => 'success', 'resultDeleted' => false,
                'result' => ['url' => 'https://flowspeech-ci.invalid/recording.wav',
                    'mimeType' => 'audio/wav', 'sampleRate' => 24000, 'channels' => 1, 'bitsPerSample' => 16],
                'usage' => ['chargedCredits' => 0]], 202);
        }
        throw new \LogicException('Unexpected HTTP request; no network fallback exists.');
    }

    public static function wav(): string {
        return 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            . 'data' . pack('V', 2) . "\0\0";
    }

    private function json(array $body, int $status = 200): PromiseInterface {
        return $this->response(json_encode($body, JSON_THROW_ON_ERROR), 'application/json', $status);
    }

    private function response(string $body, string $type, int $status = 200): PromiseInterface {
        return new FulfilledPromise(new Response($status,
            ['Content-Type' => $type, 'Content-Length' => (string) strlen($body)], $body));
    }
}

<?php

declare(strict_types=1);

namespace Drupal\flowspeech;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\flowspeech\Api\FlowSpeechClient;
use Drupal\flowspeech\Api\FlowSpeechException;
use Drupal\flowspeech\Form\FlowSpeechSettingsForm;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Drupal adapter for FlowSpeech public API v1.
 */
final class FlowSpeechApiService {

  private ?string $apiKey = NULL;
  private ?FlowSpeechClient $client = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ?ClientInterface $transportClient = NULL,
  ) {
    $key_id = $this->configFactory->get(FlowSpeechSettingsForm::CONFIG_NAME)->get('api_key');
    if (is_string($key_id) && $key_id !== '') {
      $key = $this->keyRepository->getKey($key_id);
      $value = $key?->getKeyValue();
      $this->apiKey = is_string($value) ? $value : NULL;
    }
  }

  public function setApiKey(string $api_key): void {
    $this->apiKey = $api_key;
    $this->client = NULL;
  }

  public function getQuota(): array {
    return $this->getClient()->getQuota();
  }

  public function getVoices(): array {
    return $this->getClient()->getVoices();
  }

  /**
   * Access submit/status/recovery/cancellation without blocking a web request.
   */
  public function getClient(): FlowSpeechClient {
    if ($this->client === NULL) {
      $timeout = $this->configFactory->get(FlowSpeechSettingsForm::CONFIG_NAME)->get('timeout_seconds') ?? 120;
      $this->client = new FlowSpeechClient($this->apiKey ?? '', 'https://flowspeech.io', (int) $timeout, [$this, 'transport']);
    }
    return $this->client;
  }

  /**
   * Returns WAV bytes and the completed v1 job, including usage.
   */
  public function textToSpeech(string $text, string $voice = 'Kore', ?string $request_id = NULL): array {
    $result = $this->getClient()->generateSpeech($text, [['voiceName' => $voice]], $request_id);
    $binary = base64_decode($result['data']['audioBase64'], TRUE);
    unset($result['data']);
    return ['binary' => $binary, 'mime_type' => 'audio/wav', 'response' => $result];
  }

  /**
   * Uses an isolated cURL client, independent of site handlers/auth/cookies.
   * The optional transportClient constructor argument is for explicit mocks.
   */
  public function transport(array $request): array {
    if (!function_exists('curl_exec')) {
      throw new FlowSpeechException('The cURL extension is required for bounded HTTP requests.', 0, 'missing_curl');
    }
    $client = new Client([
      'handler' => $this->transportClient?->getConfig('handler') ?? HandlerStack::create(new CurlHandler()),
      'proxy' => [],
    ]);
    $started = hrtime(TRUE) / 1e9;
    $buffer = Utils::streamFor();
    $oversized = FALSE;
    $status = 0;
    $headers = [];
    $sink = FnStream::decorate($buffer, [
      'write' => static function (string $chunk) use ($buffer, $request, &$oversized): int {
        if ($buffer->getSize() + strlen($chunk) > $request['maxBytes']) {
          $oversized = TRUE;
          return 0;
        }
        return $buffer->write($chunk);
      },
    ]);
    try {
      $response = $client->request($request['method'], $request['url'], [
        'headers' => $request['headers'],
        'body' => $request['body'],
        'timeout' => $request['timeout'],
        'connect_timeout' => min(10, $request['timeout']),
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'cookies' => FALSE,
        'auth' => NULL,
        'verify' => TRUE,
        // Streaming selects Guzzle's PHP stream handler, whose header reads
        // have per-read timeouts. cURL bounds headers and body together.
        'stream' => FALSE,
        'sink' => $sink,
        'on_headers' => static function (ResponseInterface $response) use (&$status, &$headers): void {
          $status = $response->getStatusCode();
          $headers = $response->getHeaders();
        },
      ]);
      $status = $response->getStatusCode();
      $headers = $response->getHeaders();
      if ($oversized) {
        throw new FlowSpeechException('Response exceeded size limit.', 0, 'response_too_large');
      }
      if (hrtime(TRUE) / 1e9 - $started >= $request['timeout']) {
        throw FlowSpeechClient::transportFailure($status, $headers);
      }
      $response->getBody()->rewind();
      $body = $response->getBody()->read($request['maxBytes'] + 1);
      if (strlen($body) > $request['maxBytes']) {
        throw new FlowSpeechException('Response exceeded size limit.', 0, 'response_too_large');
      }
      $content_length = $response->getHeaderLine('Content-Length');
      if ($content_length !== '' && ctype_digit($content_length) && strlen($body) !== (int) $content_length) {
        throw FlowSpeechClient::transportFailure($status, $headers);
      }
      return ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders(), 'body' => $body];
    }
    catch (TransferException $exception) {
      if ($oversized) {
        throw new FlowSpeechException('Response exceeded size limit.', 0, 'response_too_large');
      }
      // Avoid recording request bodies, credentials or result URLs in logs.
      if ($exception instanceof RequestException && $exception->hasResponse()) {
        $status = $exception->getResponse()->getStatusCode();
        $headers = $exception->getResponse()->getHeaders();
      }
      throw FlowSpeechClient::transportFailure($status, $headers);
    }
    finally {
      $sink->close();
    }
  }

}

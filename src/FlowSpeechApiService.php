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
   * Uses a fresh client so Drupal's default auth/cookies cannot reach storage.
   */
  public function transport(array $request): array {
    $client = new Client(['handler' => $this->httpClient->getConfig('handler')]);
    $started = hrtime(TRUE) / 1e9;
    try {
      $response = $client->request($request['method'], $request['url'], [
        'headers' => $request['headers'],
        'body' => $request['body'],
        'timeout' => $request['timeout'],
        'connect_timeout' => min(10, $request['timeout']),
        'read_timeout' => $request['timeout'],
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'cookies' => FALSE,
        'auth' => NULL,
        'verify' => TRUE,
        'stream' => TRUE,
      ]);
      $stream = $response->getBody()->detach();
      if (!is_resource($stream)) {
        throw new FlowSpeechException('Unable to read response stream.', 0, 'network_error', TRUE);
      }
      $body = '';
      try {
        while (!feof($stream)) {
          $remaining = $request['timeout'] - (hrtime(TRUE) / 1e9 - $started);
          if ($remaining <= 0) {
            throw new FlowSpeechException('Request timed out.', 0, 'network_error', TRUE);
          }
          stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1e6));
          $chunk = fread($stream, min(8192, $request['maxBytes'] + 1 - strlen($body)));
          if ($chunk === FALSE || ($chunk === '' && !feof($stream))) {
            throw new FlowSpeechException('Response stream interrupted.', 0, 'network_error', TRUE);
          }
          $body .= $chunk;
          if (strlen($body) > $request['maxBytes']) {
            throw new FlowSpeechException('Response exceeded size limit.', 0, 'response_too_large');
          }
        }
      }
      finally {
        fclose($stream);
      }
      $content_length = $response->getHeaderLine('Content-Length');
      if ($content_length !== '' && ctype_digit($content_length) && strlen($body) !== (int) $content_length) {
        throw new FlowSpeechException('Response stream was truncated.', 0, 'network_error', TRUE);
      }
      return ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders(), 'body' => $body];
    }
    catch (TransferException $exception) {
      // Avoid recording request bodies, credentials or result URLs in logs.
      throw new FlowSpeechException('FlowSpeech network request failed.', 0, 'network_error', TRUE);
    }
  }

}

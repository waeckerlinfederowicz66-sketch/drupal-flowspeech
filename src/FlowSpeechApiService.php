<?php

declare(strict_types=1);

namespace Drupal\flowspeech;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\flowspeech\Audio\WavEncoder;
use Drupal\flowspeech\Form\FlowSpeechSettingsForm;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client for the public FlowSpeech text-to-speech API.
 */
final class FlowSpeechApiService {

  private const BASE_URL = 'https://flowspeech.io';

  private ?string $apiKey = NULL;

  /**
   * Constructs the FlowSpeech API service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $key_id = $this->configFactory
      ->get(FlowSpeechSettingsForm::CONFIG_NAME)
      ->get('api_key');

    if (is_string($key_id) && $key_id !== '') {
      $key = $this->keyRepository->getKey($key_id);
      if ($key !== NULL) {
        $value = $key->getKeyValue();
        $this->apiKey = is_string($value) ? trim($value) : NULL;
      }
    }
  }

  /**
   * Overrides the configured API key for the current request.
   */
  public function setApiKey(string $api_key): void {
    $this->apiKey = trim($api_key);
  }

  /**
   * Returns the current FlowSpeech quota response.
   *
   * @return array<string, mixed>
   *   The API response payload.
   */
  public function getQuota(): array {
    return $this->request('GET', '/api/ai/text-to-speech/quota');
  }

  /**
   * Generates a playable WAV file from text.
   *
   * @return array{binary: string, mime_type: string, response: array<string, mixed>}
   *   WAV bytes, the MIME type, and the original API response.
   */
  public function textToSpeech(string $text, string $voice = 'Kore'): array {
    $text = trim($text);
    $voice = trim($voice);
    if ($text === '') {
      throw new \InvalidArgumentException('Text cannot be empty.');
    }
    if ($voice === '') {
      throw new \InvalidArgumentException('Voice cannot be empty.');
    }

    $response = $this->request('POST', '/api/ai/text-to-speech', [
      'text' => $text,
      'originalText' => $text,
      'speakers' => [
        ['voiceName' => $voice],
      ],
      'clientSource' => 'drupal',
      'clientVersion' => 'drupal-flowspeech-1.0',
    ]);
    $data = $response['data'] ?? NULL;
    if (!is_array($data) || !isset($data['audioBase64']) || !is_string($data['audioBase64'])) {
      throw new \RuntimeException('FlowSpeech returned no audio data.');
    }

    $audio = base64_decode($data['audioBase64'], TRUE);
    if ($audio === FALSE) {
      throw new \RuntimeException('FlowSpeech returned invalid base64 audio data.');
    }

    $mime_type = strtolower((string) ($data['mimeType'] ?? ''));
    if (str_contains($mime_type, 'wav')) {
      $wav = $audio;
    }
    elseif (str_contains($mime_type, 'l16') || str_contains($mime_type, 'pcm')) {
      $wav = WavEncoder::encode(
        $audio,
        (int) ($data['sampleRate'] ?? 24000),
        (int) ($data['numChannels'] ?? 1),
        (int) ($data['bitsPerSample'] ?? 16),
      );
    }
    else {
      throw new \RuntimeException('FlowSpeech returned an unsupported audio format.');
    }

    return [
      'binary' => $wav,
      'mime_type' => 'audio/wav',
      'response' => $response,
    ];
  }

  /**
   * Sends an authenticated request to FlowSpeech.
   *
   * @param array<string, mixed>|null $payload
   *   Optional JSON request body.
   *
   * @return array<string, mixed>
   *   Decoded API response.
   */
  private function request(string $method, string $path, ?array $payload = NULL): array {
    if ($this->apiKey === NULL || $this->apiKey === '') {
      throw new \RuntimeException('A FlowSpeech API key has not been configured.');
    }

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->apiKey,
        'Accept' => 'application/json',
      ],
      'http_errors' => FALSE,
      'timeout' => 90,
    ];
    if ($payload !== NULL) {
      $options['json'] = $payload;
    }

    try {
      $response = $this->httpClient->request(
        $method,
        self::BASE_URL . $path,
        $options,
      );
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('flowspeech')->error(
        'FlowSpeech API request failed: @message',
        ['@message' => $exception->getMessage()],
      );
      throw new \RuntimeException('The FlowSpeech API request failed.', 0, $exception);
    }

    return $this->decodeResponse($response);
  }

  /**
   * Decodes and validates an API response.
   *
   * @return array<string, mixed>
   *   Decoded response.
   */
  private function decodeResponse(ResponseInterface $response): array {
    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('FlowSpeech returned an invalid JSON response.');
    }

    $status = $response->getStatusCode();
    $code = $decoded['code'] ?? -1;
    if ($status < 200 || $status >= 300 || $code !== 0) {
      $message = isset($decoded['message']) && is_string($decoded['message'])
        ? $decoded['message']
        : 'FlowSpeech rejected the request.';
      throw new \RuntimeException($message, $status);
    }

    return $decoded;
  }

}

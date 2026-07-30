<?php

declare(strict_types=1);

namespace Drupal\flowspeech\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\OperationType\GenericType\AudioFile;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInterface;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowspeech\FlowSpeechApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides FlowSpeech text-to-speech to the Drupal AI module.
 */
#[AiProvider(
  id: 'flowspeech',
  label: new TranslatableMarkup('FlowSpeech'),
)]
final class FlowSpeechProvider extends AiProviderClientBase implements ContainerFactoryPluginInterface, TextToSpeechInterface {

  private const MODEL_ID = 'flowspeech-tts';

  /**
   * FlowSpeech API client.
   */
  protected FlowSpeechApiService $client;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('flowspeech.api');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== NULL && $operation_type !== 'text_to_speech') {
      return [];
    }

    return [self::MODEL_ID => 'FlowSpeech Text to Speech'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }

    return $operation_type === NULL || $operation_type === 'text_to_speech';
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['text_to_speech'];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('flowspeech.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    $path = $this->moduleHandler->getModule('flowspeech')->getPath();
    return Yaml::parseFile($path . '/definitions/api_defaults.yml');
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    $voices = [
      'Puck' => 'Puck - upbeat and playful',
      'Fenrir' => 'Fenrir - energetic',
      'Zephyr' => 'Zephyr - bright and cheerful',
      'Autonoe' => 'Autonoe - bright and welcoming',
      'Laomedeia' => 'Laomedeia - upbeat and lively',
      'Sadachbia' => 'Sadachbia - lively and colorful',
      'Leda' => 'Leda - youthful and casual',
      'Aoede' => 'Aoede - breezy and light',
      'Charon' => 'Charon - informative narration',
      'Rasalgethi' => 'Rasalgethi - educational',
      'Kore' => 'Kore - firm and focused',
      'Orus' => 'Orus - firm and decisive',
      'Alnilam' => 'Alnilam - authoritative',
      'Erinome' => 'Erinome - clear and precise',
      'Iapetus' => 'Iapetus - clear and professional',
      'Schedar' => 'Schedar - even and steady',
      'Pulcherrima' => 'Pulcherrima - forward and direct',
      'Sadaltager' => 'Sadaltager - knowledgeable',
      'Umbriel' => 'Umbriel - easy-going',
      'Callirrhoe' => 'Callirrhoe - soothing',
      'Achird' => 'Achird - friendly and helpful',
      'Zubenelgenubi' => 'Zubenelgenubi - casual',
      'Algieba' => 'Algieba - smooth and mellow',
      'Despina' => 'Despina - reassuring',
      'Enceladus' => 'Enceladus - breathy and magnetic',
      'Algenib' => 'Algenib - gravelly and textured',
      'Sulafat' => 'Sulafat - warm and inviting',
      'Vindemiatrix' => 'Vindemiatrix - gentle and caring',
      'Achernar' => 'Achernar - soft and tender',
      'Gacrux' => 'Gacrux - mature and experienced',
    ];

    $generalConfig['voice'] = [
      'label' => 'Voice',
      'description' => 'Choose the FlowSpeech voice used for synthesis.',
      'type' => 'text',
      'default' => 'Kore',
      'constraints' => ['options' => $voices],
      'required' => TRUE,
    ];

    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    if (is_string($authentication)) {
      $this->client->setApiKey($authentication);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string|TextToSpeechInput $input, string $model_id, array $tags = []): TextToSpeechOutput {
    $text = $input instanceof TextToSpeechInput ? $input->getText() : $input;
    $voice = isset($this->configuration['voice'])
      ? (string) $this->configuration['voice']
      : 'Kore';

    try {
      $result = $this->client->textToSpeech($text, $voice);
    }
    catch (\Throwable $exception) {
      throw new AiBadRequestException($exception->getMessage(), 0, $exception);
    }

    $filename = sprintf(
      'flowspeech-%s.wav',
      substr(hash('sha256', $text . $voice), 0, 16),
    );
    $audio = new AudioFile($result['binary'], $result['mime_type'], $filename);

    return new TextToSpeechOutput([$audio], $result['response'], []);
  }

  /**
   * Returns the raw FlowSpeech API client.
   */
  public function getClient(): FlowSpeechApiService {
    return $this->client;
  }

}

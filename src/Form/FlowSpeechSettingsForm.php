<?php

declare(strict_types=1);

namespace Drupal\flowspeech\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the FlowSpeech provider.
 */
final class FlowSpeechSettingsForm extends ConfigFormBase {

  public const CONFIG_NAME = 'flowspeech.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'flowspeech_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('FlowSpeech API key'),
      '#description' => $this->t('Select a developer API key from FlowSpeech API settings. An active paid API subscription is required; browser login tokens cannot be used.'),
      '#default_value' => $this->config(self::CONFIG_NAME)->get('api_key'),
      '#required' => TRUE,
    ];

    $form['timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Total generation deadline (seconds)'),
      '#default_value' => $this->config(self::CONFIG_NAME)->get('timeout_seconds') ?? 120,
      '#min' => 5,
      '#max' => 7200,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('timeout_seconds', (int) $form_state->getValue('timeout_seconds'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

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
      '#description' => $this->t('Select a key containing a FlowSpeech Bearer API key. Create keys in your FlowSpeech account settings.'),
      '#default_value' => $this->config(self::CONFIG_NAME)->get('api_key'),
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
      ->save();

    parent::submitForm($form, $form_state);
  }

}

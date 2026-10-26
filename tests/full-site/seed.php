<?php
declare(strict_types=1);

use Drupal\flowspeech_ci\FixtureTransport;
use Drupal\key\Entity\Key;

if (!Drupal::hasService('flowspeech_ci.transport')) throw new RuntimeException('Fixture transport is not installed.');
$key = Key::create(['id' => 'flowspeech_ci', 'label' => 'FlowSpeech CI fixture',
    'key_type' => 'authentication', 'key_provider' => 'config', 'key_input' => 'none']);
$key->setKeyValue(FixtureTransport::KEY);
$key->save();
Drupal::configFactory()->getEditable('flowspeech.settings')->set('api_key', $key->id())->save();
$mode = getenv('FLOWSPEECH_SITE_MODE');
if ($mode === 'upgrade' && Drupal::config('flowspeech.settings')->get('timeout_seconds') !== null) {
    throw new RuntimeException('Legacy install unexpectedly has the new timeout configuration.');
}
Drupal::state()->set('flowspeech_ci.seeded', $mode);
echo "PASS: real Key entity and {$mode} configuration persisted.\n";

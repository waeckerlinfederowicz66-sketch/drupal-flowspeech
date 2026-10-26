<?php
declare(strict_types=1);

use Drupal\Core\Form\FormState;
use Drupal\flowspeech\FlowSpeechApiService;
use Drupal\flowspeech\Form\FlowSpeechSettingsForm;
use Drupal\flowspeech\Plugin\AiProvider\FlowSpeechProvider;
use Drupal\flowspeech_ci\FixtureTransport;
use Drupal\user\Entity\User;

$checks = [];
$check = static function (bool $passed, string $label) use (&$checks): void {
    if (!$passed) throw new RuntimeException($label);
    $checks[] = $label;
    echo "PASS: {$label}\n";
};
$mode = getenv('FLOWSPEECH_SITE_MODE');
$phase = getenv('FLOWSPEECH_SITE_PHASE');
$check(in_array($mode, ['fresh', 'upgrade'], true) && in_array($phase, ['initial', 'persisted'], true), 'Known fixture phase');
$check((int) Drupal::database()->query('SELECT 1')->fetchField() === 1, 'Real installed database bootstrapped');
$check(Drupal::state()->get('flowspeech_ci.seeded') === $mode, 'Install/upgrade state persisted');
foreach (['key', 'ai', 'flowspeech', 'flowspeech_ci'] as $module) {
    $check(Drupal::moduleHandler()->moduleExists($module), 'Enabled module: ' . $module);
}
$transport = Drupal::service('flowspeech_ci.transport');
$check($transport instanceof FixtureTransport, 'Container installed no-network transport');
try {
    Drupal::httpClient()->get('https://blocked.invalid/', ['headers' => ['Cookie' => '', 'Authorization' => '']]);
    throw new RuntimeException('External request escaped the fixture.');
} catch (LogicException $error) {
    $check(str_contains($error->getMessage(), 'External HTTP is disabled'), 'Unexpected outbound requests are denied');
}
$transport->requests = [];
$config = Drupal::config('flowspeech.settings');
$check($config->get('api_key') === 'flowspeech_ci', 'Key selection survives cache rebuild/upgrade');
$check(Drupal::service('key.repository')->getKey('flowspeech_ci')->getKeyValue() === FixtureTransport::KEY, 'Real Key repository loads fixture value');
$expected = $phase === 'persisted' ? 180 : ($mode === 'upgrade' ? null : 120);
$check($config->get('timeout_seconds') === $expected, 'Stored timeout matches install/upgrade phase');
$service = Drupal::service('flowspeech.api');
$check($service instanceof FlowSpeechApiService, 'Production service resolves from compiled container');
$deadline = (new ReflectionProperty($service->getClient(), 'timeoutSeconds'))->getValue($service->getClient());
$check($deadline === ($expected ?? 120), 'Client uses saved timeout or legacy default fallback');
$quota = $service->getQuota();
$check($quota['subscription']['status'] === 'active' && !isset($quota['entitlement']), 'Actual quota.subscription retained');
$check($service->getVoices()['voices'][0]['name'] === 'Kore', 'Voices through container transport');

$manager = Drupal::service('ai.provider');
$check($manager->hasDefinition('flowspeech'), 'AI plugin discovered from installed module');
$provider = $manager->createInstance('flowspeech');
$check($provider->getPlugin() instanceof FlowSpeechProvider, 'AI manager constructs provider through container factory');
$check($provider->getClient() === $service, 'AI provider receives actual container service');
$check($provider->isUsable('text_to_speech'), 'AI provider sees configured Key');
$check($provider->getConfiguredModels('text_to_speech') === ['flowspeech-tts' => 'FlowSpeech Text to Speech'], 'Model exposed through actual AI proxy');
$audio = $provider->textToSpeech('Hello from the full-site fixture.', 'flowspeech-tts');
$check($audio->getNormalized()[0]->getBinary() === FixtureTransport::wav(), 'Actual AI proxy returns fixture WAV bytes');
$check($audio->getNormalized()[0]->getMimeType() === 'audio/wav', 'Actual AI output MIME type');
$check($audio->getRawOutput()['status'] === 'success', 'Actual AI raw output retains v1 job');
$check(count($transport->requests) === 4, 'Quota, voices, one job and credential-free download only');

$route = Drupal::service('router.route_provider')->getRouteByName('flowspeech.settings');
$check($route->getPath() === '/admin/config/ai/providers/flowspeech', 'Settings route discovered');
$check($route->getRequirement('_permission') === 'administer site configuration', 'Settings permission retained');
$switcher = Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
try {
    $form = Drupal::formBuilder()->getForm(FlowSpeechSettingsForm::class);
    $check(isset($form['api_key']['#options']['flowspeech_ci']), 'Real Key select lists persisted entity');
    $check($form['timeout_seconds']['#default_value'] === ($expected ?? 120), 'Real Form API builds timeout default');
    $html = (string) Drupal::service('renderer')->renderInIsolation($form);
    $check(str_contains($html, 'name="api_key"') && str_contains($html, 'name="timeout_seconds"'), 'Settings form renders real HTML controls');
    if ($phase === 'initial') {
        $state = (new FormState())->setValues(['api_key' => 'flowspeech_ci', 'timeout_seconds' => 180])->disableRedirect();
        Drupal::formBuilder()->submitForm(FlowSpeechSettingsForm::class, $state);
        $check(!$state->hasAnyErrors(), 'Real Form API validation/submission succeeds');
        $check(Drupal::config('flowspeech.settings')->get('timeout_seconds') === 180, 'Settings submission saves deadline to database');
    }
} finally {
    $switcher->switchBack();
}
$typed = Drupal::service('config.typed')->createFromNameAndData('flowspeech.settings', Drupal::config('flowspeech.settings')->getRawData());
$check($typed->get('timeout_seconds')->getDataDefinition()->getDataType() === 'integer', 'Installed configuration schema declares integer timeout');
$result = ['mode' => $mode, 'phase' => $phase, 'drupal' => Drupal::VERSION,
    'assertions' => count($checks), 'checks' => $checks, 'network' => 'in-memory only', 'status' => 'passed'];
$log = getenv('FLOWSPEECH_SITE_LOG');
if (!$log || !is_dir($log)) throw new RuntimeException('Missing isolated log directory.');
file_put_contents($log . '/smoke-' . $phase . '.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

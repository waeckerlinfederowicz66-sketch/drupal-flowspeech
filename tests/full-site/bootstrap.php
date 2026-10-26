<?php
declare(strict_types=1);

use Drupal\user\Entity\User;

if (!class_exists('Drupal') || !Drupal::hasContainer()) throw new RuntimeException('Drupal did not bootstrap.');
$root = realpath(Drupal::getContainer()->getParameter('app.root'));
if ($root !== realpath(getenv('FLOWSPEECH_SITE_ROOT') ?: '')) throw new RuntimeException('Wrong Drupal site root.');
$checks = [];
$check = static function (bool $passed, string $label) use (&$checks): void {
    if (!$passed) throw new RuntimeException($label);
    $checks[] = $label;
};
$database = Drupal::database();
$check((int) $database->query('SELECT 1')->fetchField() === 1, 'Real database query');
$check($database->schema()->tableExists('config') && $database->schema()->tableExists('key_value') &&
    $database->schema()->tableExists('users_field_data'), 'Installed Drupal schema');
$check(Drupal::config('core.extension')->get('profile') === 'minimal' &&
    Drupal::moduleHandler()->moduleExists('system') && Drupal::moduleHandler()->moduleExists('user'), 'Minimal install profile');
$admin = User::load(1);
$check($admin !== null && $admin->isActive() && $admin->getAccountName() === 'ciadmin', 'Installed administrator');
$nonce = getenv('FLOWSPEECH_SITE_NONCE');
$commit = getenv('FLOWSPEECH_SITE_COMMIT');
$log = getenv('FLOWSPEECH_SITE_LOG');
if (!$nonce || !$commit || !$log || !is_dir($log)) throw new RuntimeException('Missing run evidence context.');
$evidence = ['mode' => getenv('FLOWSPEECH_SITE_MODE'), 'phase' => 'post-install',
    'nonce' => $nonce, 'commit' => $commit, 'root' => $root, 'drupal' => Drupal::VERSION,
    'status' => 'passed', 'assertions' => count($checks), 'checks' => $checks];
if (file_put_contents($log . '/bootstrap.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    throw new RuntimeException('Cannot persist bootstrap evidence.');
}
echo "PASS: installed database, profile and administrator verified through a real Drupal bootstrap.\n";

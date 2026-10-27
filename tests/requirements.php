<?php
declare(strict_types=1);

if (!function_exists('t')) {
    function t($message) { return $message; }
}
$source = getenv('FLOWSPEECH_TEST_ROOT') ?: dirname(__DIR__);
require $source . '/flowspeech.install';
$checks = 0;
foreach (['install', 'update', 'runtime'] as $phase) {
    $requirement = flowspeech_requirements($phase)['flowspeech_curl'];
    $severity = $requirement['severity'];
    $severity = $severity instanceof BackedEnum ? $severity->value : $severity;
    if ($severity !== (function_exists('curl_exec') ? 0 : 2)) throw new RuntimeException('Incorrect cURL requirement severity.');
    $checks++;
    $description = $requirement['description'];
    if ($description instanceof \Drupal\Core\StringTranslation\TranslatableMarkup) $description = $description->getUntranslatedString();
    if (!str_contains($description, 'curl_exec')) throw new RuntimeException('Missing installation instructions.');
    $checks++;
}
echo "PASS: $checks Drupal installation/status requirement assertions; curl_exec=" . (function_exists('curl_exec') ? 'enabled' : 'disabled') . ".\n";

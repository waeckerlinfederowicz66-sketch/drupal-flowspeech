<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$vendor = is_file($root . '/.tools/vendor/autoload.php') ? $root . '/.tools/vendor' : $root . '/vendor';
$loader = require $vendor . '/autoload.php';
$loader->addPsr4('Drupal\\ai\\', $vendor . '/drupal/ai/src');
$loader->addPsr4('Drupal\\key\\', $vendor . '/drupal/key/src');
$loader->setPsr4('Drupal\\flowspeech\\', (getenv('FLOWSPEECH_TEST_ROOT') ?: $root) . '/src');

<?php
declare(strict_types=1);

$extensions = ['gd', 'pdo_sqlite', 'sqlite3', 'curl', 'openssl', 'mbstring', 'dom', 'xml'];
$missing = array_values(array_filter($extensions, static fn($name) => !extension_loaded($name)));
$sqlite = extension_loaded('pdo_sqlite')
    ? (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn() : null;
$result = ['php' => PHP_VERSION, 'sqlite' => $sqlite, 'missing_extensions' => $missing];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (version_compare(PHP_VERSION, '8.3.0', '<') || $missing || !$sqlite || version_compare($sqlite, '3.45', '<')) {
    fwrite(STDERR, "Full Drupal install requires PHP >=8.3, listed extensions and PHP SQLite >=3.45. No platform bypass is permitted.\n");
    exit(1);
}

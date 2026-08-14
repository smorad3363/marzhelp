<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$configPath = $argv[1] ?? '';
if ($configPath === '' || !is_file($configPath)) {
    fwrite(STDERR, "Configuration file was not found.\n");
    exit(1);
}

$contents = file_get_contents($configPath);
if ($contents === false) {
    fwrite(STDERR, "Unable to read the configuration file.\n");
    exit(1);
}

$contents = preg_replace_callback(
    '/^\s*\$(botDbPass|vpnDbPass)\s*=.*?;\s*$/m',
    static function (array $matches): string {
        return '$' . $matches[1] . " = '';";
    },
    $contents
);

if ($contents === null || file_put_contents($configPath, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to scrub legacy database passwords.\n");
    exit(1);
}


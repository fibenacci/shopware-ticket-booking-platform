<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    '/var/www/html/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadCandidate) {
    if (is_file($autoloadCandidate)) {
        $classLoader = require $autoloadCandidate;
        break;
    }
}

if (isset($classLoader) && class_exists(Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::class)) {
    Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::prepare($classLoader);
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'FibBookingSystem\\Tests\\' => __DIR__ . '/',
        'FibBookingSystem\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

<?php

declare(strict_types=1);

function getAvailableLocales(): array
{
    return [
        'de' => 'Deutsch',
        'en' => 'English',
    ];
}

function isLocaleAvailable(string $locale): bool
{
    return array_key_exists($locale, getAvailableLocales());
}

function setSessionLocale(string $locale): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (isLocaleAvailable($locale)) {
        $_SESSION['locale'] = $locale;
    }
}

function getLocale(): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['locale']) && isLocaleAvailable((string) $_SESSION['locale'])) {
            return (string) $_SESSION['locale'];
        }
        if (!empty($_SESSION['setup_locale']) && isLocaleAvailable((string) $_SESSION['setup_locale'])) {
            return (string) $_SESSION['setup_locale'];
        }
    }

    $config = [];
    $path = __DIR__ . '/config.php';
    if (is_file($path)) {
        $config = require $path;
    }

    if (!empty($config['locale']) && isLocaleAvailable((string) $config['locale'])) {
        return (string) $config['locale'];
    }

    try {
        require_once __DIR__ . '/settings.php';
        $dbLocale = getAppSetting('locale');
        if (is_string($dbLocale) && isLocaleAvailable($dbLocale)) {
            return $dbLocale;
        }
    } catch (Throwable $e) {
        // ignore if database settings are not available yet
    }

    return 'de';
}

function getLocaleLabel(string $locale): string
{
    $locales = getAvailableLocales();
    return $locales[$locale] ?? $locale;
}

function loadTranslations(string $locale): array
{
    $file = __DIR__ . '/../lang/' . $locale . '.php';
    if (!is_file($file)) {
        return [];
    }

    return require $file;
}

function translate(string $key, array $vars = []): string
{
    static $cache = [];
    $locale = getLocale();

    if (!isset($cache[$locale])) {
        $cache[$locale] = loadTranslations($locale);
    }

    $translations = $cache[$locale];
    $text = $translations[$key] ?? $key;

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}

function t(string $key, array $vars = []): string
{
    return translate($key, $vars);
}

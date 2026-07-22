<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function getAppSetting(string $key, ?string $fallback = null): ?string
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : $value;
}

function setAppSetting(string $key, string $value): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$key, $value]);
}

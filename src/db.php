<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function getPDO(bool $withDatabase = true): PDO
{
    static $pdo;
    if ($withDatabase && $pdo) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $dsn = sprintf('mysql:host=%s;charset=%s', $config['db']['host'], $config['db']['charset']);
    if ($withDatabase) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['dbname'], $config['db']['charset']);
    }

    $connection = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($withDatabase) {
        $pdo = $connection;
    }

    return $connection;
}

function ensureDatabaseExists(): void
{
    $config = require __DIR__ . '/config.php';
    $pdo = getPDO(false);
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
        $config['db']['dbname'],
        $config['db']['charset'],
        'utf8mb4_unicode_ci'
    ));
}

function canConnectToServer(): bool
{
    try {
        getPDO(false);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

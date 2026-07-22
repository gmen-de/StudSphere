<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function getCurrentUser(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireLogin(): void
{
    if (getCurrentUser() === null) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

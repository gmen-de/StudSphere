<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function installDatabase(): void
{
    ensureDatabaseExists();
    $pdo = getPDO();

    $queries = [
        'CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS sets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rebrickable_set_num VARCHAR(50) NOT NULL UNIQUE,
            rebrickable_set_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            year INT DEFAULT NULL,
            theme VARCHAR(255) DEFAULT NULL,
            num_parts INT DEFAULT NULL,
            image_url VARCHAR(512) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rebrickable_part_id INT NOT NULL UNIQUE,
            part_num VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            part_category VARCHAR(255) DEFAULT NULL,
            part_url VARCHAR(512) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS inventories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            part_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY user_part_unique (user_id, part_id),
            CONSTRAINT fk_inventory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_inventory_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS set_parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            set_id INT NOT NULL,
            part_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            CONSTRAINT fk_setparts_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE,
            CONSTRAINT fk_setparts_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
            UNIQUE KEY set_part_unique (set_id, part_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS app_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
}

function isInstalled(): bool
{
    try {
        $pdo = getPDO();
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

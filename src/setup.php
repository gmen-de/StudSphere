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
            local_image_path VARCHAR(512) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

        'CREATE TABLE IF NOT EXISTS parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rebrickable_part_id INT DEFAULT NULL,
            part_num VARCHAR(100) NOT NULL UNIQUE,
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
        'CREATE TABLE IF NOT EXISTS themes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            theme_id INT NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            parent_theme_id INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS colors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            color_id INT NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            rgb VARCHAR(20) DEFAULT NULL,
            is_trans TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS part_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            part_cat_id INT NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            parent_id INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS part_relationships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_part_id INT NOT NULL,
            child_part_id INT NOT NULL,
            relationship_type VARCHAR(10) DEFAULT NULL,
            CONSTRAINT fk_partrel_parent FOREIGN KEY (parent_part_id) REFERENCES parts(id) ON DELETE CASCADE,
            CONSTRAINT fk_partrel_child FOREIGN KEY (child_part_id) REFERENCES parts(id) ON DELETE CASCADE,
            UNIQUE KEY relation_unique (parent_part_id, child_part_id, relationship_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS elements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            element_id VARCHAR(10) NOT NULL UNIQUE,
            part_id INT DEFAULT NULL,
            color_id INT DEFAULT NULL,
            design_id INT DEFAULT NULL,
            is_spare TINYINT(1) DEFAULT 0,
            has_feature_image TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS minifigs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fig_num VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) DEFAULT NULL,
            num_parts INT DEFAULT NULL,
            image_url VARCHAR(512) DEFAULT NULL,
            local_image_path VARCHAR(512) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS rebrickable_inventories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            version INT DEFAULT NULL,
            name VARCHAR(255) DEFAULT NULL,
            set_num VARCHAR(100) DEFAULT NULL,
            year INT DEFAULT NULL,
            theme VARCHAR(255) DEFAULT NULL,
            num_parts INT DEFAULT NULL,
            UNIQUE KEY inventory_version_unique (inventory_id, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS inventory_parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            part_id INT DEFAULT NULL,
            color_id INT DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 0,
            is_spare TINYINT(1) DEFAULT 0,
            img_url VARCHAR(512) DEFAULT NULL,
            local_image_path VARCHAR(512) DEFAULT NULL,
            UNIQUE KEY inventory_part_unique (inventory_id, part_id, color_id, is_spare)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS inventory_sets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            set_num VARCHAR(100) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 0,
            UNIQUE KEY inventory_set_unique (inventory_id, set_num)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS inventory_minifigs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            minifig_id INT DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 0,
            UNIQUE KEY inventory_minifig_unique (inventory_id, minifig_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS storage_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            location_type VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_location_parent FOREIGN KEY (parent_id) REFERENCES storage_locations(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS storage_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            location_id INT NOT NULL,
            part_id INT NOT NULL,
            color_id INT DEFAULT NULL,
            condition_type ENUM(\'new\',\'used\') NOT NULL DEFAULT \'used\',
            quantity INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_storageitem_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT,
            CONSTRAINT fk_storageitem_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_storageitem_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE RESTRICT,
            UNIQUE KEY storage_item_unique (location_id, part_id, color_id, condition_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS storage_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            user_id INT DEFAULT NULL,
            location_id INT DEFAULT NULL,
            part_id INT DEFAULT NULL,
            color_id INT DEFAULT NULL,
            condition_type ENUM(\'new\',\'used\') DEFAULT NULL,
            movement_type ENUM(\'in\',\'out\',\'move_out\',\'move_in\',\'correction\') NOT NULL,
            quantity_change INT NOT NULL,
            resulting_quantity INT DEFAULT NULL,
            note VARCHAR(500) DEFAULT NULL,
            related_movement_id INT DEFAULT NULL,
            CONSTRAINT fk_movement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_movement_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
            CONSTRAINT fk_movement_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE SET NULL,
            CONSTRAINT fk_movement_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE SET NULL,
            CONSTRAINT fk_movement_related FOREIGN KEY (related_movement_id) REFERENCES storage_movements(id) ON DELETE SET NULL
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

    // A fresh install already has the current shape (CREATE TABLE above), so it
    // starts at the latest schema version and never replays old migrations —
    // those only apply to upgrading a pre-existing install.
    require_once __DIR__ . '/migrations.php';
    setInstalledSchemaVersion(CURRENT_SCHEMA_VERSION);
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

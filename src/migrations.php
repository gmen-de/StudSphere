<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Ordered schema migrations, keyed by the schema version they upgrade TO (i.e.
 * key 2 takes the DB from version 1 to version 2). installDatabase() always
 * creates the current shape directly via CREATE TABLE, so a fresh install is
 * stamped at CURRENT_SCHEMA_VERSION immediately and never replays this list —
 * migrations only run for a pre-existing install whose stored schema_version
 * is behind. When changing the schema going forward: update installDatabase()
 * for fresh installs AND add a migration entry here for existing ones, then
 * bump CURRENT_SCHEMA_VERSION.
 */
function getSchemaMigrations(): array
{
    return [
        2 => function (PDO $pdo): void {
            addIndexIfMissing($pdo, 'parts', 'idx_parts_category', 'part_category');
            addIndexIfMissing($pdo, 'inventory_parts', 'idx_inventory_parts_part_id', 'part_id');
        },
        3 => function (PDO $pdo): void {
            addIndexIfMissing($pdo, 'part_relationships', 'idx_partrel_child_type', 'child_part_id, relationship_type');
            addIndexIfMissing($pdo, 'inventory_parts', 'idx_inventory_parts_color_id', 'color_id');
        },
        4 => function (PDO $pdo): void {
            // Composite index lets the color facet's COUNT(DISTINCT part_id)
            // GROUP BY color_id use a loose index scan instead of a
            // temporary table + filesort (measured ~69s -> sub-second on a
            // ~950k-row inventory_parts table). Its leading column (color_id)
            // already covers everything the old single-column index did, so
            // that one is dropped rather than kept alongside it.
            dropIndexIfExists($pdo, 'inventory_parts', 'idx_inventory_parts_color_id');
            addIndexIfMissing($pdo, 'inventory_parts', 'idx_inventory_parts_color_part', 'color_id, part_id');
        },
        5 => function (PDO $pdo): void {
            // A category tile's thumbnail lookup filters inventory_parts by
            // "has a downloaded image" — without an index that column can
            // only be checked via a full-table scan-and-join, which measured
            // ~126s even after batching the per-category N+1 loop into one
            // query. A 1-char prefix is enough to distinguish NULL/empty from
            // any real path, and keeps the index small despite the column
            // being a VARCHAR(512).
            addIndexIfMissing($pdo, 'inventory_parts', 'idx_inventory_parts_has_image', 'local_image_path(1)');
        },
        6 => function (PDO $pdo): void {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS part_translations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    part_id INT NOT NULL,
                    locale VARCHAR(10) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    user_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY part_translation_unique (part_id, locale),
                    CONSTRAINT fk_parttranslation_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_parttranslation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        7 => function (PDO $pdo): void {
            // The set-detail page looks up a set's current inventory via
            // WHERE set_num = ? — without an index that's a full scan of
            // rebrickable_inventories on every page load (the table's only
            // existing index is the composite (inventory_id, version) key,
            // which set_num isn't a leftmost prefix of).
            addIndexIfMissing($pdo, 'rebrickable_inventories', 'idx_rebrickable_inventories_set_num', 'set_num');
        },
        8 => function (PDO $pdo): void {
            // Rebrickable's sets.csv only ships a set's introduction year,
            // never a retirement/end-of-life date — that data isn't
            // available from any import, so it's a manually-editable field
            // instead (same reasoning as part_translations: crowdsourced,
            // not synced).
            addColumnIfMissing($pdo, 'sets', 'year_retired', 'INT DEFAULT NULL');
        },
        9 => function (PDO $pdo): void {
            // Color-correct part images, fetched on demand (one CDN request
            // per part+color, not a bulk sync — most parts/colors are
            // already covered well enough by the bulk-downloaded
            // inventory_parts image). color_id here is Rebrickable's own
            // numbering, same as inventory_parts.color_id — not colors.id.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS part_color_images (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    part_id INT NOT NULL,
                    color_id INT NOT NULL,
                    local_image_path VARCHAR(512) DEFAULT NULL,
                    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY part_color_image_unique (part_id, color_id),
                    CONSTRAINT fk_partcolorimage_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            // Rebrickable's part_num often matches the LDraw part library's
            // own numbering, but not always (e.g. part 2436 is LDraw
            // "2436a") — confirmed via the API's external_ids.LDraw field.
            // Resolved lazily, once per part, and cached here: '' means
            // "resolved, no LDraw mapping exists" (e.g. printed/decorated
            // parts), NULL means "not looked up yet".
            addColumnIfMissing($pdo, 'parts', 'ldraw_id', 'VARCHAR(50) DEFAULT NULL');
        },
        10 => function (PDO $pdo): void {
            // "How many sets does this part+color appear in" (for the
            // inventory tab's exclusive/rare/normal grouping) is expensive
            // to compute live — COUNT(DISTINCT set_num) over inventory_parts
            // measured 3.6s for one 538-part set's worth of pairs. Cached
            // lazily per part+color instead (see sets.php's
            // getPartSetCounts()) and cleared on a full Rebrickable resync
            // (src/download.php), since new sets can change the counts.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS part_set_counts (
                    part_id INT NOT NULL,
                    color_id INT NOT NULL,
                    set_count INT NOT NULL,
                    computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (part_id, color_id),
                    CONSTRAINT fk_partsetcount_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        11 => function (PDO $pdo): void {
            // importThemesCsv() used to store a top-level theme's blank
            // parent_id CSV cell (an empty string, not PHP null) as 0
            // instead of NULL — theme_id 0 was never a real theme, so every
            // top-level theme silently looked like a child of a
            // nonexistent parent, and the sets_search/minifigs_search theme
            // browser (which needs "parent_theme_id IS NULL" to find
            // top-level themes) showed nothing hierarchical at all, just
            // one flat list of all ~500 themes. The import is fixed
            // separately (src/import.php) for future syncs; this repairs
            // whatever's already stored.
            $pdo->exec('UPDATE themes SET parent_theme_id = NULL WHERE parent_theme_id = 0');
        },
        12 => function (PDO $pdo): void {
            // Lets users upload building-instruction PDFs per set (multiple
            // per set — e.g. one per booklet, or alternate-model instructions).
            // Files live under public/instructions/{set_id}/ with a random
            // on-disk filename (see src/instructions.php) — stored_path is
            // the root-relative path used both as the DB record and as the
            // <a href> target, matching the public/images/ convention.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS set_instructions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    set_id INT NOT NULL,
                    label VARCHAR(255) DEFAULT NULL,
                    original_filename VARCHAR(255) NOT NULL,
                    stored_path VARCHAR(512) NOT NULL,
                    file_size INT NOT NULL,
                    uploaded_by INT DEFAULT NULL,
                    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        CONSTRAINT fk_setinstructions_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE,
                    CONSTRAINT fk_setinstructions_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        13 => function (PDO $pdo): void {
            // Owned set instances ("my collection") — one row per physical
            // copy. Each instance gets its own storage_locations node
            // (location_type 'owned_set', an internal marker never exposed
            // to or chosen by a user) so its parts show up as real
            // storage_items stock through the exact same location/part
            // lookup machinery already used for loose parts, instead of a
            // parallel "virtual location" concept. No separate
            // missing-parts table: the set's nominal quantities come from
            // its Rebrickable inventory (queried fresh), the actually-owned
            // quantities are just storage_items.quantity at the instance's
            // location — "missing" is nominal minus that, computed, never
            // stored twice.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owned_sets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    set_id INT NOT NULL,
                    location_id INT NOT NULL,
                    condition_type ENUM(\'new\',\'used\') NOT NULL DEFAULT \'used\',
                    has_instructions TINYINT(1) NOT NULL DEFAULT 0,
                    has_box TINYINT(1) NOT NULL DEFAULT 0,
                    box_complete TINYINT(1) NOT NULL DEFAULT 0,
                    notes TEXT DEFAULT NULL,
                    added_by INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_ownedset_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ownedset_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ownedset_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owned_set_photos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owned_set_id INT NOT NULL,
                    caption VARCHAR(255) DEFAULT NULL,
                    original_filename VARCHAR(255) NOT NULL,
                    stored_path VARCHAR(512) NOT NULL,
                    file_size INT NOT NULL,
                    uploaded_by INT DEFAULT NULL,
                    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_ownedsetphoto_ownedset FOREIGN KEY (owned_set_id) REFERENCES owned_sets(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ownedsetphoto_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        14 => function (PDO $pdo): void {
            // Per-detail notes for the "add to collection" wizard's step 2
            // (Bauanleitung/OVP/OVP vollständig each get their own note,
            // separate from the general "notes" field added in migration 13).
            addColumnIfMissing($pdo, 'owned_sets', 'instructions_notes', 'TEXT DEFAULT NULL');
            addColumnIfMissing($pdo, 'owned_sets', 'box_notes', 'TEXT DEFAULT NULL');
            addColumnIfMissing($pdo, 'owned_sets', 'box_complete_notes', 'TEXT DEFAULT NULL');
        },
        15 => function (PDO $pdo): void {
            // Damaged-but-present tracking for set inventory: a subset of
            // `quantity` (still "owned", not "missing") — see setOwnedSetPartInventory()
            // in src/owned_sets.php.
            addColumnIfMissing($pdo, 'storage_items', 'damaged_quantity', 'INT NOT NULL DEFAULT 0');
        },
        16 => function (PDO $pdo): void {
            // Spare parts are tracked completely separately from the regular
            // quantity (a set's spare and regular pool can be the exact same
            // part+color) and deliberately never feed into completeness —
            // see setStorageItemSpareQuantity() in src/storage.php.
            addColumnIfMissing($pdo, 'storage_items', 'spare_quantity', 'INT NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'storage_items', 'spare_damaged_quantity', 'INT NOT NULL DEFAULT 0');

            // Which Rebrickable inventory revision this instance actually is
            // (a set can have several — see getSetInventoryVersions() in
            // src/sets.php). Existing rows predate version selection, so
            // backfill them to whichever revision was implicitly used at the
            // time (always the newest, via getSetInventoryId()) — same
            // resolved value, just now stored instead of re-derived.
            addColumnIfMissing($pdo, 'owned_sets', 'inventory_id', 'INT DEFAULT NULL');
            $pdo->exec(
                "UPDATE owned_sets os
                 INNER JOIN sets s ON s.id = os.set_id
                 INNER JOIN (
                     SELECT set_num, MAX(version) AS max_version
                     FROM rebrickable_inventories
                     GROUP BY set_num
                 ) latest ON latest.set_num = s.rebrickable_set_num
                 INNER JOIN rebrickable_inventories ri
                     ON ri.set_num = latest.set_num AND ri.version = latest.max_version
                 SET os.inventory_id = ri.inventory_id
                 WHERE os.inventory_id IS NULL"
            );

            // Minifigs aren't a part+color combination, so they don't fit
            // storage_items — a dedicated table instead, same shape as
            // owned_set_photos.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owned_set_minifigs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owned_set_id INT NOT NULL,
                    minifig_id INT NOT NULL,
                    quantity INT NOT NULL DEFAULT 0,
                    damaged_quantity INT NOT NULL DEFAULT 0,
                    UNIQUE KEY owned_set_minifig_unique (owned_set_id, minifig_id),
                    CONSTRAINT fk_ownedsetminifig_ownedset FOREIGN KEY (owned_set_id) REFERENCES owned_sets(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ownedsetminifig_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        17 => function (PDO $pdo): void {
            // Public self-registration is gone (see index.php) — creating a
            // user is now an admin-only action in Settings. Every existing
            // account predates that distinction and already had full access,
            // so all of them become admins here; only accounts created after
            // this point can be plain (non-admin) members.
            addColumnIfMissing($pdo, 'users', 'is_admin', 'TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE users SET is_admin = 1');
        },
        18 => function (PDO $pdo): void {
            // A minifig's own constituent parts (head/torso/legs/accessories),
            // per owned instance — same reasoning as owned_set_minifigs
            // (minifigs aren't a part+color combination, don't fit
            // storage_items), just one level deeper. Rebrickable already
            // ships each minifig's own part breakdown as its own "inventory"
            // (rebrickable_inventories.set_num = a fig_num like "fig-000001",
            // with matching inventory_parts rows) — the existing generic CSV
            // import already pulled this in without any app code using it
            // until now, so no import change is needed here, only this table
            // to record what's actually present/damaged.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owned_set_minifig_parts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owned_set_id INT NOT NULL,
                    minifig_id INT NOT NULL,
                    part_id INT NOT NULL,
                    color_id INT DEFAULT NULL,
                    quantity INT NOT NULL DEFAULT 0,
                    damaged_quantity INT NOT NULL DEFAULT 0,
                    UNIQUE KEY owned_set_minifig_part_unique (owned_set_id, minifig_id, part_id, color_id),
                    CONSTRAINT fk_ownedsetminifigpart_ownedset FOREIGN KEY (owned_set_id) REFERENCES owned_sets(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ownedsetminifigpart_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_ownedsetminifigpart_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_ownedsetminifigpart_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        19 => function (PDO $pdo): void {
            // A 4th wizard step-2 detail, same shape as instructions_notes/
            // box_notes/box_complete_notes (migration 14) — whether the
            // sticker sheet has actually been applied to the model. Unlike
            // the other three, this is NOT trivially true for a sealed/
            // "new" set (stickers can't be applied before the set is even
            // built) — see the wizard's detailPairs array in
            // renderAddOwnedSetWizardModal(), which forces it to false
            // (not true) when condition=new.
            addColumnIfMissing($pdo, 'owned_sets', 'stickers_applied', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'owned_sets', 'stickers_notes', 'TEXT DEFAULT NULL');
        },
        20 => function (PDO $pdo): void {
            // getSetThemeTree()/getOwnedSetThemeTree() (src/sets.php) LEFT
            // JOIN sets ON sets.theme = themes.theme_id for every one of the
            // ~500 theme rows — with no index on sets.theme that's a full
            // table scan per theme row (measured ~1.5s per call on ~27k
            // sets), and getOwnedSetThemeTree() alone runs on every single
            // page via getNavMenu()'s "Meine Sets" dropdown.
            addIndexIfMissing($pdo, 'sets', 'idx_sets_theme', 'theme');
        },
        21 => function (PDO $pdo): void {
            // Rebrickable's own colors.csv (the bulk-import file
            // downloadAndImportRebrickableData() already uses) has no
            // BrickLink/BrickOwl/LDraw mapping — only the REST API's
            // lego/colors/ endpoint does, via each color's external_ids.
            // See syncExternalColorIds() in src/rebrickable.php, called
            // once at the end of every Rebrickable data update.
            // ldraw_color_id additionally replaces matchLdrawColorCode()'s
            // RGB-nearest-neighbor guess (src/ldraw.php) with Rebrickable's
            // authoritative mapping wherever one exists.
            addColumnIfMissing($pdo, 'colors', 'bricklink_color_id', 'INT DEFAULT NULL');
            addColumnIfMissing($pdo, 'colors', 'brickowl_color_id', 'INT DEFAULT NULL');
            addColumnIfMissing($pdo, 'colors', 'ldraw_color_id', 'INT DEFAULT NULL');
        },
        22 => function (PDO $pdo): void {
            // Per-instance toggles for the "Beschädigt/Fehlend" tab — spares
            // and stickers are excluded from that list by default (most
            // sets don't track either closely), only shown once the owner
            // explicitly opts in for this one owned_sets row. See
            // renderOwnedSetDamagedMissingSection() in src/owned_sets.php.
            addColumnIfMissing($pdo, 'owned_sets', 'damaged_missing_show_spares', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'owned_sets', 'damaged_missing_show_stickers', 'TINYINT(1) NOT NULL DEFAULT 0');
        },
        23 => function (PDO $pdo): void {
            // Records a sale at the moment an owned-set instance is removed
            // via the new "Verkaufen" action (sellOwnedSet() in
            // src/owned_sets.php) — the owned_sets row itself is gone right
            // after (same removeOwnedSet() the plain "Löschen" action uses),
            // so this is the only place that history survives. Denormalized
            // rebrickable_set_num/name so a future sales-history view
            // doesn't need the set to still exist in any particular shape;
            // set_id is kept too (ON DELETE SET NULL — the catalog itself is
            // never deleted in practice, but this must never be the reason a
            // sale record disappears if that ever changes).
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owned_set_sales (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    set_id INT DEFAULT NULL,
                    rebrickable_set_num VARCHAR(50) NOT NULL,
                    set_name VARCHAR(255) NOT NULL,
                    price DECIMAL(10,2) DEFAULT NULL,
                    sold_at DATE DEFAULT NULL,
                    platform VARCHAR(255) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    sold_by INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_ownedsetsale_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE SET NULL,
                    CONSTRAINT fk_ownedsetsale_user FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        24 => function (PDO $pdo): void {
            // Rebrickable has no BrickLink minifig-ID mapping at all (confirmed
            // admitted omission — see the Rebrickable forum thread linked in
            // fetchBricklinkMinifigId()'s doc comment in src/rebrickable.php).
            // Looked up (best-effort, cached forever once found — that's the
            // whole point of a dedicated column instead of a live call every
            // time) via a third-party site, or entered manually as a fallback.
            addColumnIfMissing($pdo, 'minifigs', 'bricklink_id', 'VARCHAR(20) DEFAULT NULL');
        },
        25 => function (PDO $pdo): void {
            // Unlike minifigs, Rebrickable's own API does map parts -> BrickLink IDs
            // (external_ids.BrickLink) — see applyBricklinkPartIdBatch() in
            // src/rebrickable.php, driven one batch per tick from the BrickLink
            // XML export's sync-progress modal, per the API's own "Performance
            // Tips" on batching part_nums.
            addColumnIfMissing($pdo, 'parts', 'bricklink_part_id', 'VARCHAR(20) DEFAULT NULL');
        },
        26 => function (PDO $pdo): void {
            // Nullable even though the app now requires it on every new-user
            // form (setup.php steps 3/4, action=admin_create_user) — existing
            // users on an upgraded install have no value yet and there's no
            // sensible default to backfill with.
            addColumnIfMissing($pdo, 'users', 'full_name', 'VARCHAR(255) DEFAULT NULL');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS dashboard_widgets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    widget_type VARCHAR(50) NOT NULL,
                    zone ENUM(\'top\', \'left\', \'right\') NOT NULL,
                    position INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_dashboardwidget_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        27 => function (PDO $pdo): void {
            // Replaces the old request-bound render tick (see
            // stepLdrawSetRenderBatch(), removed): a persistent CLI worker
            // (bin/ldraw_render_worker.php) now claims one row at a time from
            // this queue instead of exec()-ing leocad inside a web request —
            // see getLdrawSetRenderProgress()/runLdrawRenderWorkerOnce() in
            // src/ldraw.php for why (Apache worker-pool exhaustion + an
            // external gateway timeout neither could be fixed from the
            // request side).
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS ldraw_render_queue (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    part_id INT NOT NULL,
                    color_id INT NOT NULL,
                    status ENUM(\'pending\', \'rendering\') NOT NULL DEFAULT \'pending\',
                    attempts INT NOT NULL DEFAULT 0,
                    started_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY ldraw_render_queue_pair (part_id, color_id),
                    CONSTRAINT fk_ldrawrenderqueue_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        28 => function (PDO $pdo): void {
            // Loose minifigures stored independently of any owned set — the
            // per-part equivalent (storage_items) already existed, minifigs
            // had no counterpart at all (a minifig only ever lived inside an
            // owned set's own auto-generated location, via
            // owned_set_minifigs). No spare_quantity columns here unlike
            // storage_items: that concept is specifically about a SET's own
            // spares pool, which doesn't apply to a minifig stored on its
            // own.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS minifig_storage_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    location_id INT NOT NULL,
                    minifig_id INT NOT NULL,
                    condition_type ENUM(\'new\', \'used\') NOT NULL DEFAULT \'used\',
                    quantity INT NOT NULL DEFAULT 0,
                    damaged_quantity INT NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_minifigstorageitem_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_minifigstorageitem_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE RESTRICT,
                    UNIQUE KEY minifig_storage_item_unique (location_id, minifig_id, condition_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        29 => function (PDO $pdo): void {
            // DB-backed session storage (src/session_handler.php) — see that
            // file's doc comment for why: on shared hosting, PHP's own
            // gc_maxlifetime ini setting doesn't reliably keep a session
            // alive as long as this app configures, since the save directory
            // is shared across every site on the host.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(128) NOT NULL PRIMARY KEY,
                    data MEDIUMTEXT NOT NULL,
                    last_activity DATETIME NOT NULL,
                    INDEX idx_sessions_last_activity (last_activity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        30 => function (PDO $pdo): void {
            // BrickLink price-guide enrichment (src/bricklink_prices.php) —
            // bricklink_item_id is resolved once (BrickLink's own internal
            // numeric catalog id, distinct from the set number) and cached
            // forever; the price columns get refreshed opportunistically by
            // stepBricklinkPriceSync(), never live on a page request.
            // checked_at is set on every attempt (even a failed lookup), so
            // a set BrickLink doesn't carry doesn't get retried every cycle.
            addColumnIfMissing($pdo, 'sets', 'bricklink_item_id', 'INT DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_price_new', 'DECIMAL(10,2) DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_price_used', 'DECIMAL(10,2) DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_price_currency', 'VARCHAR(10) DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_price_checked_at', 'TIMESTAMP NULL DEFAULT NULL');
        },
    ];
}

/**
 * ALTER TABLE ... ADD INDEX has no IF NOT EXISTS in MariaDB versions we must
 * support on shared hosting, so check information_schema first to keep the
 * migration safe to re-run after a partial failure. $columns may be a single
 * column name, a comma-separated list for a composite index, or include a
 * prefix length like "local_image_path(1)" for a long text/varchar column.
 */
function addIndexIfMissing(PDO $pdo, string $table, string $indexName, string $columns): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $indexName]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $columnList = implode(', ', array_map(function (string $c) {
        $c = trim($c);
        if (preg_match('/^([a-zA-Z0-9_]+)(\(\d+\))$/', $c, $m)) {
            return '`' . $m[1] . '`' . $m[2];
        }
        return '`' . $c . '`';
    }, explode(',', $columns)));
    $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columnList)");
}

/**
 * ALTER TABLE ... ADD COLUMN has no IF NOT EXISTS in the MariaDB versions we
 * must support on shared hosting either — same information_schema check as
 * addIndexIfMissing(), for the same reason (safe to re-run after a partial
 * failure).
 */
function addColumnIfMissing(PDO $pdo, string $table, string $columnName, string $columnDefinition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $columnName]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$columnName` $columnDefinition");
}

function dropIndexIfExists(PDO $pdo, string $table, string $indexName): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $indexName]);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName`");
}

const CURRENT_SCHEMA_VERSION = 30;

function getInstalledSchemaVersion(): int
{
    $value = getAppSetting('schema_version');
    return $value !== null ? (int) $value : 0;
}

function setInstalledSchemaVersion(int $version): void
{
    setAppSetting('schema_version', (string) $version);
}

function schemaMigrationPending(): bool
{
    return getInstalledSchemaVersion() < CURRENT_SCHEMA_VERSION;
}

/**
 * Runs exactly one pending migration step (if any) and advances the stored
 * version — bounded, tick-safe, mirrors the same one-step-per-call pattern
 * used by the Rebrickable import and image download.
 *
 * Deliberately does NOT wrap the migration in a transaction: MySQL/MariaDB
 * implicitly commits on DDL (ALTER TABLE, CREATE, DROP, ...), which is what
 * migrations mostly consist of — beginTransaction()/rollBack() around DDL is
 * not actually atomic and a rollBack() after a DDL statement throws "there is
 * no active transaction", masking the real error. Write migrations to be safe
 * to re-run (e.g. check-before-ALTER) so a failure partway through can retry.
 * @return array{done: bool, ranVersion: ?int}
 */
function stepSchemaMigration(): array
{
    $installed = getInstalledSchemaVersion();
    if ($installed >= CURRENT_SCHEMA_VERSION) {
        return ['done' => true, 'ranVersion' => null];
    }

    $migrations = getSchemaMigrations();
    $nextVersion = $installed + 1;

    if (isset($migrations[$nextVersion])) {
        $migrations[$nextVersion](getPDO());
    }

    setInstalledSchemaVersion($nextVersion);

    return ['done' => $nextVersion >= CURRENT_SCHEMA_VERSION, 'ranVersion' => $nextVersion];
}

/**
 * Synchronous convenience wrapper: runs every pending migration in a loop
 * within one call. Fine for schema-only ALTERs on a personal-scale DB; a
 * future migration that needs to backfill large tables row-by-row should be
 * driven via stepSchemaMigration() through a tick loop instead of this.
 */
function runAllPendingMigrations(): int
{
    $count = 0;
    do {
        $result = stepSchemaMigration();
        if ($result['ranVersion'] !== null) {
            $count++;
        }
    } while (!$result['done']);

    return $count;
}

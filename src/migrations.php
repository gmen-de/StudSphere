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
        31 => function (PDO $pdo): void {
            // Best-effort PDF-first-page thumbnail (tryRenderInstructionThumbnail(),
            // src/instructions.php) — null wherever Imagick/Ghostscript
            // isn't available or the render failed; the tile falls back to
            // a generic document icon in that case, so this is purely
            // additive enrichment, never required.
            addColumnIfMissing($pdo, 'set_instructions', 'thumbnail_path', 'VARCHAR(512) DEFAULT NULL');
        },
        32 => function (PDO $pdo): void {
            // A loose (not-in-a-set) minifig's own constituent parts — same
            // reasoning as migration 18's owned_set_minifig_parts, just keyed
            // by a specific minifig_storage_items row instead of an owned_set
            // instance, since the same minifig can be stored more than once
            // (different locations/conditions) and each copy's completeness
            // is independent.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS minifig_storage_item_parts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    minifig_storage_item_id INT NOT NULL,
                    part_id INT NOT NULL,
                    color_id INT DEFAULT NULL,
                    quantity INT NOT NULL DEFAULT 0,
                    damaged_quantity INT NOT NULL DEFAULT 0,
                    UNIQUE KEY minifig_storage_item_part_unique (minifig_storage_item_id, part_id, color_id),
                    CONSTRAINT fk_minifigstorageitempart_item FOREIGN KEY (minifig_storage_item_id) REFERENCES minifig_storage_items(id) ON DELETE CASCADE,
                    CONSTRAINT fk_minifigstorageitempart_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_minifigstorageitempart_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        33 => function (PDO $pdo): void {
            // Reworks loose minifig storage from "one row = N identical,
            // aggregated instances" to "one row = exactly one physical
            // minifig" — mirrors how owned_sets already works for whole
            // sets (no quantity column, one row per physical copy), per
            // explicit user direction ("das muss für jede Figur individuell
            // sein, wie für die normalen Sets auch"). Splits every existing
            // quantity>1 row into `quantity` separate quantity=1 rows first
            // so no stock is lost; damaged_quantity is NOT carried over
            // (dropped below anyway — it was never actually surfaced in any
            // UI for loose minifigs, only for parts).
            $rows = $pdo->query('SELECT id, location_id, minifig_id, condition_type, quantity FROM minifig_storage_items WHERE quantity > 1')->fetchAll();
            $insertStmt = $pdo->prepare('INSERT INTO minifig_storage_items (location_id, minifig_id, condition_type, quantity) VALUES (?, ?, ?, 1)');
            $resetStmt = $pdo->prepare('UPDATE minifig_storage_items SET quantity = 1 WHERE id = ?');
            foreach ($rows as $row) {
                $extra = (int) $row['quantity'] - 1;
                for ($i = 0; $i < $extra; $i++) {
                    $insertStmt->execute([$row['location_id'], $row['minifig_id'], $row['condition_type']]);
                }
                $resetStmt->execute([$row['id']]);
            }

            // minifig_storage_item_parts (migration 32) is keyed by
            // minifig_storage_item_id under the OLD aggregated-quantity
            // meaning (nominal scaled by the row's old quantity) — wiped
            // rather than redistributed, since that table was introduced
            // this same release and holds no real data yet worth preserving.
            $pdo->exec('TRUNCATE TABLE minifig_storage_item_parts');

            // The replacement indexes must exist BEFORE the old unique key is
            // dropped: its leading column (location_id) is what currently
            // satisfies InnoDB's "every FK column needs a supporting index"
            // requirement for fk_minifigstorageitem_location, so dropping it
            // first fails with "Cannot drop index ... needed in a foreign
            // key constraint" (confirmed the hard way against the live DB).
            addIndexIfMissing($pdo, 'minifig_storage_items', 'idx_minifigstorageitem_location', 'location_id');
            addIndexIfMissing($pdo, 'minifig_storage_items', 'idx_minifigstorageitem_minifig', 'minifig_id');
            dropIndexIfExists($pdo, 'minifig_storage_items', 'minifig_storage_item_unique');
            dropColumnIfExists($pdo, 'minifig_storage_items', 'quantity');
            dropColumnIfExists($pdo, 'minifig_storage_items', 'damaged_quantity');
        },
        34 => function (PDO $pdo): void {
            // BrickLink price-guide fields for minifigs, same shape as
            // migration 30's sets.bricklink_item_id/bricklink_price_* —
            // see refreshBricklinkPriceForMinifig() (src/bricklink_prices.php).
            // bricklink_price_item_id is BrickLink's numeric idItem, NOT the
            // same value as the existing minifigs.bricklink_id column (that
            // one is BrickLink's own alphanumeric catalog code, e.g.
            // "sw0001a", resolved via moykubik.ru — idItem is a further,
            // separate resolution step from that code).
            addColumnIfMissing($pdo, 'minifigs', 'bricklink_price_item_id', 'INT DEFAULT NULL');
            addColumnIfMissing($pdo, 'minifigs', 'bricklink_price_new', 'DECIMAL(10,2) DEFAULT NULL');
            addColumnIfMissing($pdo, 'minifigs', 'bricklink_price_used', 'DECIMAL(10,2) DEFAULT NULL');
            addColumnIfMissing($pdo, 'minifigs', 'bricklink_price_currency', 'VARCHAR(10) DEFAULT NULL');
            addColumnIfMissing($pdo, 'minifigs', 'bricklink_price_checked_at', 'TIMESTAMP NULL DEFAULT NULL');
        },
        35 => function (PDO $pdo): void {
            // Full owned_set_detail-style detail page for loose minifig
            // instances (src/owned_minifigs.php) — notes field, a photo
            // gallery, and a sales log, each mirroring the matching
            // owned_sets/owned_set_photos/owned_set_sales piece exactly.
            addColumnIfMissing($pdo, 'minifig_storage_items', 'notes', 'TEXT DEFAULT NULL');

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS minifig_storage_item_photos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    minifig_storage_item_id INT NOT NULL,
                    caption VARCHAR(255) DEFAULT NULL,
                    original_filename VARCHAR(255) NOT NULL,
                    stored_path VARCHAR(512) NOT NULL,
                    file_size INT NOT NULL,
                    uploaded_by INT DEFAULT NULL,
                    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_minifigstorageitemphoto_item FOREIGN KEY (minifig_storage_item_id) REFERENCES minifig_storage_items(id) ON DELETE CASCADE,
                    CONSTRAINT fk_minifigstorageitemphoto_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS minifig_storage_item_sales (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    minifig_id INT DEFAULT NULL,
                    fig_num VARCHAR(50) NOT NULL,
                    name VARCHAR(255) DEFAULT NULL,
                    price DECIMAL(10,2) DEFAULT NULL,
                    sold_at DATE DEFAULT NULL,
                    platform VARCHAR(255) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    sold_by INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_minifigsale_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE SET NULL,
                    CONSTRAINT fk_minifigsale_user FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        36 => function (PDO $pdo): void {
            // "Baubare Sets" (?page=build_sets, src/build_sets.php) — one
            // cached row per catalog set with actual/nominal piece counts
            // split into total/exclusive/rare/minifig buckets. Two
            // identical tables: the scan writes into the staging table tick
            // by tick, so the live cache stays a complete, consistent
            // snapshot until the scan finishes and atomically swaps the two
            // (stepBuildSetsScan()).
            $columns = 'set_id INT NOT NULL PRIMARY KEY,
                    total_nominal INT NOT NULL,
                    total_actual INT NOT NULL,
                    exclusive_nominal INT NOT NULL,
                    exclusive_actual INT NOT NULL,
                    rare_nominal INT NOT NULL,
                    rare_actual INT NOT NULL,
                    minifig_nominal INT NOT NULL,
                    minifig_actual INT NOT NULL';
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS buildable_sets_cache (
                    $columns,
                    CONSTRAINT fk_buildablesetscache_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS buildable_sets_cache_staging (
                    $columns,
                    CONSTRAINT fk_buildablesetscachestaging_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        },
        37 => function (PDO $pdo): void {
            // Renames every existing owned-set storage_locations row (the
            // auto-generated location_type='owned_set' node addOwnedSet()
            // creates, src/owned_sets.php) from the old "Name (SetNum) #N"
            // pattern to "SetNum - Name #N", matching the new pattern that
            // function itself now uses for freshly-added sets. Instance
            // numbers (#N) are re-derived by ordering each set_id's rows by
            // owned_sets.id — the original numbers were only ever "how many
            // rows for this set_id existed at creation time", not a stored,
            // stable ordinal, so this is the same deterministic rule applied
            // uniformly rather than trying to recover the exact historical
            // count.
            $rows = $pdo->query(
                'SELECT os.location_id, os.set_id, s.rebrickable_set_num, s.name AS set_name
                 FROM owned_sets os
                 INNER JOIN sets s ON s.id = os.set_id
                 ORDER BY os.set_id, os.id'
            )->fetchAll();
            $updateStmt = $pdo->prepare('UPDATE storage_locations SET name = ? WHERE id = ?');
            $counters = [];
            foreach ($rows as $row) {
                $setId = (int) $row['set_id'];
                $counters[$setId] = ($counters[$setId] ?? 0) + 1;
                $newName = $row['rebrickable_set_num'] . ' - ' . $row['set_name'] . ' #' . $counters[$setId];
                $updateStmt->execute([$newName, (int) $row['location_id']]);
            }
        },
        38 => function (PDO $pdo): void {
            // BrickLink part-price sync (src/bricklink_prices.php). idItem is
            // per PART (shared across all its colors, confirmed live against
            // BrickLink's own catalog pages), so it lives directly on parts —
            // same shape as sets.bricklink_item_id. part_bricklink_prices is
            // the per-part+color price cache, keyed on colors(id) (the
            // internal PK storage_items.color_id also uses — NOT
            // colors.color_id, the Rebrickable id space part_set_counts uses
            // instead).
            addColumnIfMissing($pdo, 'parts', 'bricklink_item_id', 'INT DEFAULT NULL');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS part_bricklink_prices (
                    part_id INT NOT NULL,
                    color_id INT NOT NULL,
                    bricklink_price_new DECIMAL(10,2) DEFAULT NULL,
                    bricklink_price_used DECIMAL(10,2) DEFAULT NULL,
                    bricklink_price_currency VARCHAR(10) DEFAULT NULL,
                    bricklink_price_checked_at TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (part_id, color_id),
                    CONSTRAINT fk_partbricklinkprice_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_partbricklinkprice_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        39 => function (PDO $pdo): void {
            // BrickOwl has no Rebrickable-mediated external-id mapping the
            // way BrickLink does (external_ids.BrickLink, see migration 25)
            // — there is no auto-resolution mechanism for this at all, so
            // it's purely a manual-entry/edit field on the part-detail
            // modal's "Informationen" tab (action=update_part_external_ids,
            // src/routes/actions.php).
            addColumnIfMissing($pdo, 'parts', 'brickowl_id', 'VARCHAR(20) DEFAULT NULL');
        },
        40 => function (PDO $pdo): void {
            // Pickliste PWA (src/pick_lists.php, /pick/) — a pick list walks
            // a set's/minifig's needed parts against current stock,
            // decrementing loose storage as parts get physically picked.
            // Every pick list is itself a storage_locations row so its
            // contents stay visible as loose stock everywhere else (per
            // explicit requirement — see getLooseStockMap()'s unchanged
            // 'owned_set'-only exclusion). "Pick Lager" is the single
            // top-level root every pick list nests under, found via
            // location_type rather than a stored id — same
            // self-identifying-marker idiom as 'owned_set'. Idempotent: a
            // second run of this migration must not create a duplicate root.
            $existingRoot = $pdo->query(
                "SELECT id FROM storage_locations WHERE location_type = 'pick_lager_root' LIMIT 1"
            )->fetchColumn();
            if ($existingRoot === false) {
                $pdo->prepare(
                    "INSERT INTO storage_locations (parent_id, name, location_type) VALUES (NULL, 'Pick Lager', 'pick_lager_root')"
                )->execute();
            }

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS pick_lists (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    location_id INT NOT NULL,
                    source_type ENUM(\'set\', \'minifig\') NOT NULL,
                    set_id INT DEFAULT NULL,
                    minifig_id INT DEFAULT NULL,
                    inventory_id INT DEFAULT NULL,
                    owned_set_id INT DEFAULT NULL,
                    status ENUM(\'active\', \'completed\', \'closed\') NOT NULL DEFAULT \'active\',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP NULL DEFAULT NULL,
                    closed_at TIMESTAMP NULL DEFAULT NULL,
                    CONSTRAINT fk_picklist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_picklist_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_picklist_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE SET NULL,
                    CONSTRAINT fk_picklist_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE SET NULL,
                    CONSTRAINT fk_picklist_ownedset FOREIGN KEY (owned_set_id) REFERENCES owned_sets(id) ON DELETE SET NULL,
                    INDEX idx_picklist_user_status (user_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS pick_list_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    pick_list_id INT NOT NULL,
                    item_type ENUM(\'part\', \'minifig\') NOT NULL,
                    part_id INT DEFAULT NULL,
                    color_id INT DEFAULT NULL,
                    minifig_id INT DEFAULT NULL,
                    source_minifig_storage_item_id INT DEFAULT NULL,
                    needed_quantity INT NOT NULL,
                    picked_quantity INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_plitem_list FOREIGN KEY (pick_list_id) REFERENCES pick_lists(id) ON DELETE CASCADE,
                    CONSTRAINT fk_plitem_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_plitem_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_plitem_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE RESTRICT,
                    INDEX idx_plitem_list (pick_list_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS pick_list_stocktake_flags (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    pick_list_id INT NOT NULL,
                    pick_list_item_id INT DEFAULT NULL,
                    location_id INT NOT NULL,
                    part_id INT NOT NULL,
                    color_id INT DEFAULT NULL,
                    note VARCHAR(500) DEFAULT NULL,
                    flagged_by INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    resolved_at TIMESTAMP NULL DEFAULT NULL,
                    CONSTRAINT fk_plflag_list FOREIGN KEY (pick_list_id) REFERENCES pick_lists(id) ON DELETE CASCADE,
                    CONSTRAINT fk_plflag_item FOREIGN KEY (pick_list_item_id) REFERENCES pick_list_items(id) ON DELETE SET NULL,
                    CONSTRAINT fk_plflag_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE CASCADE,
                    CONSTRAINT fk_plflag_user FOREIGN KEY (flagged_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_plflag_unresolved (resolved_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            // LDraw 4-perspective renders (src/ldraw.php) — 'home' is today's
            // only angle, so DEFAULT 'home' turns every existing row into a
            // valid angle='home' entry with zero re-rendering and zero
            // filename/path changes.
            addColumnIfMissing($pdo, 'part_color_images', 'angle', "VARCHAR(20) NOT NULL DEFAULT 'home'");
            widenUniqueKeyOverForeignKey($pdo, 'part_color_images', 'part_color_image_unique', ['part_id', 'color_id', 'angle']);

            addColumnIfMissing($pdo, 'ldraw_render_queue', 'angle', "VARCHAR(20) NOT NULL DEFAULT 'home'");
            widenUniqueKeyOverForeignKey($pdo, 'ldraw_render_queue', 'ldraw_render_queue_pair', ['part_id', 'color_id', 'angle']);
        },
        41 => function (PDO $pdo): void {
            // Pick lists were only ever identified by their storage_locations
            // name — the physical container (e.g. "Tupper Box #1") — which
            // conflated "what am I picking for" with "where am I collecting
            // it." Splits those into two independently editable strings: this
            // column is the pick list's own display name (defaults to the
            // set/minifig label, e.g. "75192 - Millennium Falcon"), while the
            // storage_locations row it already owns stays the container name.
            // Existing rows get '' — display code falls back to the location
            // name for those (COALESCE(NULLIF(name,''), ...)), so nothing
            // already created goes unlabeled.
            addColumnIfMissing($pdo, 'pick_lists', 'name', "VARCHAR(255) NOT NULL DEFAULT ''");
        },
        42 => function (PDO $pdo): void {
            // "Baubare Minifiguren" (?page=build_minifigs) used to compute
            // getBuildableMinifigs() live on every page load — a two-phase
            // SQL+PHP scan assumed to stay under ~1s at "a handful of
            // hundred" candidates (src/build.php's own doc comment). That
            // assumption broke down as the collection's loose stock grew:
            // more minifigs now pass the cheap "own at least one of
            // everything" prefilter, ballooning the expensive per-candidate
            // loop and hanging the whole page (confirmed live: a 30s+
            // request with no response). Same fix "Baubare Sets" already
            // needed for the same reason — buildable_sets_cache/_staging,
            // see migration history — mirrored here for minifigs.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS buildable_minifigs_cache (
                    minifig_id INT NOT NULL PRIMARY KEY,
                    buildable INT NOT NULL,
                    missing INT NOT NULL,
                    CONSTRAINT fk_buildableminifigscache_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS buildable_minifigs_cache_staging (
                    minifig_id INT NOT NULL PRIMARY KEY,
                    buildable INT NOT NULL,
                    missing INT NOT NULL,
                    CONSTRAINT fk_buildableminifigscachestaging_minifig FOREIGN KEY (minifig_id) REFERENCES minifigs(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        },
        43 => function (PDO $pdo): void {
            // "Bauanleitungen" (src/instruction_manuals.php) — a fixed root,
            // analogous to 'pick_lager_root' (migration 40), except its
            // children are freely user-created normal locations rather than
            // programmatically managed ones; every location in its subtree
            // is dedicated exclusively to instruction-manual storage
            // (isLocationInInstructionsSubtree()). Idempotent: a second run
            // must not create a duplicate root.
            $existingRoot = $pdo->query(
                "SELECT id FROM storage_locations WHERE location_type = 'instructions_root' LIMIT 1"
            )->fetchColumn();
            if ($existingRoot === false) {
                $pdo->prepare(
                    "INSERT INTO storage_locations (parent_id, name, location_type) VALUES (NULL, 'Bauanleitungen', 'instructions_root')"
                )->execute();
            }

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS instruction_manuals (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    location_id INT NOT NULL,
                    set_id INT NOT NULL,
                    condition_grade ENUM(\'mint\',\'near_mint\',\'good\',\'fair\',\'poor\') NOT NULL DEFAULT \'good\',
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_instructionmanual_location FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_instructionmanual_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE RESTRICT,
                    INDEX idx_instructionmanual_location (location_id),
                    INDEX idx_instructionmanual_set (set_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            // Mirrors sets.bricklink_price_* — see
            // refreshBricklinkPriceForSetInstructions() (src/bricklink_prices.php).
            // BrickLink treats a set's own catalog entry (S=) and its
            // Instructions entry (I=) as two separate price guides.
            addColumnIfMissing($pdo, 'sets', 'bricklink_instructions_item_id', 'INT DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_instructions_price_new', 'DECIMAL(10,2) DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_instructions_price_used', 'DECIMAL(10,2) DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_instructions_price_currency', 'VARCHAR(10) DEFAULT NULL');
            addColumnIfMissing($pdo, 'sets', 'bricklink_instructions_price_checked_at', 'TIMESTAMP NULL DEFAULT NULL');
        },
        44 => function (PDO $pdo): void {
            // Condition is now derived from checkable defect criteria
            // (computeInstructionManualGrade(), src/instruction_manuals.php)
            // instead of a fixed 5-tier ENUM. Old condition_grade values
            // (mint/near_mint/...) have no meaningful equivalent under the
            // new criteria — existing rows are cleared per explicit user
            // request rather than mapped.
            $pdo->exec('DELETE FROM instruction_manuals');
            dropColumnIfExists($pdo, 'instruction_manuals', 'condition_grade');
            addColumnIfMissing($pdo, 'instruction_manuals', 'is_new', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'instruction_manuals', 'is_holed', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'instruction_manuals', 'has_tears', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'instruction_manuals', 'is_painted', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'instruction_manuals', 'has_stickers', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'instruction_manuals', 'is_glued', 'TINYINT(1) NOT NULL DEFAULT 0');
            addColumnIfMissing($pdo, 'instruction_manuals', 'binding_broken', 'TINYINT(1) NOT NULL DEFAULT 0');
        },
        45 => function (PDO $pdo): void {
            // "Bauanleitungen" locations are no longer freely user-created —
            // per explicit follow-up request, the root now auto-fills with
            // one virtual per-theme location per distinct set theme
            // (location_type='instructions_theme'), auto-created on demand
            // by getOrCreateInstructionsThemeLocation() (src/instruction_manuals.php).
            // theme_id links such a location back to its theme; NULL for
            // every other location type.
            addColumnIfMissing($pdo, 'storage_locations', 'theme_id', 'INT DEFAULT NULL');
            addIndexIfMissing($pdo, 'storage_locations', 'idx_storage_locations_theme', 'theme_id');

            // Every manual added under the old free-form model sits directly
            // at the instructions_root — real user data by now (not wiped
            // like migration 44's condition_grade cutover), so each one is
            // reassigned to its own set's theme location instead of being
            // dropped.
            $manuals = $pdo->query(
                'SELECT im.id, im.location_id AS old_location_id, s.theme, th.name AS theme_name
                 FROM instruction_manuals im
                 INNER JOIN sets s ON s.id = im.set_id
                 LEFT JOIN themes th ON th.theme_id = s.theme'
            )->fetchAll();
            $oldLocationIds = [];
            $updateStmt = $pdo->prepare('UPDATE instruction_manuals SET location_id = ? WHERE id = ?');
            foreach ($manuals as $manual) {
                $oldLocationIds[(int) $manual['old_location_id']] = true;
                $themeId = $manual['theme'] !== null ? (int) $manual['theme'] : INSTRUCTIONS_THEME_FALLBACK_ID;
                $themeName = $manual['theme_name'] ?? INSTRUCTIONS_THEME_FALLBACK_NAME;
                $newLocationId = getOrCreateInstructionsThemeLocation($pdo, $themeId, $themeName);
                $updateStmt->execute([$newLocationId, $manual['id']]);
            }
            // The old locations manuals used to sit at — typically just the
            // instructions_root itself — pruned if they happen to now be an
            // empty instructions_theme location (a no-op for the root, whose
            // location_type isn't 'instructions_theme').
            foreach (array_keys($oldLocationIds) as $oldLocationId) {
                pruneEmptyInstructionsThemeLocation($pdo, $oldLocationId);
            }
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

/**
 * Widens an existing UNIQUE KEY to cover additional trailing columns (e.g.
 * (part_id, color_id) -> (part_id, color_id, angle)), safe to call even when
 * that key's leading column is also the target of a FOREIGN KEY constraint
 * on this table. A plain dropIndexIfExists() + separate ADD fails there with
 * MySQL/MariaDB error 1553 ("needed in a foreign key constraint") — dropping
 * the index leaves the table with no index at all covering the FK's column
 * for the brief moment before the new one is added, which InnoDB refuses.
 * Combining DROP and ADD into one ALTER TABLE statement instead makes MySQL
 * evaluate the constraint against the final resulting schema, not the
 * intermediate state, so it never actually goes without a covering index.
 * Idempotent: does nothing once $indexName already has exactly $columns.
 */
function widenUniqueKeyOverForeignKey(PDO $pdo, string $table, string $indexName, array $columns): void
{
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
         ORDER BY seq_in_index'
    );
    $stmt->execute([$table, $indexName]);
    $currentColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($currentColumns === $columns) {
        return;
    }

    $columnList = implode(', ', array_map(fn (string $c): string => "`$c`", $columns));
    if (empty($currentColumns)) {
        $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` ($columnList)");
        return;
    }
    $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName`, ADD UNIQUE KEY `$indexName` ($columnList)");
}

/**
 * Inverse of addColumnIfMissing() — same information_schema existence check
 * so a migration that drops a column stays safe to re-run after a partial
 * failure.
 */
function dropColumnIfExists(PDO $pdo, string $table, string $columnName): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $columnName]);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$columnName`");
}

const CURRENT_SCHEMA_VERSION = 45;

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

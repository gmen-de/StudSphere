<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Maps the stats-bar figures to their app_settings cache keys. These are the
 * user's own storage/collection, not the imported Rebrickable catalog.
 *
 * - "Bausteine gesamt": every physical piece the household owns, loose or
 *   currently built into an owned set (SUM(quantity) across all of
 *   storage_items).
 * - "einzelne Bausteine": distinct part+color *types* that are actually
 *   loose and free to grab for a build right now — deliberately excludes
 *   stock materialized at an owned-set's own location (location_type
 *   'owned_set', see src/owned_sets.php), since those pieces are "owned"
 *   but not really "available" until the set is taken apart.
 * - "Sets": how many set instances are in owned_sets (physical copies, so
 *   owning the same set twice counts as 2 — matches "Bausteine gesamt"
 *   being a piece count, not a distinct-item count).
 * - "Minifiguren": summed live from owned sets' own Rebrickable inventories
 *   (inventory_minifigs) — there's no separate "owned minifigs" storage
 *   table, minifig ownership is entirely derived from which sets are owned.
 *
 * Cached rather than computed live because these queries (especially the
 * minifig one, which joins through every owned set's inventory) can get
 * expensive as storage_items/owned_sets grow, and the stats bar renders on
 * every page. Call refreshAppStatsCache() wherever storage_items or
 * owned_sets actually changes — see its call sites in index.php.
 */
const APP_STATS_CACHE_KEYS = [
    'bricks_total' => 'cached_stat_bricks_total',
    'bricks_distinct' => 'cached_stat_bricks_distinct',
    'sets' => 'cached_stat_sets',
    'minifigs' => 'cached_stat_minifigs',
];

function computeAppStats(PDO $pdo): array
{
    $cached = [];
    foreach (APP_STATS_CACHE_KEYS as $key => $settingKey) {
        $value = getAppSetting($settingKey);
        if ($value === null) {
            // Never cached yet (fresh install, or cache row missing) — compute
            // once now and store it, so subsequent requests hit the cache.
            return refreshAppStatsCache($pdo);
        }
        $cached[$key] = (int) $value;
    }
    return $cached;
}

function refreshAppStatsCache(PDO $pdo): array
{
    $stats = [
        'bricks_total' => (int) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM storage_items')->fetchColumn(),
        'bricks_distinct' => (int) $pdo->query(
            "SELECT COUNT(DISTINCT si.part_id, si.color_id)
             FROM storage_items si
             INNER JOIN storage_locations sl ON sl.id = si.location_id
             WHERE si.quantity > 0 AND (sl.location_type IS NULL OR sl.location_type != 'owned_set')"
        )->fetchColumn(),
        'sets' => (int) $pdo->query('SELECT COUNT(*) FROM owned_sets')->fetchColumn(),
        'minifigs' => (int) $pdo->query(
            "SELECT COALESCE(SUM(im.quantity), 0)
             FROM owned_sets os
             INNER JOIN sets s ON s.id = os.set_id
             INNER JOIN rebrickable_inventories ri ON ri.set_num = s.rebrickable_set_num
                 AND ri.version = (SELECT MAX(version) FROM rebrickable_inventories WHERE set_num = s.rebrickable_set_num)
             INNER JOIN inventory_minifigs im ON im.inventory_id = ri.inventory_id"
        )->fetchColumn(),
    ];
    foreach (APP_STATS_CACHE_KEYS as $key => $settingKey) {
        setAppSetting($settingKey, (string) $stats[$key]);
    }
    return $stats;
}

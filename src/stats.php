<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/owned_sets.php';

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
 * - "Minifiguren": SUM(quantity) across owned_set_minifigs — actually
 *   tracked per instance (see getOwnedSetMinifigsWithStatus() in
 *   src/owned_sets.php), not the catalog's nominal inventory_minifigs count,
 *   so a minifig marked missing during inventory-taking is reflected here
 *   too (consistent with "Bausteine gesamt"/"Beschädigte Teile" also
 *   reflecting actually-tracked state, not a catalog assumption).
 * - "Beschädigte Teile": SUM(damaged_quantity) across all of storage_items.
 *   Damaged pieces are still physically present (a subset of "Bausteine
 *   gesamt"/quantity, not subtracted from it) — see
 *   setOwnedSetPartInventory() in src/owned_sets.php.
 * - "Fehlende Teile": nominal minus actual, summed across every owned set
 *   (same definition as getOwnedSetCompleteness(), just totalled instead of
 *   per-set) — not a flat column sum like the other five, needs each set's
 *   own Rebrickable inventory to know what "nominal" means, which is exactly
 *   why this whole thing is cached rather than computed on every page load.
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
    'bricks_damaged' => 'cached_stat_bricks_damaged',
    'bricks_missing' => 'cached_stat_bricks_missing',
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
        'minifigs' => (int) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM owned_set_minifigs')->fetchColumn(),
        'bricks_damaged' => (int) $pdo->query('SELECT COALESCE(SUM(damaged_quantity), 0) FROM storage_items')->fetchColumn(),
        'bricks_missing' => computeCollectionMissingPartsTotal($pdo),
    ];
    foreach (APP_STATS_CACHE_KEYS as $key => $settingKey) {
        setAppSetting($settingKey, (string) $stats[$key]);
    }
    return $stats;
}

function computeCollectionMissingPartsTotal(PDO $pdo): int
{
    $missing = 0;
    foreach (getAllOwnedSets($pdo) as $set) {
        $completeness = getOwnedSetCompleteness($pdo, $set);
        $missing += max(0, $completeness['nominal'] - $completeness['actual']);
    }
    return $missing;
}

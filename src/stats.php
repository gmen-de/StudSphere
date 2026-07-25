<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Maps the stats-bar figures to their app_settings cache keys. These are the
 * user's own storage (storage_items), not the imported Rebrickable catalog —
 * "Bausteine gesamt"/"einzelne Bausteine" reflect what's actually in the
 * user's inventory. "Sets"/"Minifiguren" are hardcoded to 0 for now: there is
 * no data model yet for which sets/minifigs a user owns ("Meine Sets"/"Meine
 * Bausteine" are still stub pages) — once that's built, wire real counts in
 * here instead of the 0 literals below.
 *
 * Cached rather than computed live because SUM(quantity) can get expensive
 * again once storage_items is large, and the stats bar renders on every
 * page — see refreshAppStatsCache() call sites for when the cache updates.
 * There are none yet: nothing writes to storage_items yet either (no "add/
 * move stock" feature exists), so the self-heal-on-first-read path in
 * computeAppStats() is the only thing populating the cache today. Add a
 * refreshAppStatsCache() call wherever stock quantities get written once
 * that feature exists.
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
        'bricks_distinct' => (int) $pdo->query('SELECT COUNT(DISTINCT part_id, color_id) FROM storage_items WHERE quantity > 0')->fetchColumn(),
        'sets' => 0,
        'minifigs' => 0,
    ];
    foreach (APP_STATS_CACHE_KEYS as $key => $settingKey) {
        setAppSetting($settingKey, (string) $stats[$key]);
    }
    return $stats;
}

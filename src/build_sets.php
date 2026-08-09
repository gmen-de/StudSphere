<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';
require_once __DIR__ . '/storage.php';

/**
 * "Baubare Sets" (?page=build_sets, src/routes/pages.php) — for every
 * catalog set in a chosen scope (a theme + its subthemes and/or a year
 * range, or the whole catalog when neither is given), how complete would it
 * be if assembled from the current loose stock right now? Four buckets per
 * set, same exclusive(=1 set)/rare(2-3 sets)/normal bucketing
 * getSetInventorySummary() (src/sets.php) and getOwnedSetCompleteness()
 * (src/owned_sets.php) already use elsewhere, plus a combined
 * already-assembled-or-buildable-from-parts minifig bucket.
 *
 * The catalog is far too large (27,851 sets, ~1.5M inventory_parts rows) for
 * a live per-request computation, unlike getBuildableMinifigs() (src/build.php)
 * which handles the whole ~17k-minifig catalog in about a second — so the
 * result is a tick-based scan (mirroring stepRebrickableImport(),
 * src/download.php) into buildable_sets_cache, read back by
 * getBuildableSetsResults() without ever re-scanning. The cache is a single
 * "last scan" snapshot (not one per filter combination): re-running with
 * different filters replaces it. A running scan writes into
 * buildable_sets_cache_staging first and only swaps it into the live table
 * once fully done, so whatever is currently on screen stays intact and
 * browsable while a new scan is in progress.
 */

const BUILD_SETS_SCAN_BATCH_SIZE = 500;
const BUILD_SETS_SCAN_TIME_BUDGET_SECONDS = 4.0;

const BUILD_SETS_CACHE_COMPUTED_AT_KEY = 'buildable_sets_cache_computed_at';
const BUILD_SETS_CACHE_SCOPE_KEY = 'buildable_sets_cache_scope';
const BUILD_SETS_CACHE_STALE_KEY = 'buildable_sets_cache_stale';

/**
 * Called from refreshAppStatsCache() (src/stats.php), which the rest of the
 * app already calls after every storage_items-affecting write — piggybacking
 * on that single existing hook point means every write site that should
 * invalidate this cache already does, without touching each one
 * individually. Only flips the flag if a cache actually exists yet (a fresh
 * install with nothing scanned has nothing to mark stale). The results page
 * keeps showing the (now flagged) cache with a "Bestand geändert" banner
 * rather than silently forcing a re-scan — a full re-scan can take minutes,
 * see this file's own doc comment.
 */
function markBuildableSetsCacheStale(): void
{
    if (getAppSetting(BUILD_SETS_CACHE_COMPUTED_AT_KEY) !== null) {
        setAppSetting(BUILD_SETS_CACHE_STALE_KEY, '1');
    }
}

/**
 * @return array{computedAt: ?string, scope: ?array{theme_id: ?int, theme_name: ?string, year_from: ?int, year_to: ?int}, stale: bool}
 */
function getBuildableSetsCacheMeta(PDO $pdo): array
{
    $computedAt = getAppSetting(BUILD_SETS_CACHE_COMPUTED_AT_KEY);
    $scopeJson = getAppSetting(BUILD_SETS_CACHE_SCOPE_KEY);
    $scope = null;
    if ($scopeJson !== null) {
        $decoded = json_decode($scopeJson, true);
        if (is_array($decoded)) {
            $themeId = $decoded['theme_id'] ?? null;
            $themeName = null;
            if ($themeId !== null) {
                $stmt = $pdo->prepare('SELECT name FROM themes WHERE theme_id = ?');
                $stmt->execute([$themeId]);
                $name = $stmt->fetchColumn();
                $themeName = $name !== false ? $name : null;
            }
            $scope = [
                'theme_id' => $themeId !== null ? (int) $themeId : null,
                'theme_name' => $themeName,
                'year_from' => isset($decoded['year_from']) ? (int) $decoded['year_from'] : null,
                'year_to' => isset($decoded['year_to']) ? (int) $decoded['year_to'] : null,
            ];
        }
    }
    return [
        'computedAt' => $computedAt,
        'scope' => $scope,
        'stale' => getAppSetting(BUILD_SETS_CACHE_STALE_KEY) === '1',
    ];
}

/**
 * Reads the cached scan (buildable_sets_cache joined with sets for display
 * info), computing percentages at read time rather than storing them (same
 * convention as getOwnedSetCompleteness()). $exclusiveOnly/$exclusiveRareOnly
 * are pure filters over the already-cached numbers — no re-scan, mirrors how
 * "Baubare Minifiguren"'s own filters work purely in PHP/SQL over an
 * already-computed list.
 *
 * @return array<int, array{set_id:int, rebrickable_set_num:string, name:string, thumbnail:?string, year:?int,
 *   total_percent:float, total_actual:int, total_nominal:int,
 *   exclusive_percent:float, exclusive_actual:int, exclusive_nominal:int,
 *   rare_percent:float, rare_actual:int, rare_nominal:int,
 *   minifig_percent:float, minifig_actual:int, minifig_nominal:int}>
 */
function getBuildableSetsResults(PDO $pdo, bool $exclusiveOnly, bool $exclusiveRareOnly): array
{
    $sql = 'SELECT bsc.*, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail, s.year
            FROM buildable_sets_cache bsc
            INNER JOIN sets s ON s.id = bsc.set_id';
    $conditions = [];
    if ($exclusiveOnly || $exclusiveRareOnly) {
        $conditions[] = 'bsc.exclusive_actual >= bsc.exclusive_nominal';
    }
    if ($exclusiveRareOnly) {
        $conditions[] = 'bsc.rare_actual >= bsc.rare_nominal';
    }
    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $rows = $pdo->query($sql)->fetchAll();

    $percent = function (int $actual, int $nominal): float {
        return $nominal > 0 ? round(min(100.0, ($actual / $nominal) * 100), 1) : 100.0;
    };

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'set_id' => (int) $row['set_id'],
            'rebrickable_set_num' => $row['rebrickable_set_num'],
            'name' => $row['name'],
            'thumbnail' => $row['thumbnail'],
            'year' => $row['year'] !== null ? (int) $row['year'] : null,
            'total_percent' => $percent((int) $row['total_actual'], (int) $row['total_nominal']),
            'total_actual' => (int) $row['total_actual'],
            'total_nominal' => (int) $row['total_nominal'],
            'exclusive_percent' => $percent((int) $row['exclusive_actual'], (int) $row['exclusive_nominal']),
            'exclusive_actual' => (int) $row['exclusive_actual'],
            'exclusive_nominal' => (int) $row['exclusive_nominal'],
            'rare_percent' => $percent((int) $row['rare_actual'], (int) $row['rare_nominal']),
            'rare_actual' => (int) $row['rare_actual'],
            'rare_nominal' => (int) $row['rare_nominal'],
            'minifig_percent' => $percent((int) $row['minifig_actual'], (int) $row['minifig_nominal']),
            'minifig_actual' => (int) $row['minifig_actual'],
            'minifig_nominal' => (int) $row['minifig_nominal'],
        ];
    }

    usort($results, function (array $a, array $b): int {
        return $b['total_percent'] <=> $a['total_percent'];
    });

    return $results;
}

/**
 * Generalizes getBuildableMinifigs()'s (src/build.php) per-part min-ratio
 * logic: how many *additional* copies of each minifig could be assembled
 * from $stock right now. Reuses that same function's "every required part
 * has at least some stock" SQL prefilter so the expensive per-part ratio
 * calculation only runs for genuine candidates — the vast majority of
 * minifigs are missing at least one part entirely and stay at 0 without
 * ever reaching getSetPartsList().
 *
 * @param int[]|null $minifigIds restrict to these minifig ids, or null for the whole catalog
 * @param array<string, int> $stock "part_id:color_id" (surrogate colors.id) => qty
 * @return array<int, int> buildable-from-parts count keyed by minifig_id
 */
function computeMinifigAvailabilityFromParts(PDO $pdo, ?array $minifigIds, array $stock): array
{
    if (empty($stock) || $minifigIds === []) {
        return [];
    }

    $sql = "SELECT ri.set_num AS fig_num, m.id AS minifig_id,
                   COUNT(DISTINCT CONCAT(ip.part_id,':',c.id)) AS total_pairs,
                   COUNT(DISTINCT CASE WHEN si.part_id IS NOT NULL THEN CONCAT(ip.part_id,':',c.id) END) AS matched_pairs
            FROM inventory_parts ip
            INNER JOIN rebrickable_inventories ri ON ri.inventory_id = ip.inventory_id
            INNER JOIN minifigs m ON m.fig_num = ri.set_num
            LEFT JOIN colors c ON c.color_id = ip.color_id
            LEFT JOIN (
                SELECT DISTINCT si2.part_id, si2.color_id
                FROM storage_items si2
                INNER JOIN storage_locations sl2 ON sl2.id = si2.location_id
                WHERE si2.quantity > 0 AND (sl2.location_type IS NULL OR sl2.location_type != 'owned_set')
            ) si ON si.part_id = ip.part_id AND si.color_id = c.id
            WHERE ip.is_spare = 0";
    $params = [];
    if ($minifigIds !== null) {
        $placeholders = implode(',', array_fill(0, count($minifigIds), '?'));
        $sql .= " AND m.id IN ($placeholders)";
        $params = $minifigIds;
    }
    $sql .= ' GROUP BY ri.set_num, m.id
              HAVING total_pairs = matched_pairs';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $candidate) {
        $inventoryId = getMinifigInventoryId($pdo, $candidate['fig_num']);
        if ($inventoryId === null) {
            continue;
        }
        $parts = getSetPartsList($pdo, $inventoryId, false, 'en');
        $buildable = null;
        foreach ($parts as $part) {
            if ($part['quantity'] <= 0) {
                continue;
            }
            $have = $stock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
            $ratio = intdiv($have, $part['quantity']);
            $buildable = $buildable === null ? $ratio : min($buildable, $ratio);
        }
        $result[(int) $candidate['minifig_id']] = $buildable ?? 0;
    }
    return $result;
}

/**
 * Total minifig availability per minifig_id: already-assembled loose
 * instances (minifig_storage_items — every row is loose by definition, this
 * table has no owned_set equivalent, see db/schema.sql) plus what could
 * additionally be built from loose parts
 * (computeMinifigAvailabilityFromParts() above) — combined because, per
 * explicit request, it doesn't matter whether a figure is already built or
 * still needs assembling from parts on hand.
 *
 * @param int[]|null $minifigIds
 * @return array<int, int>
 */
function computeMinifigAvailabilityMap(PDO $pdo, ?array $minifigIds, array $stock): array
{
    $loose = [];
    if ($minifigIds === null || !empty($minifigIds)) {
        $sql = 'SELECT minifig_id, COUNT(*) AS cnt FROM minifig_storage_items';
        $params = [];
        if ($minifigIds !== null) {
            $placeholders = implode(',', array_fill(0, count($minifigIds), '?'));
            $sql .= " WHERE minifig_id IN ($placeholders)";
            $params = $minifigIds;
        }
        $sql .= ' GROUP BY minifig_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $loose[(int) $row['minifig_id']] = (int) $row['cnt'];
        }
    }

    $availability = $loose;
    foreach (computeMinifigAvailabilityFromParts($pdo, $minifigIds, $stock) as $minifigId => $count) {
        $availability[$minifigId] = ($availability[$minifigId] ?? 0) + $count;
    }
    return $availability;
}

/**
 * One-time setup for a scan — resolves the candidate set/inventory list
 * (theme+descendants via getThemeAndDescendantIds(), src/sets.php, and/or a
 * year range, both optional and combinable; neither given scans the whole
 * catalog) and precomputes everything every tick needs so
 * stepBuildSetsScan() never repeats it: the loose-stock map (same
 * "exclude owned_set locations" convention as getBuildableMinifigs(),
 * src/build.php), the global sticker-part-id set (sticker-ness is a
 * part-category property, not per-inventory, unlike getStickerPartIds()'s
 * per-inventory scoping), and minifig availability for the *whole* minifig
 * catalog (matching getBuildableMinifigs()'s own always-full-catalog scope
 * — proven to run in about a second — rather than trying to first resolve
 * which minifigs the candidate sets reference, which for an unrestricted
 * scan could mean an unwieldy 27k-item IN-list for no real benefit).
 *
 * @return array the scan state, stored in $_SESSION by the caller
 */
function initBuildSetsScanState(PDO $pdo, ?int $themeId, ?int $yearFrom, ?int $yearTo): array
{
    $where = [];
    $params = [];
    if ($themeId !== null) {
        $tree = getSetThemeTree($pdo);
        $themeIds = getThemeAndDescendantIds($tree, $themeId);
        $placeholders = implode(',', array_fill(0, count($themeIds), '?'));
        $where[] = "s.theme IN ($placeholders)";
        foreach ($themeIds as $id) {
            $params[] = (string) $id;
        }
    }
    if ($yearFrom !== null) {
        $where[] = 's.year >= ?';
        $params[] = $yearFrom;
    }
    if ($yearTo !== null) {
        $where[] = 's.year <= ?';
        $params[] = $yearTo;
    }

    $sql = 'SELECT s.id AS set_id, ri.inventory_id
            FROM sets s
            INNER JOIN rebrickable_inventories ri ON ri.set_num = s.rebrickable_set_num
            INNER JOIN (
                SELECT set_num, MAX(version) AS max_version
                FROM rebrickable_inventories
                GROUP BY set_num
            ) latest ON latest.set_num = ri.set_num AND latest.max_version = ri.version';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY s.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $candidateSetIds = [];
    $candidateInventoryIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $candidateSetIds[] = (int) $row['set_id'];
        $candidateInventoryIds[] = (int) $row['inventory_id'];
    }

    $stock = [];
    $stockStmt = $pdo->query(
        "SELECT si.part_id, si.color_id, SUM(si.quantity) - SUM(si.damaged_quantity) AS stock
         FROM storage_items si
         INNER JOIN storage_locations sl ON sl.id = si.location_id
         WHERE sl.location_type IS NULL OR sl.location_type != 'owned_set'
         GROUP BY si.part_id, si.color_id
         HAVING stock > 0"
    );
    foreach ($stockStmt->fetchAll() as $row) {
        $stock[$row['part_id'] . ':' . $row['color_id']] = (int) $row['stock'];
    }

    $stickerPartIds = array_flip(array_map('intval', array_column(
        $pdo->query(
            "SELECT p.id FROM parts p
             INNER JOIN part_categories pc ON pc.part_cat_id = p.part_category
             WHERE pc.name = 'Stickers'"
        )->fetchAll(),
        'id'
    )));

    $minifigAvailability = computeMinifigAvailabilityMap($pdo, null, $stock);

    $pdo->exec('TRUNCATE TABLE buildable_sets_cache_staging');

    return [
        'candidateSetIds' => $candidateSetIds,
        'candidateInventoryIds' => $candidateInventoryIds,
        'cursor' => 0,
        'total' => count($candidateSetIds),
        'stock' => $stock,
        'stickerPartIds' => $stickerPartIds,
        'minifigAvailability' => $minifigAvailability,
        'scope' => ['theme_id' => $themeId, 'year_from' => $yearFrom, 'year_to' => $yearTo],
    ];
}

/**
 * One bounded tick — mirrors stepRebrickableImport()'s (src/download.php)
 * session-state/time-budget pattern. Processes candidate sets starting at
 * $state['cursor'], stopping once either BUILD_SETS_SCAN_BATCH_SIZE sets or
 * BUILD_SETS_SCAN_TIME_BUDGET_SECONDS elapse, whichever comes first — bounds
 * one request's runtime regardless of how parts-heavy this particular batch
 * happens to be. inventory_parts/inventory_minifigs are fetched once per
 * tick for the whole batch (not per set) to avoid N+1 queries. Writes into
 * buildable_sets_cache_staging; only the final tick swaps it into the live
 * buildable_sets_cache table (see this file's own doc comment for why).
 *
 * @return array{processed:int, total:int, done:bool}
 */
function stepBuildSetsScan(PDO $pdo, array &$state): array
{
    $startedAt = microtime(true);
    $cursor = $state['cursor'];
    $total = $state['total'];
    $stock = $state['stock'];
    $stickerPartIds = $state['stickerPartIds'];
    $minifigAvailability = $state['minifigAvailability'];

    $batchSetIds = [];
    $batchInventoryIds = [];
    $processedThisTick = 0;
    while (
        $cursor < $total
        && $processedThisTick < BUILD_SETS_SCAN_BATCH_SIZE
        && (microtime(true) - $startedAt) < BUILD_SETS_SCAN_TIME_BUDGET_SECONDS
    ) {
        $batchSetIds[] = $state['candidateSetIds'][$cursor];
        $batchInventoryIds[] = $state['candidateInventoryIds'][$cursor];
        $cursor++;
        $processedThisTick++;
    }

    if (!empty($batchInventoryIds)) {
        $placeholders = implode(',', array_fill(0, count($batchInventoryIds), '?'));

        $partsStmt = $pdo->prepare(
            "SELECT ip.inventory_id, ip.part_id, ip.color_id AS color_id_raw, c.id AS color_id_surrogate, SUM(ip.quantity) AS qty
             FROM inventory_parts ip
             LEFT JOIN colors c ON c.color_id = ip.color_id
             WHERE ip.inventory_id IN ($placeholders) AND ip.is_spare = 0
             GROUP BY ip.inventory_id, ip.part_id, ip.color_id, c.id"
        );
        $partsStmt->execute($batchInventoryIds);
        $partsByInventory = [];
        $pairsForSetCounts = [];
        foreach ($partsStmt->fetchAll() as $row) {
            $partsByInventory[(int) $row['inventory_id']][] = $row;
            if ($row['color_id_raw'] !== null) {
                $pairsForSetCounts[$row['part_id'] . ':' . $row['color_id_raw']] = [
                    'part_id' => (int) $row['part_id'],
                    'color_id' => (int) $row['color_id_raw'],
                ];
            }
        }
        $setCounts = getPartSetCounts($pdo, array_values($pairsForSetCounts));

        $minifigsStmt = $pdo->prepare(
            "SELECT inventory_id, minifig_id, quantity FROM inventory_minifigs WHERE inventory_id IN ($placeholders)"
        );
        $minifigsStmt->execute($batchInventoryIds);
        $minifigsByInventory = [];
        foreach ($minifigsStmt->fetchAll() as $row) {
            $minifigsByInventory[(int) $row['inventory_id']][] = $row;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO buildable_sets_cache_staging
             (set_id, total_nominal, total_actual, exclusive_nominal, exclusive_actual, rare_nominal, rare_actual, minifig_nominal, minifig_actual)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               total_nominal = VALUES(total_nominal), total_actual = VALUES(total_actual),
               exclusive_nominal = VALUES(exclusive_nominal), exclusive_actual = VALUES(exclusive_actual),
               rare_nominal = VALUES(rare_nominal), rare_actual = VALUES(rare_actual),
               minifig_nominal = VALUES(minifig_nominal), minifig_actual = VALUES(minifig_actual)'
        );

        foreach ($batchSetIds as $i => $setId) {
            $invId = $batchInventoryIds[$i];
            $totalNominal = 0;
            $totalActual = 0;
            $exclusiveNominal = 0;
            $exclusiveActual = 0;
            $rareNominal = 0;
            $rareActual = 0;

            foreach ($partsByInventory[$invId] ?? [] as $row) {
                if (isset($stickerPartIds[(int) $row['part_id']])) {
                    continue;
                }
                $need = (int) $row['qty'];
                $have = min($need, $stock[$row['part_id'] . ':' . $row['color_id_surrogate']] ?? 0);
                $totalNominal += $need;
                $totalActual += $have;

                $setCount = $row['color_id_raw'] !== null
                    ? ($setCounts[$row['part_id'] . ':' . $row['color_id_raw']] ?? 0)
                    : 0;
                if ($setCount === 1) {
                    $exclusiveNominal += $need;
                    $exclusiveActual += $have;
                } elseif ($setCount >= 2 && $setCount <= 3) {
                    $rareNominal += $need;
                    $rareActual += $have;
                }
            }

            $minifigNominal = 0;
            $minifigActual = 0;
            foreach ($minifigsByInventory[$invId] ?? [] as $row) {
                $need = (int) $row['quantity'];
                $have = min($need, $minifigAvailability[(int) $row['minifig_id']] ?? 0);
                $minifigNominal += $need;
                $minifigActual += $have;
            }

            $insertStmt->execute([
                $setId, $totalNominal, $totalActual, $exclusiveNominal, $exclusiveActual,
                $rareNominal, $rareActual, $minifigNominal, $minifigActual,
            ]);
        }
    }

    $state['cursor'] = $cursor;
    $done = $cursor >= $total;

    if ($done) {
        $pdo->exec('TRUNCATE TABLE buildable_sets_cache');
        $pdo->exec('INSERT INTO buildable_sets_cache SELECT * FROM buildable_sets_cache_staging');
        $pdo->exec('TRUNCATE TABLE buildable_sets_cache_staging');
        setAppSetting(BUILD_SETS_CACHE_COMPUTED_AT_KEY, date('Y-m-d H:i:s'));
        setAppSetting(BUILD_SETS_CACHE_SCOPE_KEY, json_encode($state['scope'], JSON_UNESCAPED_UNICODE));
        setAppSetting(BUILD_SETS_CACHE_STALE_KEY, '0');
    }

    return ['processed' => $cursor, 'total' => $total, 'done' => $done];
}

/**
 * The dark progress overlay shown while a scan runs — same
 * .modal-overlay/.modal-box/.progress-track/.progress-fill markup as
 * renderRebrickableUpdateModal() (src/download.php), which already IS a
 * full-viewport dark overlay with a progress bar, just auto-opened
 * (`display:flex` from the start, no separate trigger button — this
 * function's whole page has nothing else worth showing while a scan runs)
 * and redirecting to the results view instead of just closing once done.
 * $exclusiveOnly/$exclusiveRareOnly ride along purely so the redirect can
 * restore them on the results page — they're display filters, not part of
 * what gets scanned.
 */
function renderBuildSetsScanOverlay(?int $themeId, ?int $yearFrom, ?int $yearTo, bool $exclusiveOnly, bool $exclusiveRareOnly): string
{
    $html = '<div class="modal-overlay" id="build-sets-scan-overlay" style="display:flex;">';
    $html .= '<div class="modal-box">';
    $html .= '<h2>' . htmlspecialchars(t('build_sets_scan_heading')) . '</h2>';
    $html .= '<div class="import-status">';
    $html .= '<div class="progress-message" id="build-sets-scan-message">' . htmlspecialchars(t('import_not_started')) . '</div>';
    $html .= '<div class="progress-track" id="build-sets-scan-progress"><div class="progress-fill"></div></div>';
    $html .= '</div>';
    $html .= '</div></div>';

    $doneUrl = '?page=build_sets'
        . ($exclusiveOnly ? '&exclusive_only=1' : '')
        . ($exclusiveRareOnly ? '&exclusive_rare_only=1' : '');

    $payload = json_encode([
        'theme' => $themeId !== null ? (string) $themeId : '',
        'yearFrom' => $yearFrom !== null ? (string) $yearFrom : '',
        'yearTo' => $yearTo !== null ? (string) $yearTo : '',
        'doneUrl' => $doneUrl,
        'statusLabel' => t('build_sets_scan_status'),
        'errorLabel' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var cfg = {$payload};
  var message = document.getElementById('build-sets-scan-message');
  var track = document.getElementById('build-sets-scan-progress');
  var fill = track ? track.querySelector('.progress-fill') : null;
  if (!message || !track || !fill) {
    return;
  }

  function formatStatus(processed, total) {
    return cfg.statusLabel.replace('{processed}', String(processed)).replace('{total}', String(total));
  }

  function tick() {
    var formData = new FormData();
    formData.set('action', 'build_sets_scan_tick');
    formData.set('theme', cfg.theme);
    formData.set('year_from', cfg.yearFrom);
    formData.set('year_to', cfg.yearTo);
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.done) {
          message.textContent = formatStatus(data.total, data.total);
          fill.style.width = '100%';
          window.location.href = cfg.doneUrl;
          return;
        }
        message.textContent = formatStatus(data.processed, data.total);
        fill.style.width = (data.percent || 0) + '%';
        tick();
      })
      .catch(function() {
        message.textContent = cfg.errorLabel;
        setTimeout(tick, 2000);
      });
  }

  tick();
})();
</script>
SCRIPT;

    return $html;
}

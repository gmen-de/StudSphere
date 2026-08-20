<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/settings.php';

/**
 * "Baubare Minifiguren" (?page=build_minifigs, src/routes/pages.php) — for
 * every catalog minifig, how many complete copies could be assembled from
 * current loose parts stock right now. Used to be a live per-request
 * computation (a two-phase SQL+PHP scan, assumed — per this function's own
 * former doc comment — to stay under ~1s against "a handful of hundred"
 * candidates out of the ~80k minifig-inventory rows). That assumption broke
 * down as the collection's loose stock grew: more minifigs now pass the
 * cheap "own at least one of everything" prefilter than originally measured,
 * ballooning the expensive per-candidate getSetPartsList() loop and hanging
 * the whole page (confirmed live: a 30s+ request with no response at all).
 *
 * Same fix "Baubare Sets" already needed for the identical reason
 * (buildable_sets_cache/_staging, src/build_sets.php) — a tick-based scan
 * into buildable_minifigs_cache, read back here without ever recomputing
 * live. Only the two numbers that actually require the expensive per-
 * candidate work (buildable, missing) are cached; everything else the page
 * needs (name/thumbnail/price, theme/year facets) is joined/derived fresh at
 * read time, same as buildable_sets_cache not storing display info either.
 *
 * @return array<int, array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, buildable:int, missing:int, theme_ids:int[], theme_path:string, year:?int, bricklink_price_used:?float, bricklink_price_currency:?string, bricklink_price_checked_at:?string}>
 */
function getBuildableMinifigsResults(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT bmc.minifig_id, bmc.buildable, bmc.missing,
                m.fig_num, m.name, m.local_image_path AS thumbnail,
                m.bricklink_price_used, m.bricklink_price_currency, m.bricklink_price_checked_at
         FROM buildable_minifigs_cache bmc
         INNER JOIN minifigs m ON m.id = bmc.minifig_id'
    )->fetchAll();

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'minifig_id' => (int) $row['minifig_id'],
            'fig_num' => $row['fig_num'],
            'name' => $row['name'],
            'thumbnail' => $row['thumbnail'],
            'buildable' => (int) $row['buildable'],
            'missing' => (int) $row['missing'],
            'bricklink_price_used' => $row['bricklink_price_used'] !== null ? (float) $row['bricklink_price_used'] : null,
            'bricklink_price_currency' => $row['bricklink_price_currency'],
            'bricklink_price_checked_at' => $row['bricklink_price_checked_at'],
        ];
    }

    // Same theme/year facet lookup and price/missing sort as the original
    // live version — this part was never the bottleneck (already batched
    // into one query for the whole candidate list).
    $facets = getMinifigCatalogFacetsMap($pdo, array_column($results, 'minifig_id'));
    foreach ($results as &$result) {
        $facet = $facets[$result['minifig_id']] ?? ['theme_ids' => [], 'theme_path' => '', 'year' => null];
        $result['theme_ids'] = $facet['theme_ids'];
        $result['theme_path'] = $facet['theme_path'];
        $result['year'] = $facet['year'];
    }
    unset($result);

    usort($results, function (array $a, array $b): int {
        // Unpriced rows (bricklink_price_used === null) always sort last,
        // regardless of which side of the comparison they're on — treated
        // as -INF rather than 0, so a real (if small) price still outranks
        // "unknown".
        $priceA = $a['bricklink_price_used'] ?? -INF;
        $priceB = $b['bricklink_price_used'] ?? -INF;
        return $priceB <=> $priceA ?: $a['missing'] <=> $b['missing'];
    });

    return $results;
}

const BUILD_MINIFIGS_SCAN_BATCH_SIZE = 150;
const BUILD_MINIFIGS_SCAN_TIME_BUDGET_SECONDS = 4.0;

const BUILD_MINIFIGS_CACHE_COMPUTED_AT_KEY = 'buildable_minifigs_cache_computed_at';
const BUILD_MINIFIGS_CACHE_STALE_KEY = 'buildable_minifigs_cache_stale';

/**
 * Same hook-point convention as markBuildableSetsCacheStale()
 * (src/build_sets.php), called from the same refreshAppStatsCache()
 * (src/stats.php) after every storage_items-affecting write. Only flips the
 * flag if a cache already exists — a fresh install with nothing scanned yet
 * has nothing to mark stale.
 */
function markBuildableMinifigsCacheStale(): void
{
    if (getAppSetting(BUILD_MINIFIGS_CACHE_COMPUTED_AT_KEY) !== null) {
        setAppSetting(BUILD_MINIFIGS_CACHE_STALE_KEY, '1');
    }
}

/**
 * @return array{computedAt: ?string, stale: bool}
 */
function getBuildableMinifigsCacheMeta(): array
{
    return [
        'computedAt' => getAppSetting(BUILD_MINIFIGS_CACHE_COMPUTED_AT_KEY),
        'stale' => getAppSetting(BUILD_MINIFIGS_CACHE_STALE_KEY) === '1',
    ];
}

/**
 * One-time setup for a scan — the same cheap SQL prefilter the old live
 * function used (every non-spare required part+color has *some* stock,
 * prunes the ~80k minifig-inventory rows down to real candidates before any
 * per-figure work starts) plus the loose-stock map, both computed once and
 * reused by every tick.
 *
 * @return array the scan state, stored in $_SESSION by the caller
 */
function initBuildMinifigsScanState(PDO $pdo): array
{
    $stock = getLooseStockMap($pdo);

    // fig_num = the minifig's own pseudo "set number" for its constituent-
    // parts inventory (see getMinifigInventoryId()'s doc comment) — the
    // INNER JOIN against minifigs.fig_num already scopes this to minifig
    // inventories only, a real Rebrickable set number can never coincide
    // with a "fig-NNNNNN" string.
    $candidateStmt = $pdo->query(
        "SELECT ri.set_num AS fig_num, m.id AS minifig_id,
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
         WHERE ip.is_spare = 0
         GROUP BY ri.set_num, m.id
         HAVING total_pairs = matched_pairs"
    );

    $candidates = [];
    foreach ($candidateStmt->fetchAll() as $row) {
        $candidates[] = ['minifig_id' => (int) $row['minifig_id'], 'fig_num' => $row['fig_num']];
    }

    $pdo->exec('TRUNCATE TABLE buildable_minifigs_cache_staging');

    return [
        'candidates' => $candidates,
        'cursor' => 0,
        'total' => count($candidates),
        'stock' => $stock,
    ];
}

/**
 * One bounded tick — same batch-size/time-budget/staging-table pattern as
 * stepBuildSetsScan() (src/build_sets.php). Resolves inventory ids and
 * fetches parts for the whole batch of candidates in two queries (not one
 * getMinifigInventoryId()+getSetPartsList() pair per candidate like the old
 * live version did) — that per-candidate N+1 query pattern was the actual
 * mechanism behind the page hanging, so the scan can't just replay it on a
 * slower schedule.
 *
 * @return array{processed:int, total:int, done:bool}
 */
function stepBuildMinifigsScan(PDO $pdo, array &$state): array
{
    $startedAt = microtime(true);
    $cursor = $state['cursor'];
    $total = $state['total'];
    $stock = $state['stock'];

    $batch = [];
    $processedThisTick = 0;
    while (
        $cursor < $total
        && $processedThisTick < BUILD_MINIFIGS_SCAN_BATCH_SIZE
        && (microtime(true) - $startedAt) < BUILD_MINIFIGS_SCAN_TIME_BUDGET_SECONDS
    ) {
        $batch[] = $state['candidates'][$cursor];
        $cursor++;
        $processedThisTick++;
    }

    if (!empty($batch)) {
        $figNums = array_column($batch, 'fig_num');
        $figPlaceholders = implode(',', array_fill(0, count($figNums), '?'));

        // Latest inventory version per fig_num, batched — same "latest
        // version wins" join stepBuildSetsScan() uses for sets.
        $invStmt = $pdo->prepare(
            "SELECT ri.set_num, ri.inventory_id
             FROM rebrickable_inventories ri
             INNER JOIN (
                 SELECT set_num, MAX(version) AS max_version
                 FROM rebrickable_inventories
                 WHERE set_num IN ($figPlaceholders)
                 GROUP BY set_num
             ) latest ON latest.set_num = ri.set_num AND latest.max_version = ri.version"
        );
        $invStmt->execute($figNums);
        $inventoryIdByFigNum = [];
        foreach ($invStmt->fetchAll() as $row) {
            $inventoryIdByFigNum[$row['set_num']] = (int) $row['inventory_id'];
        }

        $inventoryIds = array_values($inventoryIdByFigNum);
        $partsByInventory = [];
        if (!empty($inventoryIds)) {
            $invPlaceholders = implode(',', array_fill(0, count($inventoryIds), '?'));
            $partsStmt = $pdo->prepare(
                "SELECT ip.inventory_id, ip.part_id, c.id AS color_id, SUM(ip.quantity) AS qty
                 FROM inventory_parts ip
                 LEFT JOIN colors c ON c.color_id = ip.color_id
                 WHERE ip.inventory_id IN ($invPlaceholders) AND ip.is_spare = 0
                 GROUP BY ip.inventory_id, ip.part_id, c.id"
            );
            $partsStmt->execute($inventoryIds);
            foreach ($partsStmt->fetchAll() as $row) {
                $partsByInventory[(int) $row['inventory_id']][] = $row;
            }
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO buildable_minifigs_cache_staging (minifig_id, buildable, missing)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE buildable = VALUES(buildable), missing = VALUES(missing)'
        );

        foreach ($batch as $candidate) {
            $inventoryId = $inventoryIdByFigNum[$candidate['fig_num']] ?? null;
            $parts = $inventoryId !== null ? ($partsByInventory[$inventoryId] ?? []) : [];

            $buildable = null;
            $missing = 0;
            foreach ($parts as $part) {
                $need = (int) $part['qty'];
                if ($need <= 0) {
                    continue;
                }
                $have = $stock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
                $ratio = intdiv($have, $need);
                $buildable = $buildable === null ? $ratio : min($buildable, $ratio);
                $missing += max(0, $need - $have);
            }
            $buildable ??= 0;

            $insertStmt->execute([$candidate['minifig_id'], $buildable, $missing]);
        }
    }

    $state['cursor'] = $cursor;
    $done = $cursor >= $total;

    if ($done) {
        $pdo->exec('TRUNCATE TABLE buildable_minifigs_cache');
        $pdo->exec('INSERT INTO buildable_minifigs_cache SELECT * FROM buildable_minifigs_cache_staging');
        $pdo->exec('TRUNCATE TABLE buildable_minifigs_cache_staging');
        setAppSetting(BUILD_MINIFIGS_CACHE_COMPUTED_AT_KEY, date('Y-m-d H:i:s'));
        setAppSetting(BUILD_MINIFIGS_CACHE_STALE_KEY, '0');
    }

    return ['processed' => $cursor, 'total' => $total, 'done' => $done];
}

/**
 * The dark progress overlay shown while a scan runs — same markup/script
 * shape as renderBuildSetsScanOverlay() (src/build_sets.php), just against
 * action=build_minifigs_scan_tick and no scope/filter params to carry along
 * (this scan is always the whole catalog, unlike build_sets' optional
 * theme/year scope).
 */
function renderBuildMinifigsScanOverlay(): string
{
    $html = '<div class="modal-overlay" id="build-minifigs-scan-overlay" style="display:flex;">';
    $html .= '<div class="modal-box">';
    $html .= '<h2>' . htmlspecialchars(t('build_minifigs_scan_heading')) . '</h2>';
    $html .= '<div class="import-status">';
    $html .= '<div class="progress-message" id="build-minifigs-scan-message">' . htmlspecialchars(t('import_not_started')) . '</div>';
    $html .= '<div class="progress-track" id="build-minifigs-scan-progress"><div class="progress-fill"></div></div>';
    $html .= '</div>';
    $html .= '</div></div>';

    $payload = json_encode([
        'doneUrl' => '?page=build_minifigs',
        'statusLabel' => t('build_minifigs_scan_status'),
        'errorLabel' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var cfg = {$payload};
  var message = document.getElementById('build-minifigs-scan-message');
  var track = document.getElementById('build-minifigs-scan-progress');
  var fill = track ? track.querySelector('.progress-fill') : null;
  if (!message || !track || !fill) {
    return;
  }

  function formatStatus(processed, total) {
    return cfg.statusLabel.replace('{processed}', String(processed)).replace('{total}', String(total));
  }

  function tick() {
    var formData = new FormData();
    formData.set('action', 'build_minifigs_scan_tick');
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

/**
 * Full data for the "Bauen" modal (renderBuildMinifigModal() below) for one
 * specific catalog minifig — image/name/price (getMinifigById(),
 * src/minifigs.php) plus its own constituent parts (getSetPartsList(),
 * src/sets.php) each paired with current loose stock (getPartStock(),
 * src/storage.php, filtered to the matching color) and the locations that
 * stock sits at. $have nets out damaged stock and ignores condition_type
 * (same convention getLooseStockMap()-based stock maps elsewhere in this
 * file already use) — a part's own color is what matters here, not the
 * built figure's condition.
 *
 * @return array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, bricklink_price_used:?float, bricklink_price_currency:?string, parts: array<int, array{part_id:int, color_id:?int, name:string, color_name:?string, thumbnail:?string, quantity_per_unit:int, have:int, locations: array<int, array{location_path:string, quantity:int, condition_type:string}>}>}|null
 */
function getBuildableMinifigDetail(PDO $pdo, int $minifigId, string $locale = 'en'): ?array
{
    $minifig = getMinifigById($pdo, $minifigId);
    if ($minifig === null) {
        return null;
    }
    $inventoryId = getMinifigInventoryId($pdo, $minifig['fig_num']);
    $boms = $inventoryId !== null ? getSetPartsList($pdo, $inventoryId, false, $locale) : [];

    $stockCache = [];
    $parts = [];
    foreach ($boms as $item) {
        if (!isset($stockCache[$item['part_id']])) {
            $stockCache[$item['part_id']] = getPartStock($item['part_id']);
        }

        $locations = [];
        $have = 0;
        foreach ($stockCache[$item['part_id']] as $stockRow) {
            if ($stockRow['color_id'] !== $item['color_id']) {
                continue;
            }
            $usable = $stockRow['quantity'] - $stockRow['damaged_quantity'];
            if ($usable <= 0) {
                continue;
            }
            $have += $usable;
            $locations[] = [
                'location_path' => $stockRow['location_path'],
                'quantity' => $usable,
                'condition_type' => $stockRow['condition_type'],
            ];
        }

        $parts[] = [
            'part_id' => $item['part_id'],
            'color_id' => $item['color_id'],
            'name' => $item['name'],
            'color_name' => $item['color_name'],
            'thumbnail' => $item['ldraw_thumbnail'] ?? $item['thumbnail'] ?? $item['remote_thumbnail'] ?? null,
            'quantity_per_unit' => $item['quantity'],
            'have' => $have,
            'locations' => $locations,
        ];
    }

    return [
        'minifig_id' => $minifig['id'],
        'fig_num' => $minifig['fig_num'],
        'name' => $minifig['name'],
        'thumbnail' => $minifig['thumbnail'],
        'bricklink_price_used' => $minifig['bricklink_price_used'],
        'bricklink_price_currency' => $minifig['bricklink_price_currency'],
        'parts' => $parts,
    ];
}

/**
 * Consumes loose parts stock and creates $quantity new minifig instances at
 * $destinationLocationId — the "Bauen" modal's submit action. Re-validates
 * every part's stock against the database right before touching anything
 * (never trusts the client's own snapshot, which could be stale by the time
 * the user submits): if even one part is short, throws and changes nothing.
 *
 * Consumption order per part is by location_id ascending (deterministic,
 * simplest reasonable choice — this app has no per-bin "prefer this
 * location first" concept anywhere else either), only ever eating into the
 * intact (quantity - damaged_quantity) portion of a row via
 * setStorageItemQuantity() (src/storage.php, which also writes the
 * corresponding storage_movements audit rows). No enclosing database
 * transaction across the whole operation: every reused function
 * (setStorageItemQuantity(), addMinifigStock()) already commits its own,
 * and nesting PDO transactions isn't straightforward — consistent with the
 * rest of the app, which relies on the same per-function-transaction
 * pattern rather than one app-wide lock. The fresh re-check immediately
 * before consuming anything keeps the inconsistency window as small as
 * practical for a single-user app.
 *
 * @throws RuntimeException if stock is insufficient for any part, or the
 *         minifig/destination is invalid
 * @return array{createdInstanceIds: int[]}
 */
function buildMinifigFromStock(PDO $pdo, int $minifigId, int $quantity, string $conditionType, int $destinationLocationId): array
{
    if ($quantity <= 0) {
        throw new RuntimeException(t('build_minifig_invalid_quantity'));
    }
    $detail = getBuildableMinifigDetail($pdo, $minifigId, 'en');
    if ($detail === null) {
        throw new RuntimeException(t('minifig_not_found'));
    }

    // Full re-check against fresh DB rows (getPartStock() again, not the
    // $detail fetched above) before consuming anything. A null color_id
    // (a colorless/"any color" BOM requirement — rare for minifig parts,
    // which are almost always printed/molded in one specific color, but
    // storage_items.color_id is nullable) can never be satisfied through
    // this per-color storage system, so it's treated as always short
    // rather than risking a type error passing null into
    // setStorageItemQuantity()'s non-nullable $colorId further down.
    foreach ($detail['parts'] as $part) {
        $needed = $part['quantity_per_unit'] * $quantity;
        if ($needed <= 0) {
            continue;
        }
        if ($part['color_id'] === null) {
            throw new RuntimeException(t('build_minifig_insufficient_stock', ['name' => $part['name']]));
        }
        $freshRows = array_filter(getPartStock($part['part_id']), fn (array $r): bool => $r['color_id'] === $part['color_id']);
        $available = array_sum(array_map(fn (array $r): int => $r['quantity'] - $r['damaged_quantity'], $freshRows));
        if ($available < $needed) {
            throw new RuntimeException(t('build_minifig_insufficient_stock', ['name' => $part['name']]));
        }
    }

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    foreach ($detail['parts'] as $part) {
        $needed = $part['quantity_per_unit'] * $quantity;
        if ($needed <= 0) {
            continue;
        }
        $freshRows = array_filter(getPartStock($part['part_id']), fn (array $r): bool => $r['color_id'] === $part['color_id']);
        usort($freshRows, fn (array $a, array $b): int => $a['location_id'] <=> $b['location_id']);
        foreach ($freshRows as $row) {
            if ($needed <= 0) {
                break;
            }
            $usable = $row['quantity'] - $row['damaged_quantity'];
            if ($usable <= 0) {
                continue;
            }
            $consume = min($usable, $needed);
            setStorageItemQuantity($row['location_id'], $part['part_id'], $part['color_id'], $row['condition_type'], $row['quantity'] - $consume, $userId, $row['damaged_quantity']);
            $needed -= $consume;
        }
    }

    $createdInstanceIds = addMinifigStock($destinationLocationId, $minifigId, $conditionType, $quantity);

    return ['createdInstanceIds' => $createdInstanceIds];
}

/**
 * The "Bauen" modal — static skeleton (this page's only modal, no id
 * collision risk), content built entirely client-side once
 * window.openBuildMinifigModal(minifigId) fetches action=build_minifig_detail.
 * Reuses .owned-set-inventory-tile-complete/-missing (src/owned_sets.php's
 * status border colors) for the per-part availability tiles rather than
 * renderOwnedSetInventoryTile() itself — that one is click-to-edit (opens a
 * quantity modal), which doesn't belong here: nothing in this grid is
 * individually editable, it's read-only status feeding into one "how many
 * to build" decision. Tile borders are recalculated in JS on every
 * quantity-field change (all the data — have/need per part — is already in
 * the fetched JSON, no server round-trip needed for that).
 */
function renderBuildMinifigModal(): string
{
    $html = '<div class="modal-overlay" id="build-minifig-modal" style="display:none;">';
    $html .= '<div class="modal-box"><button type="button" class="modal-close" id="build-minifig-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<div id="build-minifig-modal-content"></div>';
    $html .= '</div></div>';

    $labelsJson = json_encode([
        'loading' => t('build_minifig_loading'),
        'notFound' => t('minifig_not_found'),
        'errorRetry' => t('import_error_retry'),
        'minifigIcon' => getNavIcon('minifigs'),
        'brickIcon' => getNavIcon('bricks'),
        'priceLabel' => t('my_minifigs_top100_price_column'),
        'priceUnknown' => t('build_minifigs_price_unknown'),
        'partsHeading' => t('build_minifig_parts_heading'),
        'quantityLabel' => t('build_minifig_quantity_label'),
        'conditionLabel' => t('build_minifig_condition_label'),
        'conditionNew' => t('condition_new'),
        'conditionUsed' => t('condition_used'),
        'destinationLabel' => t('build_minifig_destination_label'),
        'levelLabel' => t('location_picker_level_label'),
        'rootLabel' => t('location_picker_root_label'),
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'locationRequired' => t('owned_set_wizard_location_required'),
        'submitButton' => t('build_minifig_submit_button'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = {$labelsJson};
  var modal = document.getElementById('build-minifig-modal');
  var content = document.getElementById('build-minifig-modal-content');
  var closeBtn = document.getElementById('build-minifig-modal-close');
  if (!modal || !content || !closeBtn) {
    return;
  }

  function closeModal() {
    modal.style.display = 'none';
    content.innerHTML = '';
  }
  closeBtn.addEventListener('click', closeModal);

  function renderModal(data) {
    content.innerHTML = '';

    var img = document.createElement('span');
    img.className = 'set-detail-image build-minifig-image';
    img.innerHTML = data.thumbnail ? '<img src="' + data.thumbnail + '" alt="">' : texts.minifigIcon;
    content.appendChild(img);

    var title = document.createElement('h2');
    title.textContent = data.name || data.fig_num;
    content.appendChild(title);

    var priceP = document.createElement('p');
    priceP.className = 'hint';
    priceP.textContent = texts.priceLabel + ': ' + (data.price_text || texts.priceUnknown);
    content.appendChild(priceP);

    var partsHeading = document.createElement('h3');
    partsHeading.textContent = texts.partsHeading;
    content.appendChild(partsHeading);

    var grid = document.createElement('div');
    grid.className = 'parts-grid';
    var tiles = [];
    (data.parts || []).forEach(function(part) {
      var tile = document.createElement('div');
      tile.className = 'owned-set-inventory-tile';

      var thumb = document.createElement('span');
      thumb.className = 'part-card-image';
      thumb.innerHTML = part.thumbnail ? '<img src="' + part.thumbnail + '" alt="">' : texts.brickIcon;
      tile.appendChild(thumb);

      var name = document.createElement('span');
      name.className = 'part-card-name';
      name.textContent = part.name + (part.color_name ? ' (' + part.color_name + ')' : '');
      tile.appendChild(name);

      var summary = document.createElement('p');
      summary.className = 'owned-set-inventory-summary';
      tile.appendChild(summary);

      if (part.locations && part.locations.length > 0) {
        var locP = document.createElement('p');
        locP.className = 'hint';
        locP.textContent = part.locations.map(function(l) {
          return l.location_path + ' (' + l.quantity + 'x)';
        }).join(', ');
        tile.appendChild(locP);
      }

      grid.appendChild(tile);
      tiles.push({ el: tile, summary: summary, have: part.have, need: part.quantity_per_unit });
    });
    content.appendChild(grid);

    var form = document.createElement('form');
    form.className = 'add-stock-form';

    var qtyLabel = document.createElement('label');
    qtyLabel.textContent = texts.quantityLabel;
    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.min = '1';
    qtyInput.value = '1';
    qtyLabel.appendChild(qtyInput);
    form.appendChild(qtyLabel);

    function updateTiles() {
      var qty = parseInt(qtyInput.value, 10) || 0;
      tiles.forEach(function(t) {
        var needed = t.need * qty;
        var ok = t.have >= needed;
        t.el.classList.toggle('owned-set-inventory-tile-complete', ok);
        t.el.classList.toggle('owned-set-inventory-tile-missing', !ok);
        t.summary.textContent = t.have + ' / ' + needed;
      });
    }
    qtyInput.addEventListener('input', updateTiles);
    updateTiles();

    var condLabel = document.createElement('label');
    condLabel.textContent = texts.conditionLabel;
    var condSelect = document.createElement('select');
    var optUsed = document.createElement('option');
    optUsed.value = 'used';
    optUsed.textContent = texts.conditionUsed;
    optUsed.selected = true;
    var optNew = document.createElement('option');
    optNew.value = 'new';
    optNew.textContent = texts.conditionNew;
    condSelect.appendChild(optUsed);
    condSelect.appendChild(optNew);
    condLabel.appendChild(condSelect);
    form.appendChild(condLabel);

    var destLabel = document.createElement('p');
    destLabel.textContent = texts.destinationLabel;
    form.appendChild(destLabel);
    var locationContainer = document.createElement('div');
    locationContainer.className = 'location-picker';
    form.appendChild(locationContainer);
    var selectedLocationId = null;
    window.createLocationPicker(locationContainer, texts, function(value) {
      selectedLocationId = value;
    });

    var msgBox = document.createElement('div');
    msgBox.className = 'add-stock-message';
    form.appendChild(msgBox);

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.textContent = texts.submitButton;
    form.appendChild(submitBtn);

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      msgBox.textContent = '';
      if (!selectedLocationId) {
        msgBox.textContent = texts.locationRequired;
        return;
      }
      submitBtn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'build_minifig');
      formData.set('minifig_id', data.minifig_id);
      formData.set('quantity', qtyInput.value);
      formData.set('condition_type', condSelect.value);
      formData.set('destination_location_id', selectedLocationId);
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            window.location.reload();
            return;
          }
          submitBtn.disabled = false;
          msgBox.textContent = res.message || texts.errorRetry;
        })
        .catch(function() {
          submitBtn.disabled = false;
          msgBox.textContent = texts.errorRetry;
        });
    });

    content.appendChild(form);
  }

  window.openBuildMinifigModal = function(minifigId) {
    modal.style.display = 'flex';
    content.innerHTML = '<p class="hint">' + texts.loading + '</p>';

    fetch('?action=build_minifig_detail&minifig_id=' + encodeURIComponent(minifigId), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.minifig_id) {
          content.innerHTML = '<p class="hint">' + texts.notFound + '</p>';
          return;
        }
        renderModal(data);
      })
      .catch(function() {
        content.innerHTML = '<p class="hint">' + texts.errorRetry + '</p>';
      });
  };

  document.addEventListener('click', function(e) {
    var row = e.target.closest('.build-minifig-row');
    if (row) {
      window.openBuildMinifigModal(row.dataset.minifigId);
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var row = e.target.closest('.build-minifig-row');
    if (row) {
      e.preventDefault();
      window.openBuildMinifigModal(row.dataset.minifigId);
    }
  });
})();
</script>
SCRIPT;

    return $html;
}

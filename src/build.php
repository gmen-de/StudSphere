<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';
require_once __DIR__ . '/storage.php';

/**
 * Catalog minifigs the user could assemble from loose parts stock
 * (storage_items — ordinary bricks and loose minifig parts alike, both live
 * in the same table, see storage_items' own doc comment) — powers the
 * "Bauen" nav dropdown's "Baubare Minifiguren" entry.
 *
 * Two-phase to stay fast against the whole catalog (~80k minifig-inventory
 * rows as of this writing, confirmed via a live timed test — a single
 * candidate query plus a handful of getSetPartsList() calls finishes in
 * ~1s, well inside shared-hosting request timeouts):
 *  1. One SQL query finds candidates whose every non-spare required part+
 *     color has *some* stock (not necessarily enough) — a cheap necessary
 *     condition for buildable > 0 that prunes the ~80k rows down to a
 *     handful of hundred real candidates before any per-figure work starts.
 *  2. Only for those candidates: getSetPartsList() (src/sets.php, same
 *     function action=minifig_detail already uses for a minifig's own BOM)
 *     gives the exact per-part quantities, compared against a stock map
 *     built once upfront — no per-candidate stock query.
 *
 * Stock counts storage_items.quantity minus damaged_quantity (only intact
 * stock is usable) and deliberately excludes spare_quantity (spares aren't
 * "free" stock, same convention getOwnedSetCompleteness() already uses).
 *
 * $buildable = how many complete copies are assembleable right now (the
 * bottleneck part's floor(stock / needed-per-copy) across every required
 * part). $missing = total individual pieces still short of a single first
 * copy (sum of max(0, needed - have) per part) — deliberately NOT "missing
 * for one more beyond what's already buildable": that alternative can
 * mathematically never read 0 (there's always something short of an
 * *additional* copy), which misleadingly hid the real answer to "is there
 * a figure I already have everything for" — yes, whenever $missing is 0,
 * exactly the same condition as $buildable >= 1.
 *
 * Sorted by BrickLink price (bricklink_price_used — a hand-assembled figure
 * realistically compares to the used market, not factory-sealed "new")
 * descending, unpriced ones last, $missing ascending as tiebreak.
 * $buildable is returned but deliberately not part of the sort — the user
 * wants value first, buildability is just supporting info per row.
 *
 * @return array<int, array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, buildable:int, missing:int, theme_path:string, bricklink_price_used:?float, bricklink_price_currency:?string, bricklink_price_checked_at:?string}>
 */
function getBuildableMinifigs(PDO $pdo, string $locale = 'en'): array
{
    $stock = [];
    $stockStmt = $pdo->query(
        'SELECT part_id, color_id, SUM(quantity) - SUM(damaged_quantity) AS stock
         FROM storage_items
         GROUP BY part_id, color_id
         HAVING stock > 0'
    );
    foreach ($stockStmt->fetchAll() as $row) {
        $stock[$row['part_id'] . ':' . $row['color_id']] = (int) $row['stock'];
    }
    if (empty($stock)) {
        return [];
    }

    // fig_num = the minifig's own pseudo "set number" for its constituent-
    // parts inventory (see getMinifigInventoryId()'s doc comment) — the
    // INNER JOIN against minifigs.fig_num already scopes this to minifig
    // inventories only, a real Rebrickable set number can never coincide
    // with a "fig-NNNNNN" string.
    $candidateStmt = $pdo->query(
        "SELECT ri.set_num AS fig_num, m.id AS minifig_id, m.name, m.local_image_path AS thumbnail,
                m.bricklink_price_used, m.bricklink_price_currency, m.bricklink_price_checked_at,
                COUNT(DISTINCT CONCAT(ip.part_id,':',c.id)) AS total_pairs,
                COUNT(DISTINCT CASE WHEN si.part_id IS NOT NULL THEN CONCAT(ip.part_id,':',c.id) END) AS matched_pairs
         FROM inventory_parts ip
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = ip.inventory_id
         INNER JOIN minifigs m ON m.fig_num = ri.set_num
         LEFT JOIN colors c ON c.color_id = ip.color_id
         LEFT JOIN (SELECT DISTINCT part_id, color_id FROM storage_items WHERE quantity > 0) si
                ON si.part_id = ip.part_id AND si.color_id = c.id
         WHERE ip.is_spare = 0
         GROUP BY ri.set_num, m.id, m.name, m.local_image_path,
                  m.bricklink_price_used, m.bricklink_price_currency, m.bricklink_price_checked_at
         HAVING total_pairs = matched_pairs"
    );

    $results = [];
    foreach ($candidateStmt->fetchAll() as $candidate) {
        $inventoryId = getMinifigInventoryId($pdo, $candidate['fig_num']);
        if ($inventoryId === null) {
            continue;
        }
        $parts = getSetPartsList($pdo, $inventoryId, false, $locale);

        $buildable = null;
        foreach ($parts as $part) {
            if ($part['quantity'] <= 0) {
                continue;
            }
            $have = $stock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
            $ratio = intdiv($have, $part['quantity']);
            $buildable = $buildable === null ? $ratio : min($buildable, $ratio);
        }
        $buildable ??= 0;

        $missing = 0;
        foreach ($parts as $part) {
            $have = $stock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
            $missing += max(0, $part['quantity'] - $have);
        }

        $results[] = [
            'minifig_id' => (int) $candidate['minifig_id'],
            'fig_num' => $candidate['fig_num'],
            'name' => $candidate['name'],
            'thumbnail' => $candidate['thumbnail'],
            'buildable' => $buildable,
            'missing' => $missing,
            'bricklink_price_used' => $candidate['bricklink_price_used'] !== null ? (float) $candidate['bricklink_price_used'] : null,
            'bricklink_price_currency' => $candidate['bricklink_price_currency'],
            'bricklink_price_checked_at' => $candidate['bricklink_price_checked_at'],
        ];
    }

    $themePaths = getMinifigThemePathsMap($pdo, array_column($results, 'minifig_id'));
    foreach ($results as &$result) {
        $result['theme_path'] = $themePaths[$result['minifig_id']] ?? '';
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

/**
 * Full data for the "Bauen" modal (renderBuildMinifigModal() below) for one
 * specific catalog minifig — image/name/price (getMinifigById(),
 * src/minifigs.php) plus its own constituent parts (getSetPartsList(),
 * src/sets.php) each paired with current loose stock (getPartStock(),
 * src/storage.php, filtered to the matching color) and the locations that
 * stock sits at. $have nets out damaged stock and ignores condition_type
 * (same convention getBuildableMinifigs()'s own stock map already uses) — a
 * part's own color is what matters here, not the built figure's condition.
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
    if (locationHasNonOwnedSetChildren($destinationLocationId)) {
        throw new RuntimeException(t('add_stock_location_not_leaf'));
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

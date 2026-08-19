<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/rebrickable.php';
require_once __DIR__ . '/images.php';

/**
 * Cached color-correct part image, keyed by Rebrickable's own color_id — the
 * same numbering inventory_parts.color_id uses, NOT colors.id (the surrogate
 * PK storage_items.color_id uses). $angle defaults to 'home' — LDraw's
 * default isometric view and every existing row's angle before the
 * Pickliste's 4-perspective feature (src/pick_lists.php) introduced the
 * others — so every pre-existing call site is unaffected.
 */
function getCachedPartColorImage(PDO $pdo, int $partId, int $rebrickableColorId, string $angle = 'home'): ?string
{
    $stmt = $pdo->prepare('SELECT local_image_path FROM part_color_images WHERE part_id = ? AND color_id = ? AND angle = ?');
    $stmt->execute([$partId, $rebrickableColorId, $angle]);
    $path = $stmt->fetchColumn();
    return $path !== false && $path !== null ? (string) $path : null;
}

/**
 * Rebrickable's own part_num often matches the LDraw parts library's own
 * numbering, but not always — e.g. part "2436" is LDraw "2436a" — confirmed
 * via the API's external_ids.LDraw field. Resolved lazily via one API call
 * per part (GET lego/parts/{part_num}/) and cached on parts.ldraw_id, since
 * the mapping never changes: '' means "resolved, no LDraw mapping exists"
 * (all printed/decorated parts, and some others), stored so we don't repeat
 * the same doomed API call every time; NULL means "not looked up yet".
 */
function resolvePartLdrawId(PDO $pdo, int $partId, string $partNum): ?string
{
    $stmt = $pdo->prepare('SELECT ldraw_id FROM parts WHERE id = ?');
    $stmt->execute([$partId]);
    $cached = $stmt->fetchColumn();
    if ($cached !== false && $cached !== null) {
        return $cached !== '' ? (string) $cached : null;
    }

    $data = callRebrickableApi('lego/parts/' . rawurlencode($partNum) . '/');
    $ldrawId = trim((string) ($data['external_ids']['LDraw'][0] ?? ''));

    $updateStmt = $pdo->prepare('UPDATE parts SET ldraw_id = ? WHERE id = ?');
    $updateStmt->execute([$ldrawId, $partId]);

    return $ldrawId !== '' ? $ldrawId : null;
}

/**
 * Rebrickable serves pre-rendered LDraw part images directly off its CDN,
 * keyed by color_id and LDraw part id — no API call or key needed for the
 * image itself (only for resolvePartLdrawId(), and only once per part).
 * Confirmed against the real CDN:
 * https://cdn.rebrickable.com/media/thumbs/parts/ldraw/{color_id}/{ldraw_id}.png/250x250p.png
 */
function getLdrawImageUrl(string $ldrawId, int $rebrickableColorId): string
{
    return 'https://cdn.rebrickable.com/media/thumbs/parts/ldraw/' . $rebrickableColorId . '/' . rawurlencode($ldrawId) . '.png/250x250p.png';
}

/**
 * Downloads and caches the color-correct LDraw render for one part+color
 * (see getLdrawImageUrl()), using the same on-disk layout as the bulk
 * image-download pipeline (images.php) but its own dedicated table —
 * this is a single on-demand fetch triggered by a user clicking the "fetch
 * color-correct image" button, not a bulk sync.
 *
 * @return string the new local_image_path
 * @throws RuntimeException if this part has no LDraw mapping (most
 *         commonly: printed/decorated parts), Rebrickable has no render for
 *         this exact part+color, or the download otherwise fails
 */
function fetchPartColorImage(PDO $pdo, int $partId, string $partNum, int $rebrickableColorId): string
{
    $ldrawId = resolvePartLdrawId($pdo, $partId, $partNum);
    if ($ldrawId === null) {
        throw new RuntimeException(t('part_color_image_not_available'));
    }

    $remoteUrl = getLdrawImageUrl($ldrawId, $rebrickableColorId);

    // Not sanitizeImageFilename(url) here: this URL's path always ends in
    // the literal segment "250x250p.png" for every part+color, which would
    // collapse every single fetched image onto the same filename.
    $filename = $rebrickableColorId . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $ldrawId) . '.png';
    $shard = getImageShard($filename);
    $dir = getImageStorageDir('part_color_images', $shard);
    $absolutePath = $dir . '/' . $filename;
    $relativePath = getImageRelativePath('part_color_images', $shard, $filename);

    if (!is_file($absolutePath) && !downloadImageFile($remoteUrl, $absolutePath)) {
        throw new RuntimeException(t('part_color_image_not_available'));
    }

    $stmt = $pdo->prepare(
        'INSERT INTO part_color_images (part_id, color_id, local_image_path)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE local_image_path = VALUES(local_image_path), fetched_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$partId, $rebrickableColorId, $relativePath]);

    return $relativePath;
}

/**
 * One button for an entire parts list (a set's inventory/spares grid, or the
 * version-compare table) rather than one per card — cards that still need
 * their color-correct image carry a `data-color-id` attribute (see parts.php's
 * renderPartCard()); this button scans for them within $containerId and
 * fetches each in turn. Renders nothing when $missingCount is 0.
 */
function renderFetchMissingImagesButton(string $containerId, int $missingCount): string
{
    if ($missingCount <= 0) {
        return '';
    }
    $label = t('part_color_images_fetch_missing_button', ['count' => (string) $missingCount]);
    return '<div class="fetch-missing-images-bar">'
        . '<button type="button" class="fetch-missing-images-btn" data-scope="#' . htmlspecialchars($containerId) . '" data-loading-label="' . htmlspecialchars($label) . '" data-done-label="' . htmlspecialchars(t('part_color_images_fetch_done')) . '">'
        . '<span class="fetch-missing-images-label">' . htmlspecialchars($label) . '</span>'
        . '</button>'
        . '<span class="fetch-missing-images-status" aria-live="polite"></span>'
        . '</div>';
}

/**
 * Click handler for .fetch-missing-images-btn (see
 * renderFetchMissingImagesButton()) — delegated on `document` like
 * renderPartDetailModal()'s own open-on-click handler, so it works
 * regardless of which page the button ends up on. Processes one part+color
 * at a time (not in parallel) — gentle on Rebrickable's API/CDN and keeps
 * each individual request small and fast, which matters more than raw
 * throughput here since a set's inventory is at most a few hundred items.
 * Each card's image swaps in live as its own fetch completes, not just at
 * the end. Safe to include multiple times on one page (independent
 * listeners), but callers should still only render it once where possible.
 */
function renderFetchMissingImagesScript(): string
{
    return <<<'SCRIPT'
<script>
(function(){
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fetch-missing-images-btn');
    if (!btn || btn.disabled) {
      return;
    }
    e.preventDefault();

    var container = btn.dataset.scope ? document.querySelector(btn.dataset.scope) : null;
    var targets = container ? Array.prototype.slice.call(container.querySelectorAll('[data-color-id]')) : [];
    if (targets.length === 0) {
      return;
    }

    btn.disabled = true;
    var label = btn.querySelector('.fetch-missing-images-label');
    var status = btn.parentElement ? btn.parentElement.querySelector('.fetch-missing-images-status') : null;
    var total = targets.length;
    var doneCount = 0;
    var failedCount = 0;

    function updateStatus() {
      if (status) {
        status.textContent = doneCount + ' / ' + total;
      }
    }
    updateStatus();

    function processNext(index) {
      if (index >= targets.length) {
        if (label) {
          label.textContent = btn.dataset.doneLabel;
        }
        if (status && failedCount > 0) {
          status.textContent += ' (' + failedCount + ' ✗)';
        }
        return;
      }

      var el = targets[index];
      var formData = new FormData();
      formData.set('action', 'fetch_part_color_image');
      formData.set('part_id', el.dataset.partId);
      formData.set('color_id', el.dataset.colorId);

      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          el.removeAttribute('data-color-id');
          if (res.success) {
            var imageSpan = el.querySelector('.part-card-image');
            if (imageSpan) {
              imageSpan.innerHTML = '<img src="' + res.imagePath + '" alt="">';
            }
          } else {
            failedCount++;
          }
          doneCount++;
          updateStatus();
          processNext(index + 1);
        })
        .catch(function() {
          el.removeAttribute('data-color-id');
          failedCount++;
          doneCount++;
          updateStatus();
          processNext(index + 1);
        });
    }

    processNext(0);
  });
})();
</script>
SCRIPT;
}

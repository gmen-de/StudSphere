<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/minifigs.php';
require_once __DIR__ . '/owned_sets.php';
require_once __DIR__ . '/rebrickable.php';

/**
 * Mirrors owned_sets.php's role, but for loose minifig instances
 * (minifig_storage_items — one row per physical figure since the
 * individual-instance rework, migration 33) instead of owned_sets. Every
 * function here has a named counterpart in owned_sets.php; see each one's
 * own doc comment for exactly what's kept/dropped relative to that mirror —
 * mainly: no exclusive/rare/sticker-sheet categorization, no sealed-box
 * (OVP/Anleitung/Sticker-angebracht) workflow, no Bauanleitung tab. None of
 * those exist for a single loose minifig (confirmed against the live DB:
 * zero minifig inventory rows have is_spare=1 or a Stickers category, and a
 * minifig contains no other minifigs) — see this feature's own plan for the
 * full reasoning.
 */

// ---------------------------------------------------------------------
// Instance CRUD
// ---------------------------------------------------------------------

/**
 * @return array{id:int, minifig_id:int, fig_num:string, name:?string, thumbnail:?string, location_id:int, condition_type:string, notes:?string, bricklink_price_new:?float, bricklink_price_used:?float, bricklink_price_currency:?string, bricklink_price_checked_at:?string}|null
 */
function getOwnedMinifigInstanceById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT msi.id, msi.minifig_id, m.fig_num, m.name, m.local_image_path AS thumbnail,
                msi.location_id, msi.condition_type, msi.notes,
                m.bricklink_price_new, m.bricklink_price_used, m.bricklink_price_currency, m.bricklink_price_checked_at
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id
         WHERE msi.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['minifig_id'] = (int) $row['minifig_id'];
    $row['location_id'] = (int) $row['location_id'];
    $row['bricklink_price_new'] = $row['bricklink_price_new'] !== null ? (float) $row['bricklink_price_new'] : null;
    $row['bricklink_price_used'] = $row['bricklink_price_used'] !== null ? (float) $row['bricklink_price_used'] : null;
    return $row;
}

/**
 * Previous/next instance of the SAME minifig, ordered by id — much simpler
 * than getAdjacentOwnedSets()'s cross-variant set-number comparison: unlike
 * a set number (base + "-variant"), a fig_num has no sibling-variant concept
 * to walk across, so this only ever needs to stay within one minifig_id.
 *
 * @return array{prev: ?int, next: ?int}
 */
function getAdjacentOwnedMinifigInstances(PDO $pdo, array $instance): array
{
    $prevStmt = $pdo->prepare('SELECT id FROM minifig_storage_items WHERE minifig_id = ? AND id < ? ORDER BY id DESC LIMIT 1');
    $prevStmt->execute([$instance['minifig_id'], $instance['id']]);
    $prevId = $prevStmt->fetchColumn();

    $nextStmt = $pdo->prepare('SELECT id FROM minifig_storage_items WHERE minifig_id = ? AND id > ? ORDER BY id ASC LIMIT 1');
    $nextStmt->execute([$instance['minifig_id'], $instance['id']]);
    $nextId = $nextStmt->fetchColumn();

    return [
        'prev' => $prevId !== false ? (int) $prevId : null,
        'next' => $nextId !== false ? (int) $nextId : null,
    ];
}

/**
 * 1-based position among this minifig's owned instances, oldest first — for
 * the "#n" breadcrumb, mirrors getOwnedSetsForSet()'s use on the catalog set
 * page.
 */
function getOwnedMinifigInstanceNumber(PDO $pdo, int $minifigId, int $instanceId): int
{
    $stmt = $pdo->prepare('SELECT id FROM minifig_storage_items WHERE minifig_id = ? ORDER BY id ASC');
    $stmt->execute([$minifigId]);
    $position = 1;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ((int) $id === $instanceId) {
            return $position;
        }
        $position++;
    }
    return $position;
}

/**
 * Every owned instance of one minifig model, oldest first, each tagged with
 * its own complete/damaged/missing status (getOwnedMinifigInstanceStatus(),
 * src/minifigs.php) — powers the detail page's instance picker dropdown,
 * letting it jump straight to a specific physical copy of the same model
 * shown in "Meine Minifiguren"'s grouped card (renderOwnedMinifigGroupCard()).
 *
 * @return array<int, array{id:int, location_id:int, condition_type:string, status:string}>
 */
function getOwnedMinifigInstancesForModel(PDO $pdo, int $minifigId, string $figNum, string $locale = 'en'): array
{
    $stmt = $pdo->prepare('SELECT id, location_id, condition_type FROM minifig_storage_items WHERE minifig_id = ? ORDER BY id ASC');
    $stmt->execute([$minifigId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[] = [
            'id' => (int) $row['id'],
            'location_id' => (int) $row['location_id'],
            'condition_type' => $row['condition_type'],
            'status' => getOwnedMinifigInstanceStatus($pdo, (int) $row['id'], $figNum, $locale),
        ];
    }
    return $result;
}

/**
 * The N owned minifigs (model + condition) with the highest unit BrickLink
 * price, most expensive first — powers the "Top 100 Mini Figuren" nav
 * entry. Ranked by unit price, not total holdings value (price × quantity):
 * a model owned in both conditions gets one row per condition (its own
 * bricklink_price_new/used), since the two aren't really the same product
 * from a valuation standpoint — a model can therefore appear twice.
 * $quantity is how many of that exact model+condition combo are owned,
 * shown alongside but not factored into the ranking; $total_value
 * (unit_price × quantity) is likewise just a displayed figure, not the sort
 * key.
 *
 * @return array<int, array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, condition_type:string, quantity:int, representative_instance_id:int, unit_price:float, total_value:float, currency:?string}>
 */
function getTopValuedOwnedMinifigs(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->prepare(
        "SELECT m.id AS minifig_id, m.fig_num, m.name, m.local_image_path AS thumbnail,
                msi.condition_type, COUNT(*) AS quantity, MIN(msi.id) AS representative_instance_id,
                CASE msi.condition_type WHEN 'new' THEN m.bricklink_price_new ELSE m.bricklink_price_used END AS unit_price,
                m.bricklink_price_currency AS currency
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id
         GROUP BY m.id, msi.condition_type, m.fig_num, m.name, m.local_image_path,
                  m.bricklink_price_new, m.bricklink_price_used, m.bricklink_price_currency
         HAVING unit_price IS NOT NULL
         ORDER BY unit_price DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['minifig_id'] = (int) $row['minifig_id'];
        $row['quantity'] = (int) $row['quantity'];
        $row['representative_instance_id'] = (int) $row['representative_instance_id'];
        $row['unit_price'] = (float) $row['unit_price'];
        $row['total_value'] = $row['unit_price'] * $row['quantity'];
    }
    unset($row);
    return $rows;
}

/**
 * Removes one minifig instance entirely: unlinks its photo files (DB rows
 * cascade via FK), deletes the row (minifig_storage_item_parts cascades via
 * FK too). No deleteStorageLocation() call, unlike removeOwnedSet() — a
 * loose minifig instance was never given its own auto-generated leaf
 * location (see minifig_storage_items' own doc comment, src/setup.php), it
 * always points directly at a real, user-created location.
 */
function removeOwnedMinifigInstance(PDO $pdo, int $instanceId): void
{
    foreach (getOwnedMinifigPhotos($pdo, $instanceId) as $photo) {
        @unlink(dirname(__DIR__) . '/' . $photo['stored_path']);
    }
    $pdo->prepare('DELETE FROM minifig_storage_items WHERE id = ?')->execute([$instanceId]);
}

function saveOwnedMinifigNotes(PDO $pdo, int $instanceId, ?string $notes): void
{
    $pdo->prepare('UPDATE minifig_storage_items SET notes = ? WHERE id = ?')->execute([$notes, $instanceId]);
}

/**
 * "Verkaufen": records a sale (minifig_storage_item_sales — the only place
 * this survives, since the instance itself is gone right after) then does
 * exactly what removeOwnedMinifigInstance() does — mirrors sellOwnedSet().
 */
function sellOwnedMinifigInstance(PDO $pdo, int $instanceId, ?float $price, ?string $soldAt, ?string $platform, ?string $notes, ?int $userId): void
{
    $instance = getOwnedMinifigInstanceById($pdo, $instanceId);
    if ($instance === null) {
        throw new RuntimeException('Minifigur-Exemplar nicht gefunden.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO minifig_storage_item_sales (minifig_id, fig_num, name, price, sold_at, platform, notes, sold_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $instance['minifig_id'],
        $instance['fig_num'],
        $instance['name'],
        $price,
        $soldAt,
        $platform,
        $notes,
        $userId,
    ]);

    removeOwnedMinifigInstance($pdo, $instanceId);
}

// ---------------------------------------------------------------------
// Photos (1:1 mirror of owned_set_photos)
// ---------------------------------------------------------------------

function getOwnedMinifigPhotosStorageDir(int $instanceId): string
{
    $dir = dirname(__DIR__) . '/public/minifig_storage_item_photos/' . $instanceId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Verzeichnis für Minifiguren-Fotos nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getOwnedMinifigPhotoRelativePath(int $instanceId, string $filename): string
{
    return 'public/minifig_storage_item_photos/' . $instanceId . '/' . $filename;
}

function generateOwnedMinifigPhotoFilename(string $originalFilename): string
{
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'jpg';
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/**
 * @return array<int, array{id:int, caption:?string, original_filename:string, stored_path:string, file_size:int, uploaded_at:string}>
 */
function getOwnedMinifigPhotos(PDO $pdo, int $instanceId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, caption, original_filename, stored_path, file_size, uploaded_at
         FROM minifig_storage_item_photos WHERE minifig_storage_item_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$instanceId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['file_size'] = (int) $row['file_size'];
    }
    unset($row);
    return $rows;
}

function addOwnedMinifigPhoto(PDO $pdo, int $instanceId, ?string $caption, string $originalFilename, string $storedPath, int $fileSize, ?int $userId): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO minifig_storage_item_photos (minifig_storage_item_id, caption, original_filename, stored_path, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$instanceId, $caption, $originalFilename, $storedPath, $fileSize, $userId]);
    return (int) $pdo->lastInsertId();
}

/**
 * Returns the deleted row (so the caller can unlink the file) — null if no
 * such photo exists.
 */
function deleteOwnedMinifigPhoto(PDO $pdo, int $photoId): ?array
{
    $stmt = $pdo->prepare('SELECT id, stored_path FROM minifig_storage_item_photos WHERE id = ?');
    $stmt->execute([$photoId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $pdo->prepare('DELETE FROM minifig_storage_item_photos WHERE id = ?')->execute([$photoId]);
    return $row;
}

function renderOwnedMinifigPhoto(array $photo): string
{
    $caption = (string) ($photo['caption'] ?? '');
    $html = '<div class="owned-set-photo" data-id="' . (int) $photo['id'] . '">';
    $html .= '<button type="button" class="owned-set-photo-view" data-src="' . htmlspecialchars($photo['stored_path']) . '" data-caption="' . htmlspecialchars($caption) . '">';
    $html .= '<img src="' . htmlspecialchars($photo['stored_path']) . '" alt="' . htmlspecialchars($caption) . '" loading="lazy">';
    $html .= '</button>';
    if ($caption !== '') {
        $html .= '<span class="owned-set-photo-caption">' . htmlspecialchars($caption) . '</span>';
    }
    $html .= '<button type="button" class="owned-set-photo-delete" data-id="' . (int) $photo['id'] . '">' . htmlspecialchars(t('set_detail_instructions_delete_button')) . '</button>';
    $html .= '</div>';
    return $html;
}

/**
 * "Fotos" tab — same tile-grid/drag&drop/lightbox UX as
 * renderOwnedSetPhotoGallery() (src/owned_sets.php), reusing its exact
 * .owned-set-photo* CSS classes; only the element ids and the two upload/
 * delete action names differ.
 */
function renderOwnedMinifigPhotoGallery(PDO $pdo, array $instance): string
{
    $photos = getOwnedMinifigPhotos($pdo, $instance['id']);

    $html = '<div class="owned-set-photo-grid" id="minifig-photo-grid">';

    $html .= '<div class="owned-set-photo owned-set-photo-upload" id="minifig-photo-upload-tile">';
    $html .= '<span class="owned-set-photo-upload-icon">' . getActionIcon('upload') . '</span>';
    $html .= '<span class="owned-set-photo-upload-text">' . htmlspecialchars(t('owned_set_photo_upload_hint')) . '</span>';
    $html .= '<input type="text" id="minifig-photo-caption-input" class="owned-set-photo-upload-caption" placeholder="' . htmlspecialchars(t('owned_set_photo_caption_placeholder')) . '" maxlength="255">';
    $html .= '<input type="file" id="minifig-photo-file-input" accept="image/*" multiple hidden>';
    $html .= '<span class="instruction-upload-message" id="minifig-photo-message"></span>';
    $html .= '</div>';

    foreach ($photos as $photo) {
        $html .= renderOwnedMinifigPhoto($photo);
    }
    $html .= '</div>';

    $html .= '<div class="modal-overlay owned-set-photo-lightbox" id="minifig-photo-lightbox" style="display:none;">';
    $html .= '<div class="owned-set-photo-lightbox-inner">';
    $html .= '<button type="button" class="modal-close" id="minifig-photo-lightbox-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<div class="owned-set-photo-lightbox-stage">';
    $html .= '<button type="button" class="owned-set-photo-lightbox-nav owned-set-photo-lightbox-prev" id="minifig-photo-lightbox-prev" aria-label="' . htmlspecialchars(t('owned_set_photo_prev_label')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg></button>';
    $html .= '<img id="minifig-photo-lightbox-img" src="" alt="">';
    $html .= '<button type="button" class="owned-set-photo-lightbox-nav owned-set-photo-lightbox-next" id="minifig-photo-lightbox-next" aria-label="' . htmlspecialchars(t('owned_set_photo_next_label')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>';
    $html .= '</div>';
    $html .= '<span class="owned-set-photo-lightbox-caption" id="minifig-photo-lightbox-caption"></span>';
    $html .= '</div></div>';

    $photoLabelsJson = json_encode([
        'uploading' => t('set_detail_instructions_uploading'),
        'deleteConfirm' => t('owned_set_photo_delete_confirm'),
        'errorRetry' => t('import_error_retry'),
        'deleteButtonLabel' => t('set_detail_instructions_delete_button'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $instanceIdForJs = (int) $instance['id'];

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $photoLabelsJson;
  var uploadTile = document.getElementById("minifig-photo-upload-tile");
  var captionInput = document.getElementById("minifig-photo-caption-input");
  var fileInput = document.getElementById("minifig-photo-file-input");
  var msg = document.getElementById("minifig-photo-message");
  var grid = document.getElementById("minifig-photo-grid");
  var lightbox = document.getElementById("minifig-photo-lightbox");
  var lightboxClose = document.getElementById("minifig-photo-lightbox-close");
  var lightboxImg = document.getElementById("minifig-photo-lightbox-img");
  var lightboxCaption = document.getElementById("minifig-photo-lightbox-caption");
  var lightboxPrev = document.getElementById("minifig-photo-lightbox-prev");
  var lightboxNext = document.getElementById("minifig-photo-lightbox-next");
  if (!uploadTile || !fileInput || !msg || !grid) {
    return;
  }

  function bindDelete(btn) {
    btn.addEventListener("click", function(e) {
      e.stopPropagation();
      if (!window.confirm(texts.deleteConfirm)) {
        return;
      }
      var formData = new FormData();
      formData.set("action", "delete_minifig_photo");
      formData.set("photo_id", btn.dataset.id);
      fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            btn.closest(".owned-set-photo").remove();
          }
        });
    });
  }
  function bindView(btn) {
    btn.addEventListener("click", function() {
      openLightbox(btn.dataset.src);
    });
  }
  function bindTile(tile) {
    var deleteBtn = tile.querySelector(".owned-set-photo-delete");
    var viewBtn = tile.querySelector(".owned-set-photo-view");
    if (deleteBtn) {
      bindDelete(deleteBtn);
    }
    if (viewBtn) {
      bindView(viewBtn);
    }
  }
  grid.querySelectorAll(".owned-set-photo:not(.owned-set-photo-upload)").forEach(bindTile);

  var lightboxPhotos = [];
  var lightboxIndex = -1;

  function collectLightboxPhotos() {
    return Array.prototype.map.call(grid.querySelectorAll(".owned-set-photo-view"), function(btn) {
      return { src: btn.dataset.src, caption: btn.dataset.caption };
    });
  }

  function showLightboxPhoto(index) {
    if (lightboxPhotos.length === 0) {
      return;
    }
    lightboxIndex = (index + lightboxPhotos.length) % lightboxPhotos.length;
    var photo = lightboxPhotos[lightboxIndex];
    lightboxImg.src = photo.src;
    lightboxImg.alt = photo.caption || "";
    if (lightboxCaption) {
      lightboxCaption.textContent = photo.caption || "";
      lightboxCaption.hidden = !photo.caption;
    }
    var hasMultiple = lightboxPhotos.length > 1;
    if (lightboxPrev) {
      lightboxPrev.hidden = !hasMultiple;
    }
    if (lightboxNext) {
      lightboxNext.hidden = !hasMultiple;
    }
  }

  function openLightbox(src) {
    if (!lightbox || !lightboxImg) {
      return;
    }
    lightboxPhotos = collectLightboxPhotos();
    var startIndex = 0;
    for (var i = 0; i < lightboxPhotos.length; i++) {
      if (lightboxPhotos[i].src === src) {
        startIndex = i;
        break;
      }
    }
    showLightboxPhoto(startIndex);
    lightbox.style.display = "flex";
  }
  if (lightboxClose) {
    lightboxClose.addEventListener("click", function() {
      lightbox.style.display = "none";
      lightboxImg.src = "";
    });
  }
  if (lightboxPrev) {
    lightboxPrev.addEventListener("click", function() {
      showLightboxPhoto(lightboxIndex - 1);
    });
  }
  if (lightboxNext) {
    lightboxNext.addEventListener("click", function() {
      showLightboxPhoto(lightboxIndex + 1);
    });
  }
  document.addEventListener("keydown", function(e) {
    if (!lightbox || lightbox.style.display === "none") {
      return;
    }
    if (e.key === "ArrowLeft") {
      showLightboxPhoto(lightboxIndex - 1);
    } else if (e.key === "ArrowRight") {
      showLightboxPhoto(lightboxIndex + 1);
    }
  });

  function addPhotoTile(photo) {
    var tile = document.createElement("div");
    tile.className = "owned-set-photo";
    tile.dataset.id = photo.id;

    var viewBtn = document.createElement("button");
    viewBtn.type = "button";
    viewBtn.className = "owned-set-photo-view";
    viewBtn.dataset.src = photo.url;
    viewBtn.dataset.caption = photo.caption || "";
    var img = document.createElement("img");
    img.src = photo.url;
    img.alt = photo.caption || "";
    img.loading = "lazy";
    viewBtn.appendChild(img);
    tile.appendChild(viewBtn);

    if (photo.caption) {
      var captionEl = document.createElement("span");
      captionEl.className = "owned-set-photo-caption";
      captionEl.textContent = photo.caption;
      tile.appendChild(captionEl);
    }

    var deleteBtn = document.createElement("button");
    deleteBtn.type = "button";
    deleteBtn.className = "owned-set-photo-delete";
    deleteBtn.dataset.id = photo.id;
    deleteBtn.textContent = texts.deleteButtonLabel;
    tile.appendChild(deleteBtn);

    bindTile(tile);
    grid.appendChild(tile);
  }

  function uploadOne(file) {
    var formData = new FormData();
    formData.set("action", "upload_minifig_photo");
    formData.set("minifig_storage_item_id", "$instanceIdForJs");
    formData.set("caption", captionInput.value);
    formData.set("photo_file", file);

    return fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          addPhotoTile(res.photo);
        } else {
          msg.textContent = res.message;
        }
      })
      .catch(function() {
        msg.textContent = texts.errorRetry;
      });
  }

  function uploadFiles(files) {
    var list = Array.prototype.slice.call(files || []);
    if (list.length === 0) {
      return;
    }
    msg.textContent = texts.uploading;
    var chain = Promise.resolve();
    list.forEach(function(file) {
      chain = chain.then(function() { return uploadOne(file); });
    });
    chain.then(function() {
      msg.textContent = "";
      captionInput.value = "";
    });
  }

  uploadTile.addEventListener("click", function(e) {
    if (e.target === captionInput) {
      return;
    }
    fileInput.click();
  });
  fileInput.addEventListener("change", function() {
    uploadFiles(fileInput.files);
    fileInput.value = "";
  });
  captionInput.addEventListener("click", function(e) {
    e.stopPropagation();
  });

  var dragCounter = 0;
  uploadTile.addEventListener("dragenter", function(e) {
    e.preventDefault();
    dragCounter++;
    uploadTile.classList.add("owned-set-photo-upload-dragover");
  });
  uploadTile.addEventListener("dragover", function(e) {
    e.preventDefault();
  });
  uploadTile.addEventListener("dragleave", function() {
    dragCounter = Math.max(0, dragCounter - 1);
    if (dragCounter === 0) {
      uploadTile.classList.remove("owned-set-photo-upload-dragover");
    }
  });
  uploadTile.addEventListener("drop", function(e) {
    e.preventDefault();
    dragCounter = 0;
    uploadTile.classList.remove("owned-set-photo-upload-dragover");
    uploadFiles(e.dataTransfer && e.dataTransfer.files);
  });
})();
</script>
SCRIPT;

    return $html;
}

// ---------------------------------------------------------------------
// Bauteile tab
// ---------------------------------------------------------------------

/**
 * The Bauteile tab's tile grid — reuses renderOwnedSetInventoryTile() and
 * renderOwnedSetQuantityModalMarkup() (src/owned_sets.php) unchanged, both
 * already generic despite living in that file. No exclusive/rare grouping
 * (a set-catalog concept a single minifig has no equivalent of).
 */
function renderOwnedMinifigInventoryGrid(array $instance, array $parts): string
{
    if (empty($parts)) {
        return '<section class="card"><p>' . htmlspecialchars(t('set_detail_inventory_empty')) . '</p></section>';
    }

    $tilesHtml = '';
    foreach ($parts as $part) {
        $name = $part['name'] . ($part['color_name'] !== null ? ' · ' . $part['color_name'] : '');
        $tilesHtml .= renderOwnedSetInventoryTile(
            $part['part_id'] . ':' . $part['color_id'],
            $part['part_num'],
            $name,
            $part['thumbnail'],
            (int) $part['nominal_quantity'],
            (int) $part['actual_quantity'],
            (int) $part['damaged_quantity']
        );
    }

    $html = '<div class="parts-grid owned-set-inventory-grid" id="minifig-inventory-grid">' . $tilesHtml . '</div>';
    $html .= renderOwnedSetQuantityModalMarkup();
    $html .= renderOwnedMinifigQuantityModalScript($instance);

    return $html;
}

/**
 * Click-to-edit modal for one Bauteile tile — mirrors
 * renderOwnedSetQuantityModalScript()'s structure, but saves via the
 * existing action=save_minifig_storage_item_part (separate part_id/
 * color_id/quantity/damaged_quantity POST fields, not a single
 * "[key]"-bracketed array field — see that action's own doc comment,
 * src/routes/actions.php) and has no separate "summary" sidebar rows to
 * patch (a single minifig has no exclusive/rare/sticker/minifig
 * sub-categories) — only the one ring, recomputed here by summing every
 * tile's own current dataset instead of a server round-trip.
 */
function renderOwnedMinifigQuantityModalScript(array $instance): string
{
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'saveButton' => t('owned_set_save_button'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $instanceIdJson = (int) $instance['id'];
    $localeJson = json_encode(getLocale(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var appLocale = $localeJson;
  var instanceId = $instanceIdJson;

  var grid = document.getElementById('minifig-inventory-grid');
  var modal = document.getElementById('owned-set-qty-modal');
  var modalContent = document.getElementById('owned-set-qty-modal-content');
  var closeBtn = document.getElementById('owned-set-qty-modal-close');
  if (!grid || !modal || !modalContent || !closeBtn) {
    return;
  }

  function closeModal() {
    modal.style.display = 'none';
    modalContent.innerHTML = '';
  }
  closeBtn.addEventListener('click', closeModal);

  function buildStepper(minVal, maxVal, value) {
    var wrap = document.createElement('div');
    wrap.className = 'owned-set-inventory-stepper';
    var minusBtn = document.createElement('button');
    minusBtn.type = 'button';
    minusBtn.className = 'owned-set-inventory-stepper-btn';
    minusBtn.textContent = '\\u2212';
    var input = document.createElement('input');
    input.type = 'number';
    input.min = String(minVal);
    input.max = String(maxVal);
    input.value = String(value);
    var plusBtn = document.createElement('button');
    plusBtn.type = 'button';
    plusBtn.className = 'owned-set-inventory-stepper-btn';
    plusBtn.textContent = '+';

    function step(delta) {
      var v = (parseInt(input.value, 10) || 0) + delta;
      v = Math.max(parseInt(input.min, 10), Math.min(v, parseInt(input.max, 10)));
      input.value = String(v);
      input.dispatchEvent(new Event('input'));
    }
    minusBtn.addEventListener('click', function() { step(-1); });
    plusBtn.addEventListener('click', function() { step(1); });

    wrap.appendChild(minusBtn);
    wrap.appendChild(input);
    wrap.appendChild(plusBtn);
    return { wrap: wrap, input: input };
  }

  function updateTile(tile, actual, damaged) {
    var nominal = parseInt(tile.dataset.nominal, 10);
    var missing = Math.max(0, nominal - actual);
    var intact = actual - damaged;
    tile.dataset.actual = String(actual);
    tile.dataset.damaged = String(damaged);
    tile.classList.remove('owned-set-inventory-tile-complete', 'owned-set-inventory-tile-damaged', 'owned-set-inventory-tile-missing');
    if (missing > 0) {
      tile.classList.add('owned-set-inventory-tile-missing');
    } else if (damaged > 0) {
      tile.classList.add('owned-set-inventory-tile-damaged');
    } else {
      tile.classList.add('owned-set-inventory-tile-complete');
    }
    var summary = tile.querySelector('.owned-set-inventory-summary');
    if (summary) {
      summary.textContent = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
    }
  }

  function formatNumber(n) {
    var sep = appLocale === 'de' ? '.' : ',';
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, sep);
  }

  function ringColorClass(percent) {
    if (percent >= 100) {
      return 'owned-set-total-ring-fg-complete';
    }
    if (percent >= 75) {
      return 'owned-set-total-ring-fg-partial';
    }
    return 'owned-set-total-ring-fg-low';
  }

  function applyStats(stats) {
    if (!stats) {
      return;
    }
    Object.keys(stats).forEach(function(key) {
      var el = document.getElementById('status-stat-' + key);
      var strong = el ? el.querySelector('strong') : null;
      if (strong) {
        strong.textContent = formatNumber(stats[key]);
      }
    });
  }

  // No separate "summary" sidebar rows to patch (unlike sets, see this
  // function's own doc comment) — just recomputes the one ring by summing
  // every tile's own current dataset, no server round-trip needed.
  function updateRing() {
    var ringFg = document.getElementById('owned-set-total-ring-fg');
    var ringLabel = document.getElementById('owned-set-total-ring-label');
    if (!ringFg || !ringLabel) {
      return;
    }
    var nominal = 0, actual = 0;
    grid.querySelectorAll('.owned-set-inventory-tile').forEach(function(t) {
      nominal += parseInt(t.dataset.nominal, 10) || 0;
      actual += parseInt(t.dataset.actual, 10) || 0;
    });
    var percent = nominal > 0 ? Math.min(100, (actual / nominal) * 100) : 100;
    var circumference = 2 * Math.PI * 45;
    ringFg.style.strokeDashoffset = (circumference * (1 - percent / 100)).toFixed(2);
    ringFg.classList.remove('owned-set-total-ring-fg-complete', 'owned-set-total-ring-fg-partial', 'owned-set-total-ring-fg-low');
    ringFg.classList.add(ringColorClass(percent));
    ringLabel.textContent = formatNumber(actual) + ' / ' + formatNumber(nominal);
  }

  function openModal(tile) {
    modalContent.innerHTML = '';
    modal.style.display = 'flex';

    var nominal = parseInt(tile.dataset.nominal, 10);
    var actual = parseInt(tile.dataset.actual, 10);
    var damaged = parseInt(tile.dataset.damaged, 10);

    var header = document.createElement('div');
    header.className = 'owned-set-qty-modal-header';
    var img = document.createElement('span');
    img.className = 'owned-set-qty-modal-image';
    if (tile.dataset.thumbnail) {
      img.innerHTML = '<img src="' + tile.dataset.thumbnail + '" alt="">';
    }
    header.appendChild(img);
    var info = document.createElement('div');
    var title = document.createElement('h3');
    title.textContent = tile.dataset.number;
    var name = document.createElement('p');
    name.textContent = tile.dataset.name;
    info.appendChild(title);
    info.appendChild(name);
    header.appendChild(info);
    modalContent.appendChild(header);

    var ownedLabel = document.createElement('label');
    ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
    var ownedStepper = buildStepper(0, nominal, actual);
    ownedLabel.appendChild(ownedStepper.wrap);
    modalContent.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
    var damagedStepper = buildStepper(0, actual, damaged);
    damagedLabel.appendChild(damagedStepper.wrap);
    modalContent.appendChild(damagedLabel);

    ownedStepper.input.addEventListener('input', function() {
      var v = parseInt(ownedStepper.input.value, 10) || 0;
      damagedStepper.input.max = String(v);
      if ((parseInt(damagedStepper.input.value, 10) || 0) > v) {
        damagedStepper.input.value = String(v);
      }
    });

    var msg = document.createElement('p');
    msg.className = 'owned-set-message';
    modalContent.appendChild(msg);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = texts.saveButton;
    saveBtn.addEventListener('click', function() {
      msg.textContent = '';
      var newOwned = Math.max(0, Math.min(parseInt(ownedStepper.input.value, 10) || 0, nominal));
      var newDamaged = Math.max(0, Math.min(parseInt(damagedStepper.input.value, 10) || 0, newOwned));
      var key = tile.dataset.key.split(':');

      var formData = new FormData();
      formData.set('action', 'save_minifig_storage_item_part');
      formData.set('minifig_storage_item_id', String(instanceId));
      formData.set('part_id', key[0]);
      formData.set('color_id', key[1]);
      formData.set('quantity', String(newOwned));
      formData.set('damaged_quantity', String(newDamaged));

      saveBtn.disabled = true;
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          saveBtn.disabled = false;
          if (res.success) {
            updateTile(tile, newOwned, newDamaged);
            updateRing();
            applyStats(res.stats);
            closeModal();
          } else {
            msg.textContent = res.message || texts.errorRetry;
          }
        })
        .catch(function() {
          saveBtn.disabled = false;
          msg.textContent = texts.errorRetry;
        });
    });
    modalContent.appendChild(saveBtn);
  }

  grid.addEventListener('click', function(e) {
    var tile = e.target.closest('.owned-set-inventory-tile');
    if (tile) {
      openModal(tile);
    }
  });
  grid.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var tile = e.target.closest('.owned-set-inventory-tile');
    if (tile) {
      e.preventDefault();
      openModal(tile);
    }
  });
})();
</script>
SCRIPT;
}

// ---------------------------------------------------------------------
// Beschädigt/Fehlend + BrickLink export
// ---------------------------------------------------------------------

/**
 * Mirrors renderOwnedSetDamagedMissingSection() — no spares/sticker toggles
 * (neither category exists for a minifig's own inventory) and no nested
 * minifig-in-minifig breakdown (a minifig doesn't contain other minifigs).
 */
function renderOwnedMinifigDamagedMissingSection(PDO $pdo, array $instance): string
{
    $parts = getMinifigStorageItemPartsWithStatus($pdo, $instance['id'], $instance['fig_num'], getLocale());

    $rows = [];
    foreach ($parts as $part) {
        $missing = $part['nominal_quantity'] - $part['actual_quantity'];
        if ($part['damaged_quantity'] <= 0 && $missing <= 0) {
            continue;
        }
        $rows[] = [
            'thumbnail' => $part['thumbnail'],
            'name' => $part['name'] . ($part['color_name'] !== null ? ' · ' . $part['color_name'] : ''),
            'damaged' => $part['damaged_quantity'],
            'missing' => max(0, $missing),
        ];
    }

    if (empty($rows)) {
        return '<section class="card"><p>' . htmlspecialchars(t('owned_set_damaged_missing_empty')) . '</p></section>';
    }

    $html = '<div class="owned-set-inventory-tiles">';
    foreach ($rows as $row) {
        $html .= '<div class="owned-set-inventory-tile owned-set-inventory-tile-readonly">';
        $html .= '<span class="part-card-image">' . ($row['thumbnail'] !== null ? '<img src="' . htmlspecialchars($row['thumbnail']) . '" alt="">' : getNavIcon('bricks')) . '</span>';
        $html .= '<span class="part-card-name">' . htmlspecialchars($row['name']) . '</span>';
        $html .= '<p class="owned-set-inventory-summary">' . htmlspecialchars(t('owned_set_damaged_missing_row', ['damaged' => (string) $row['damaged'], 'missing' => (string) $row['missing']])) . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Which of this minifig's own part_nums still need a parts.bricklink_part_id
 * before the XML export can use their real BrickLink item id — mirrors
 * getOwnedSetBricklinkPartNums() without the spares/stickers/nested-minifig
 * additions (none apply here), via the already-generic
 * getPartNumsMissingBricklinkId() (src/rebrickable.php).
 *
 * @return string[]
 */
function getOwnedMinifigBricklinkPartNums(PDO $pdo, array $instance): array
{
    $partNums = [];
    foreach (getMinifigStorageItemPartsWithStatus($pdo, $instance['id'], $instance['fig_num'], getLocale()) as $part) {
        $partNums[$part['part_num']] = true;
    }
    return getPartNumsMissingBricklinkId($pdo, array_keys($partNums));
}

/**
 * Mirrors buildOwnedSetBricklinkXml(), always emitting ITEMTYPE P lines only
 * — never M (a "whole minifig missing" line makes no sense here: this
 * instance IS present by definition, only its own constituent parts can be
 * damaged/missing) — so unlike the set version there's no needsManualId at
 * all, nothing here ever needs BrickLink's minifig-id resolution.
 *
 * @return array{xml: string, skipped: string[]}
 */
function buildOwnedMinifigBricklinkXml(PDO $pdo, array $instance): array
{
    $bricklinkCondition = $instance['condition_type'] === 'new' ? 'N' : 'U';
    $bricklinkRemarks = htmlspecialchars($instance['fig_num'] . ' - ' . ($instance['name'] ?? $instance['fig_num']), ENT_XML1);

    $parts = getMinifigStorageItemPartsWithStatus($pdo, $instance['id'], $instance['fig_num'], getLocale());

    $colorIds = [];
    $partNums = [];
    foreach ($parts as $item) {
        if ($item['rebrickable_color_id'] !== null) {
            $colorIds[$item['rebrickable_color_id']] = true;
        }
        $partNums[$item['part_num']] = true;
    }
    $bricklinkColorByRebrickableId = [];
    if (!empty($colorIds)) {
        $placeholders = implode(',', array_fill(0, count($colorIds), '?'));
        $stmt = $pdo->prepare("SELECT color_id, bricklink_color_id FROM colors WHERE color_id IN ($placeholders)");
        $stmt->execute(array_keys($colorIds));
        foreach ($stmt->fetchAll() as $row) {
            $bricklinkColorByRebrickableId[(int) $row['color_id']] = $row['bricklink_color_id'] !== null ? (int) $row['bricklink_color_id'] : null;
        }
    }

    $bricklinkPartIdByPartNum = [];
    if (!empty($partNums)) {
        $placeholders = implode(',', array_fill(0, count($partNums), '?'));
        $stmt = $pdo->prepare("SELECT part_num, bricklink_part_id FROM parts WHERE part_num IN ($placeholders)");
        $stmt->execute(array_keys($partNums));
        foreach ($stmt->fetchAll() as $row) {
            $bricklinkPartIdByPartNum[$row['part_num']] = $row['bricklink_part_id'];
        }
    }

    $lines = [];
    $skipped = [];
    foreach ($parts as $item) {
        $wantedQty = max(0, $item['nominal_quantity'] - $item['actual_quantity']) + $item['damaged_quantity'];
        if ($wantedQty <= 0) {
            continue;
        }
        $bricklinkColorId = $item['rebrickable_color_id'] !== null ? ($bricklinkColorByRebrickableId[$item['rebrickable_color_id']] ?? null) : null;
        if ($bricklinkColorId === null) {
            $skipped[] = $item['part_num'] . ' (' . $item['name'] . ($item['color_name'] !== null ? ' · ' . $item['color_name'] : '') . ')';
            continue;
        }
        $bricklinkPartId = $bricklinkPartIdByPartNum[$item['part_num']] ?? $item['part_num'];
        $lines[] = '  <ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>' . htmlspecialchars($bricklinkPartId, ENT_XML1)
            . '</ITEMID><COLOR>' . $bricklinkColorId . '</COLOR><MINQTY>' . $wantedQty
            . '</MINQTY><CONDITION>' . $bricklinkCondition . '</CONDITION><REMARKS>' . $bricklinkRemarks . '</REMARKS></ITEM>';
    }

    $xml = '<INVENTORY>' . "\n" . implode("\n", $lines) . "\n" . '</INVENTORY>';
    if (!empty($skipped)) {
        $xml .= "\n" . '<!-- Ohne BrickLink-Farbzuordnung ausgelassen: ' . htmlspecialchars(implode(', ', $skipped), ENT_XML1) . ' -->';
    }

    return ['xml' => $xml, 'skipped' => $skipped];
}

/**
 * BrickLink XML trigger + two modals (sync-progress, result) — mirrors
 * renderOwnedSetBricklinkModal(), minus its whole manual-entry-modal branch:
 * that only exists for whole-missing-minifig BrickLink id resolution, which
 * never applies here (see buildOwnedMinifigBricklinkXml()'s doc comment), so
 * action=owned_minifig_bricklink_xml_check's "ready" is always true and the
 * result modal always opens directly. Reuses the existing, already-generic
 * action=owned_set_bricklink_part_sync_tick unchanged for the part-id sync
 * itself.
 */
function renderOwnedMinifigBricklinkModal(array $instance): string
{
    $html = '<div class="modal-overlay" id="minifig-bricklink-result-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="minifig-bricklink-result-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_bricklink_result_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_bricklink_result_intro')) . '</p>';
    $html .= '<textarea id="minifig-bricklink-xml-content" class="owned-set-bricklink-xml-textarea" rows="14" readonly></textarea>';
    $html .= '<div class="owned-set-bricklink-modal-actions">';
    $html .= '<button type="button" class="owned-set-bricklink-result-btn" id="minifig-bricklink-copy">' . getActionIcon('copy') . '<span>' . htmlspecialchars(t('owned_set_bricklink_copy_button')) . '</span></button>';
    $html .= '<button type="button" class="owned-set-bricklink-result-btn owned-set-bricklink-result-btn-primary" id="minifig-bricklink-download">' . getActionIcon('download') . '<span>' . htmlspecialchars(t('owned_set_bricklink_download_button')) . '</span></button>';
    $html .= '</div>';
    $html .= '</div></div>';

    $html .= '<div class="modal-overlay" id="minifig-bricklink-sync-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="minifig-bricklink-sync-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_bricklink_sync_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_bricklink_sync_intro')) . '</p>';
    $html .= '<div class="owned-set-bricklink-sync-ring-wrap">';
    $html .= '<svg class="owned-set-bricklink-sync-ring" viewBox="0 0 100 100">';
    $html .= '<circle class="owned-set-bricklink-sync-ring-track" cx="50" cy="50" r="42"/>';
    $html .= '<circle class="owned-set-bricklink-sync-ring-fill" id="minifig-bricklink-sync-ring-fill" cx="50" cy="50" r="42"/>';
    $html .= '</svg>';
    $html .= '<span class="owned-set-bricklink-sync-ring-label" id="minifig-bricklink-sync-percent">0%</span>';
    $html .= '</div>';
    $html .= '<p class="hint owned-set-bricklink-sync-status" id="minifig-bricklink-sync-status"></p>';
    $html .= '</div></div>';

    $instanceId = (int) $instance['id'];
    $xmlFilename = 'bricklink-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $instance['fig_num']) . '.xml';
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'copyLabel' => t('owned_set_bricklink_copy_button'),
        'copySuccess' => t('owned_set_bricklink_copy_success'),
        'xmlFilename' => $xmlFilename,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var instanceId = $instanceId;
  var openBtn = document.getElementById('minifig-bricklink-open');
  var resultModal = document.getElementById('minifig-bricklink-result-modal');
  var resultCloseBtn = document.getElementById('minifig-bricklink-result-modal-close');
  var xmlTextarea = document.getElementById('minifig-bricklink-xml-content');
  var copyBtn = document.getElementById('minifig-bricklink-copy');
  var downloadBtn = document.getElementById('minifig-bricklink-download');
  var syncModal = document.getElementById('minifig-bricklink-sync-modal');
  var syncCloseBtn = document.getElementById('minifig-bricklink-sync-modal-close');
  var syncRingFill = document.getElementById('minifig-bricklink-sync-ring-fill');
  var syncPercentEl = document.getElementById('minifig-bricklink-sync-percent');
  var syncStatusEl = document.getElementById('minifig-bricklink-sync-status');
  if (!openBtn || !resultModal || !resultCloseBtn || !xmlTextarea || !copyBtn || !downloadBtn
      || !syncModal || !syncCloseBtn || !syncRingFill || !syncPercentEl || !syncStatusEl) {
    return;
  }

  function showResultModal(xmlText) {
    xmlTextarea.value = xmlText || '';
    resultModal.style.display = 'flex';
  }
  function closeResultModal() {
    resultModal.style.display = 'none';
  }
  resultCloseBtn.addEventListener('click', closeResultModal);

  var ringCircumference = 2 * Math.PI * 42;
  syncRingFill.style.strokeDasharray = String(ringCircumference);
  syncRingFill.style.strokeDashoffset = String(ringCircumference);

  function openSyncModal() {
    syncRingFill.style.strokeDashoffset = String(ringCircumference);
    syncPercentEl.textContent = '0%';
    syncStatusEl.textContent = '';
    syncModal.style.display = 'flex';
  }
  function closeSyncModal() {
    syncModal.style.display = 'none';
  }
  function updateSyncProgress(done, total) {
    var percent = total > 0 ? Math.round((done / total) * 100) : 100;
    syncRingFill.style.strokeDashoffset = String(ringCircumference * (1 - percent / 100));
    syncPercentEl.textContent = percent + '%';
    syncStatusEl.textContent = done + ' / ' + total;
  }
  syncCloseBtn.addEventListener('click', closeSyncModal);

  function checkAndProceed() {
    var params = new URLSearchParams();
    params.set('action', 'owned_minifig_bricklink_xml_check');
    params.set('instance_id', String(instanceId));
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          throw new Error(res.message || texts.errorRetry);
        }
        showResultModal(res.xml);
      })
      .catch(function() {
        window.alert(texts.errorRetry);
      });
  }

  function runPartSync(partNums, batchSize) {
    var total = partNums.length;
    var batches = [];
    for (var i = 0; i < partNums.length; i += batchSize) {
      batches.push(partNums.slice(i, i + batchSize));
    }
    var done = 0;
    openSyncModal();
    updateSyncProgress(0, total);

    function nextBatch(index) {
      if (index >= batches.length) {
        closeSyncModal();
        checkAndProceed();
        return;
      }
      var batch = batches[index];
      var startedAt = Date.now();
      var formData = new FormData();
      formData.set('action', 'owned_set_bricklink_part_sync_tick');
      formData.set('part_nums', batch.join(','));
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .catch(function() { return { success: false }; })
        .then(function() {
          done += batch.length;
          updateSyncProgress(done, total);
          var elapsed = Date.now() - startedAt;
          var wait = Math.max(0, 1000 - elapsed);
          setTimeout(function() { nextBatch(index + 1); }, wait);
        });
    }
    nextBatch(0);
  }

  var copyResetTimer = null;
  copyBtn.addEventListener('click', function() {
    var label = copyBtn.querySelector('span');
    function showCopied() {
      if (copyResetTimer) {
        clearTimeout(copyResetTimer);
      }
      label.textContent = texts.copySuccess;
      copyResetTimer = setTimeout(function() { label.textContent = texts.copyLabel; }, 1500);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(xmlTextarea.value).then(showCopied).catch(function() {
        xmlTextarea.select();
        document.execCommand('copy');
        showCopied();
      });
    } else {
      xmlTextarea.select();
      document.execCommand('copy');
      showCopied();
    }
  });

  downloadBtn.addEventListener('click', function() {
    var blob = new Blob([xmlTextarea.value], { type: 'application/xml' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = texts.xmlFilename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });

  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    var params = new URLSearchParams();
    params.set('action', 'owned_minifig_bricklink_parts_missing');
    params.set('instance_id', String(instanceId));
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success && res.partNums && res.partNums.length > 0) {
          runPartSync(res.partNums, res.batchSize || 50);
        } else {
          checkAndProceed();
        }
      })
      .catch(function() {
        checkAndProceed();
      });
  });
})();
</script>
SCRIPT;

    return $html;
}

// ---------------------------------------------------------------------
// Sidebar modals: edit (notes), move, sell
// ---------------------------------------------------------------------

/**
 * "Bearbeiten" — mirrors renderOwnedSetEditModal(), reduced to just the
 * general notes field: none of the OVP/Anleitung/Sticker-angebracht
 * checkboxes apply to a loose minifig (no sealed-box workflow).
 */
function renderOwnedMinifigEditModal(array $instance): string
{
    $html = '<div class="modal-overlay" id="minifig-edit-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="minifig-edit-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_edit_heading')) . '</h2>';
    $html .= '<form method="post" id="minifig-edit-form">';
    $html .= '<input type="hidden" name="action" value="save_minifig_notes">';
    $html .= '<input type="hidden" name="instance_id" value="' . (int) $instance['id'] . '">';
    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea name="notes" rows="4">' . htmlspecialchars((string) $instance['notes']) . '</textarea></label>';
    $html .= '<button type="submit">' . htmlspecialchars(t('owned_set_save_button')) . '</button>';
    $html .= '</form>';
    $html .= '</div></div>';

    $html .= <<<SCRIPT
<script>
(function(){
  var openBtn = document.getElementById('minifig-edit-open');
  var modal = document.getElementById('minifig-edit-modal');
  var closeBtn = document.getElementById('minifig-edit-modal-close');
  if (!openBtn || !modal || !closeBtn) {
    return;
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    modal.style.display = 'flex';
  });
  closeBtn.addEventListener('click', function() {
    modal.style.display = 'none';
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * "Verschieben" — unlike renderOwnedSetMoveModal() (which re-parents the
 * instance's own auto-generated leaf location), a loose minifig instance has
 * no location of its own to re-parent — it just points directly at an
 * existing real location, so this posts straight to the already-existing
 * action=move_minifig_storage_item (src/routes/actions.php, built for the
 * location Explorer) with new_location_id, not parent_location_id.
 */
function renderOwnedMinifigMoveModal(array $instance): string
{
    $currentPath = implode(' » ', array_column(getStorageLocationAncestors($instance['location_id']), 'name'));

    $html = '<div class="modal-overlay" id="minifig-move-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="minifig-move-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_move_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_move_current', ['path' => $currentPath])) . '</p>';
    $html .= '<form id="minifig-move-form">';
    $html .= '<div class="location-picker" id="minifig-move-location-picker"></div>';
    $html .= '<p class="owned-set-wizard-error" id="minifig-move-error"></p>';
    $html .= '<button type="submit">' . htmlspecialchars(t('owned_set_move_button')) . '</button>';
    $html .= '</form>';
    $html .= '</div></div>';

    $labelsJson = json_encode([
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'levelLabel' => t('location_picker_level_label'),
        'rootLabel' => t('location_picker_root_label'),
        'locationRequired' => t('owned_set_wizard_location_required'),
        'errorRetry' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $instanceIdJson = (int) $instance['id'];

    // POSTs via fetch (not a plain form submit) since action=
    // move_minifig_storage_item already returns JSON — it was built for the
    // location Explorer's own AJAX flow (src/routes/pages.php) and is
    // reused here unchanged rather than duplicated. Reloads on success,
    // same "simplest correct way to show fresh state, a manual move is rare
    // enough that the cost is fine" reasoning as the BrickLink price
    // refresh button.
    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var instanceId = $instanceIdJson;
  var openBtn = document.getElementById('minifig-move-open');
  var modal = document.getElementById('minifig-move-modal');
  var closeBtn = document.getElementById('minifig-move-modal-close');
  var form = document.getElementById('minifig-move-form');
  var pickerContainer = document.getElementById('minifig-move-location-picker');
  var errorEl = document.getElementById('minifig-move-error');
  if (!openBtn || !modal || !closeBtn || !form || !pickerContainer) {
    return;
  }

  var selectedLocationId = null;
  window.createLocationPicker(pickerContainer, texts, function(value) {
    selectedLocationId = value;
  });

  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    modal.style.display = 'flex';
  });
  closeBtn.addEventListener('click', function() {
    modal.style.display = 'none';
  });

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    errorEl.textContent = '';
    if (!selectedLocationId) {
      errorEl.textContent = texts.locationRequired;
      return;
    }
    var formData = new FormData();
    formData.set('action', 'move_minifig_storage_item');
    formData.set('instance_id', String(instanceId));
    formData.set('new_location_id', selectedLocationId);
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.reload();
        } else {
          errorEl.textContent = res.message || texts.errorRetry;
        }
      })
      .catch(function() {
        errorEl.textContent = texts.errorRetry;
      });
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * "Verkaufen" — mirrors renderOwnedSetSellModal(). Plain form submit +
 * confirm(), navigates away on success just like "Entfernen" (the redirect
 * happens server-side, see action=sell_minifig_storage_item).
 */
function renderOwnedMinifigSellModal(array $instance): string
{
    $today = date('Y-m-d');

    $html = '<div class="modal-overlay" id="minifig-sell-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="minifig-sell-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_sell_heading')) . '</h2>';
    $html .= '<form method="post" id="minifig-sell-form">';
    $html .= '<input type="hidden" name="action" value="sell_minifig_storage_item">';
    $html .= '<input type="hidden" name="instance_id" value="' . (int) $instance['id'] . '">';
    $html .= '<label>' . htmlspecialchars(t('owned_set_sell_price_label')) . '<input type="number" name="price" step="0.01" min="0"></label>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_sell_date_label')) . '<input type="date" name="sold_at" value="' . htmlspecialchars($today) . '"></label>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_sell_platform_label')) . '<input type="text" name="platform" placeholder="' . htmlspecialchars(t('owned_set_sell_platform_placeholder')) . '"></label>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea name="notes" rows="3"></textarea></label>';
    $html .= '<button type="submit" class="owned-set-remove-button">' . htmlspecialchars(t('owned_set_sell_button')) . '</button>';
    $html .= '</form>';
    $html .= '</div></div>';

    $confirmJson = json_encode(t('owned_set_sell_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var openBtn = document.getElementById('minifig-sell-open');
  var modal = document.getElementById('minifig-sell-modal');
  var closeBtn = document.getElementById('minifig-sell-modal-close');
  var form = document.getElementById('minifig-sell-form');
  if (!openBtn || !modal || !closeBtn || !form) {
    return;
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    modal.style.display = 'flex';
  });
  closeBtn.addEventListener('click', function() {
    modal.style.display = 'none';
  });
  form.addEventListener('submit', function(e) {
    if (!window.confirm($confirmJson)) {
      e.preventDefault();
    }
  });
})();
</script>
SCRIPT;

    return $html;
}

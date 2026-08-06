<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';

const INSTRUCTION_MAX_LABEL_LENGTH = 255;

/**
 * Where an uploaded instruction PDF for a set lives. Deliberately under
 * public/ (like public/images/), not storage/ — this app has no per-user
 * ownership/permission concept (every logged-in user sees everything, see
 * getSetInstructions() callers), so there's nothing a protected/streamed
 * download would gain here, while a direct <a href> avoids PHP having to
 * buffer/stream potentially large PDFs on shared hosting with no control
 * over memory_limit.
 *
 * No filename-based sharding (unlike getImageStorageDir()) — a set rarely
 * has more than a handful of instruction booklets, so one directory per set
 * never grows large enough to need it.
 */
function getInstructionsStorageDir(int $setId): string
{
    $dir = dirname(__DIR__) . '/public/instructions/' . $setId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Verzeichnis für Bauanleitungen nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getInstructionRelativePath(int $setId, string $filename): string
{
    return 'public/instructions/' . $setId . '/' . $filename;
}

/**
 * The on-disk filename is always a random hex string with a fixed .pdf
 * suffix — never derived from the user-supplied original filename. This
 * closes path-traversal concerns outright (no user input reaches the
 * filesystem path) and means a mismatched/spoofed upload can never end up
 * served with an extension that would make a misconfigured host execute it.
 */
function generateInstructionFilename(): string
{
    return bin2hex(random_bytes(16)) . '.pdf';
}

function generateInstructionThumbnailFilename(): string
{
    return bin2hex(random_bytes(16)) . '.png';
}

/**
 * @return array<int, array{id:int, set_id:int, label:?string, original_filename:string, stored_path:string, thumbnail_path:?string, file_size:int, uploaded_at:string}>
 */
function getSetInstructions(PDO $pdo, int $setId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, set_id, label, original_filename, stored_path, thumbnail_path, file_size, uploaded_at
         FROM set_instructions WHERE set_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$setId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['set_id'] = (int) $row['set_id'];
        $row['file_size'] = (int) $row['file_size'];
    }
    unset($row);
    return $rows;
}

function getSetInstructionById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, set_id, label, original_filename, stored_path, thumbnail_path, file_size, uploaded_at
         FROM set_instructions WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['set_id'] = (int) $row['set_id'];
    $row['file_size'] = (int) $row['file_size'];
    return $row;
}

/**
 * @return array{id:int, set_id:int, label:?string, original_filename:string, stored_path:string, thumbnail_path:?string, file_size:int, uploaded_at:string}
 */
function addSetInstruction(PDO $pdo, int $setId, ?string $label, string $originalFilename, string $storedPath, ?string $thumbnailPath, int $fileSize, ?int $uploadedBy): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO set_instructions (set_id, label, original_filename, stored_path, thumbnail_path, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$setId, $label, $originalFilename, $storedPath, $thumbnailPath, $fileSize, $uploadedBy]);
    $id = (int) $pdo->lastInsertId();

    $instruction = getSetInstructionById($pdo, $id);
    if ($instruction === null) {
        throw new RuntimeException('Bauanleitung konnte nach dem Speichern nicht gefunden werden.');
    }
    return $instruction;
}

/**
 * Best-effort first-page thumbnail for an uploaded instruction PDF. Tries,
 * in order: (1) a CLI tool (pdftoppm, then Ghostscript) via exec(), which
 * on a VPS with root access is the cheapest and most reliable path; (2) the
 * Imagick extension, which also works on shared hosts that forbid exec()
 * but expose Imagick — though it commonly fails there too, since
 * ImageMagick's policy.xml disables the PDF/PS/EPS delegate on shared hosts
 * by default (post-ImageTragick CVEs) even when the extension itself is
 * installed; (3) nothing — the caller falls back to the generic PDF icon.
 * Every step swallows its own failures so a missing tool never surfaces as
 * an error to the user, it just moves on to the next step.
 */
function tryRenderInstructionThumbnail(string $pdfAbsolutePath, string $thumbnailAbsolutePath): bool
{
    if (tryRenderInstructionThumbnailViaSystemTool($pdfAbsolutePath, $thumbnailAbsolutePath)) {
        return true;
    }
    return tryRenderInstructionThumbnailViaImagick($pdfAbsolutePath, $thumbnailAbsolutePath);
}

function instructionThumbnailToolAvailable(string $binary): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);
    return $exitCode === 0 && $output !== [];
}

function tryRenderInstructionThumbnailViaSystemTool(string $pdfAbsolutePath, string $thumbnailAbsolutePath): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    try {
        if (instructionThumbnailToolAvailable('pdftoppm')) {
            $tmpPrefix = sys_get_temp_dir() . '/studsphere_instr_' . bin2hex(random_bytes(8));
            $cmd = sprintf(
                'pdftoppm -f 1 -l 1 -png -scale-to-x 400 -scale-to-y -1 %s %s 2>&1',
                escapeshellarg($pdfAbsolutePath),
                escapeshellarg($tmpPrefix)
            );
            exec($cmd, $output, $exitCode);
            $matches = glob($tmpPrefix . '*.png') ?: [];
            if ($exitCode === 0 && $matches !== [] && is_file($matches[0]) && filesize($matches[0]) > 0) {
                $ok = rename($matches[0], $thumbnailAbsolutePath);
                foreach ($matches as $leftover) {
                    if (is_file($leftover)) {
                        @unlink($leftover);
                    }
                }
                if ($ok) {
                    return true;
                }
            } else {
                foreach ($matches as $leftover) {
                    @unlink($leftover);
                }
            }
        }

        if (instructionThumbnailToolAvailable('gs')) {
            $cmd = sprintf(
                'gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r100 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>&1',
                escapeshellarg($thumbnailAbsolutePath),
                escapeshellarg($pdfAbsolutePath)
            );
            exec($cmd, $output, $exitCode);
            if ($exitCode === 0 && is_file($thumbnailAbsolutePath) && filesize($thumbnailAbsolutePath) > 0) {
                return true;
            }
            if (is_file($thumbnailAbsolutePath)) {
                @unlink($thumbnailAbsolutePath);
            }
        }
    } catch (\Throwable $e) {
        return false;
    }

    return false;
}

function tryRenderInstructionThumbnailViaImagick(string $pdfAbsolutePath, string $thumbnailAbsolutePath): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        $imagick = new Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImage($pdfAbsolutePath . '[0]');
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $imagick->setImageFormat('png');
        $imagick->thumbnailImage(400, 0);
        $written = $imagick->writeImage($thumbnailAbsolutePath);
        $imagick->clear();
        return $written;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Deletes the DB row and returns it (so the caller can unlink the on-disk
 * file afterwards) — null if no such instruction exists.
 */
function deleteSetInstruction(PDO $pdo, int $id): ?array
{
    $instruction = getSetInstructionById($pdo, $id);
    if ($instruction === null) {
        return null;
    }
    $stmt = $pdo->prepare('DELETE FROM set_instructions WHERE id = ?');
    $stmt->execute([$id]);
    return $instruction;
}

function renderInstructionTile(array $instruction): string
{
    $label = $instruction['label'] !== null ? $instruction['label'] : $instruction['original_filename'];
    $meta = formatFileSize($instruction['file_size']) . ' · ' . formatDate($instruction['uploaded_at']);

    $html = '<div class="owned-set-photo instruction-tile" data-id="' . (int) $instruction['id'] . '">';
    $html .= '<a class="owned-set-photo-view instruction-tile-open" href="' . htmlspecialchars($instruction['stored_path']) . '" target="_blank" rel="noopener">';
    if (!empty($instruction['thumbnail_path'])) {
        $html .= '<img class="instruction-tile-thumbnail" src="' . htmlspecialchars($instruction['thumbnail_path']) . '" alt="">';
    } else {
        $html .= '<span class="instruction-tile-icon">' . getActionIcon('pdf') . '</span>';
    }
    $html .= '</a>';
    $html .= '<span class="owned-set-photo-caption">' . htmlspecialchars($label) . '</span>';
    $html .= '<span class="owned-set-photo-caption instruction-tile-meta">' . htmlspecialchars($meta) . '</span>';
    $html .= '<button type="button" class="owned-set-photo-delete" data-id="' . (int) $instruction['id'] . '">' . htmlspecialchars(t('set_detail_instructions_delete_button')) . '</button>';
    $html .= '</div>';
    return $html;
}

/**
 * The "Bauanleitung" tab's full markup + upload/delete script — shared by
 * the catalog set_detail page and owned_set_detail's own tab bar. Keyed by
 * the catalog set_id in both cases (an owned instance has no instructions
 * of its own; every physical copy of the same set shares the same PDFs),
 * so this needs nothing owned-instance-specific. Deliberately the same
 * tile-grid/drag-and-drop UX as renderOwnedSetPhotoGallery() (src/owned_sets.php)
 * — per explicit user request to match that tab exactly — reusing its
 * .owned-set-photo* classes directly rather than a parallel set, since a
 * PDF tile is structurally identical to a photo tile (just an icon instead
 * of an <img>, and an "open in new tab" link instead of a lightbox).
 */
function renderSetInstructionsTab(int $setId): string
{
    $pdo = getPDO();
    $instructions = getSetInstructions($pdo, $setId);

    $content = '<div class="owned-set-photo-grid" id="instructions-grid">';

    $content .= '<div class="owned-set-photo owned-set-photo-upload" id="instructions-upload-tile">';
    $content .= '<span class="owned-set-photo-upload-icon">' . getActionIcon('upload') . '</span>';
    $content .= '<span class="owned-set-photo-upload-text">' . htmlspecialchars(t('instruction_upload_hint')) . '</span>';
    $content .= '<input type="text" id="instruction-label-input" class="owned-set-photo-upload-caption" placeholder="' . htmlspecialchars(t('set_detail_instructions_label_placeholder')) . '" maxlength="255">';
    $content .= '<input type="file" id="instruction-file-input" accept="application/pdf" multiple hidden>';
    $content .= '<span class="instruction-upload-message" id="instruction-upload-message"></span>';
    $content .= '</div>';

    foreach ($instructions as $instruction) {
        $content .= renderInstructionTile($instruction);
    }
    $content .= '</div>';

    $instructionLabelsJson = json_encode([
        'uploading' => t('set_detail_instructions_uploading'),
        'deleteConfirm' => t('set_detail_instructions_delete_confirm'),
        'errorRetry' => t('import_error_retry'),
        'deleteButtonLabel' => t('set_detail_instructions_delete_button'),
        'pdfIcon' => getActionIcon('pdf'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $content .= <<<SCRIPT
<script>
(function(){
  var texts = $instructionLabelsJson;
  var uploadTile = document.getElementById('instructions-upload-tile');
  var labelInput = document.getElementById('instruction-label-input');
  var fileInput = document.getElementById('instruction-file-input');
  var msg = document.getElementById('instruction-upload-message');
  var grid = document.getElementById('instructions-grid');
  if (!uploadTile || !fileInput || !msg || !grid) {
    return;
  }

  function bindDelete(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      if (!window.confirm(texts.deleteConfirm)) {
        return;
      }
      var formData = new FormData();
      formData.set('action', 'delete_set_instruction');
      formData.set('instruction_id', btn.dataset.id);
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            btn.closest('.instruction-tile').remove();
          }
        });
    });
  }
  grid.querySelectorAll('.instruction-tile .owned-set-photo-delete').forEach(bindDelete);

  function addInstructionTile(instruction) {
    var tile = document.createElement('div');
    tile.className = 'owned-set-photo instruction-tile';
    tile.dataset.id = instruction.id;

    var open = document.createElement('a');
    open.className = 'owned-set-photo-view instruction-tile-open';
    open.href = instruction.url;
    open.target = '_blank';
    open.rel = 'noopener';
    if (instruction.thumbnailUrl) {
      var thumb = document.createElement('img');
      thumb.className = 'instruction-tile-thumbnail';
      thumb.src = instruction.thumbnailUrl;
      thumb.alt = '';
      open.appendChild(thumb);
    } else {
      var icon = document.createElement('span');
      icon.className = 'instruction-tile-icon';
      icon.innerHTML = texts.pdfIcon;
      open.appendChild(icon);
    }
    tile.appendChild(open);

    var caption = document.createElement('span');
    caption.className = 'owned-set-photo-caption';
    caption.textContent = instruction.label || instruction.originalFilename;
    tile.appendChild(caption);

    var meta = document.createElement('span');
    meta.className = 'owned-set-photo-caption instruction-tile-meta';
    meta.textContent = instruction.fileSize + ' \\u00b7 ' + instruction.uploadedAt;
    tile.appendChild(meta);

    var deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'owned-set-photo-delete';
    deleteBtn.dataset.id = instruction.id;
    deleteBtn.textContent = texts.deleteButtonLabel;
    tile.appendChild(deleteBtn);

    bindDelete(deleteBtn);
    grid.appendChild(tile);
  }

  function uploadOne(file) {
    var formData = new FormData();
    formData.set('action', 'upload_set_instruction');
    formData.set('set_id', '$setId');
    formData.set('label', labelInput.value);
    formData.set('instruction_file', file);

    return fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          addInstructionTile(res.instruction);
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
      msg.textContent = '';
      labelInput.value = '';
    });
  }

  uploadTile.addEventListener('click', function(e) {
    if (e.target === labelInput) {
      return;
    }
    fileInput.click();
  });
  fileInput.addEventListener('change', function() {
    uploadFiles(fileInput.files);
    fileInput.value = '';
  });
  labelInput.addEventListener('click', function(e) {
    e.stopPropagation();
  });

  var dragCounter = 0;
  uploadTile.addEventListener('dragenter', function(e) {
    e.preventDefault();
    dragCounter++;
    uploadTile.classList.add('owned-set-photo-upload-dragover');
  });
  uploadTile.addEventListener('dragover', function(e) {
    e.preventDefault();
  });
  uploadTile.addEventListener('dragleave', function() {
    dragCounter = Math.max(0, dragCounter - 1);
    if (dragCounter === 0) {
      uploadTile.classList.remove('owned-set-photo-upload-dragover');
    }
  });
  uploadTile.addEventListener('drop', function(e) {
    e.preventDefault();
    dragCounter = 0;
    uploadTile.classList.remove('owned-set-photo-upload-dragover');
    uploadFiles(e.dataTransfer && e.dataTransfer.files);
  });
})();
</script>
SCRIPT;

    return $content;
}

function formatFileSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    $decimals = $unitIndex === 0 ? 0 : 1;
    return formatNumber($value, $decimals) . ' ' . $units[$unitIndex];
}

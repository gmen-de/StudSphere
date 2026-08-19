<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/download.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/part_images.php';

const LDRAW_LIBRARY_URL = 'https://library.ldraw.org/library/updates/complete.zip';

// The LDraw library is one big archive (not many small CSVs like the
// Rebrickable import), so a much bigger per-tick chunk is safe and avoids an
// impractical number of round trips.
const LDRAW_DOWNLOAD_CHUNK_SIZE = 4_000_000; // ~4 MB per download tick
const LDRAW_EXTRACT_BATCH_SIZE = 400; // zip entries extracted per tick

// Matches .part-modal-image's 16rem (256px) display size — the largest
// context these renders show up in (part-detail modal); the card grid uses
// a much smaller 4.5rem, so this comfortably covers both.
const LDRAW_RENDER_IMAGE_SIZE = 256;

// LeoCAD's stud styles 0/1/6/7 render blank studs; 2-5 emboss the LEGO logo.
// 4 ("LDraw raised rounded") was picked by comparing renders of all eight.
const LDRAW_STUD_STYLE = 4;

// Without any anti-aliasing the stud circles and the logo embossing look
// visibly jagged — 8 samples smooths that out at no noticeable cost for
// parts this simple (chosen by comparing 1/4/8 side by side).
const LDRAW_AA_SAMPLES = 8;

// exec() has no built-in timeout. Watching real renders on the test server:
// software/Xvfb rendering routinely takes 60-120s for a single part (no GPU
// available) — normal, not stuck, but also long enough that a genuinely
// hung leocad process (a real crash/deadlock, not just "slow") could
// otherwise block the persistent render worker (see runLdrawRenderWorkerOnce())
// on one item forever. 180s is well above any observed legitimate render, so
// this is a safety net against a true hang, not a budget for normal renders.
const LDRAW_RENDER_EXEC_TIMEOUT_SECONDS = 180;
// `timeout`'s own exit code when it has to SIGKILL/SIGTERM the process.
const TIMEOUT_COMMAND_EXIT_CODE = 124;

// Runs leocad at the lowest CPU scheduling priority (19 — nice's own max) so
// a render, however long it takes, only ever soaks up CPU cycles Apache/
// PHP/MariaDB aren't actively contending for, rather than competing with
// them on equal footing. Niceness is inherited by child processes, so
// wrapping the outermost command (timeout, then xvfb-run, then leocad) is
// enough for it to apply all the way down to the actual leocad process.
const LDRAW_RENDER_NICE_LEVEL = 19;

// Rebrickable's API enforces its own rate limit (confirmed via a real 429
// during testing — hundreds of not-yet-cached parts in one batch blew
// through it almost immediately). Only the first lookup per part ever hits
// the network (resolvePartLdrawId() caches on parts.ldraw_id after that), so
// the render worker only ever needs to pace the calls that actually hit it.
const LDRAW_API_CALL_DELAY_MICROSECONDS = 350_000; // ~2.8 req/s

// The 4 angles the Pickliste's per-part detail view offers (src/pick_lists.php,
// /pick/). 'home' is LeoCAD's own default isometric view and also the ONLY
// angle every part_color_images/ldraw_render_queue row predates this feature
// with (migration 40 defaults the new 'angle' column to 'home') — keeping it
// first in this list means most parts only ever need 3 *new* renders, not 4.
// front/left/back are valid LeoCAD --viewpoint keywords giving a reasonable
// 3-flat-side spread alongside the isometric default.
const LDRAW_PICK_DETAIL_ANGLES = ['home', 'front', 'left', 'back'];

/**
 * storage/ldraw — protected from direct web access via storage/.htaccess,
 * same reasoning as storage/rebrickable: these are source/working files
 * (the downloaded archive, the extracted parts library), not the final
 * rendered images (those go into part_color_images' usual public/images/
 * location via the same storage layout the Rebrickable-CDN fetch uses).
 */
function getLdrawStorageDir(): string
{
    $dir = dirname(__DIR__) . '/storage/ldraw';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Speicherverzeichnis nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getLdrawLibraryZipPath(): string
{
    return getLdrawStorageDir() . '/complete.zip';
}

function getLdrawLibraryExtractDir(): string
{
    return getLdrawStorageDir() . '/library';
}

function getLdrawRenderTmpDir(): string
{
    $dir = getLdrawStorageDir() . '/render_tmp';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Temp-Verzeichnis nicht erstellen: ' . $dir);
    }
    return $dir;
}

/**
 * The extracted archive's root folder is itself called "ldraw" (i.e. the zip
 * contains ldraw/parts/, ldraw/p/, ldraw/LDConfig.ldr, ...) — this is both
 * LeoCAD's --libpath argument and where we read LDConfig.ldr/parts/*.dat from.
 */
function getLdrawLibraryRootDir(): string
{
    return getLdrawLibraryExtractDir() . '/ldraw';
}

function getLdrawColorConfigPath(): string
{
    return getLdrawLibraryRootDir() . '/LDConfig.ldr';
}

function getLdrawPartFilePath(string $ldrawId): string
{
    return getLdrawLibraryRootDir() . '/parts/' . $ldrawId . '.dat';
}

/**
 * Checks the things this module needs that a plain shared-hosting PHP
 * install can't provide (a shell, installable system packages) — surfaced
 * in Settings so an unsupported host gets a clear explanation instead of a
 * silently broken toggle.
 *
 * @return array{available: bool, missing: string[]}
 */
function ldrawToolsAvailable(): array
{
    $missing = [];

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        $missing[] = 'exec()';
    }

    foreach (['leocad', 'xvfb-run', 'timeout', 'nice'] as $binary) {
        $path = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if ($path === null || trim((string) $path) === '') {
            $missing[] = $binary;
        }
    }

    return ['available' => empty($missing), 'missing' => $missing];
}

function isLdrawLibraryReady(): bool
{
    return is_file(getLdrawColorConfigPath()) && is_dir(getLdrawLibraryRootDir() . '/parts');
}

/**
 * Parses LDConfig.ldr's "0 !COLOUR <name> CODE <code> VALUE #RRGGBB ...
 * [ALPHA n]" lines into [code => ['rgb' => 'RRGGBB', 'trans' => bool]] (rgb
 * has no '#', matching colors.rgb's own stored format). The ALPHA attribute
 * marks a transparent color (e.g. Trans_Red) — tracking it is what lets
 * matchLdrawColorCode() avoid pairing an opaque Rebrickable color with a
 * transparent LDraw one just because the two happen to share an RGB value
 * (confirmed on real data: Rebrickable's opaque "Red" is #C91A09, which is
 * also LDraw code 36 "Trans_Red"'s exact VALUE — LDraw's own opaque "Red",
 * code 4, is the slightly different #B40000). Cheap enough (a few hundred
 * lines) to just re-parse per call — called at most a few times per render
 * tick, not per part.
 *
 * @return array<int, array{rgb:string, trans:bool}>
 */
function getLdrawColorCodeMap(): array
{
    $path = getLdrawColorConfigPath();
    if (!is_file($path)) {
        return [];
    }

    $map = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/^0\s+!COLOUR\s+\S+\s+CODE\s+(\d+)\s+VALUE\s+#([0-9A-Fa-f]{6})/', $line, $m)) {
            $map[(int) $m[1]] = [
                'rgb' => strtoupper($m[2]),
                'trans' => (bool) preg_match('/\bALPHA\b/', $line),
            ];
        }
    }
    return $map;
}

/**
 * Rebrickable's colors.rgb and LDraw's canonical VALUE rarely differ for the
 * same named color, but "rarely" isn't "never" — falls back to nearest
 * Euclidean RGB distance so an odd/newer color still gets a plausible
 * render instead of failing outright. Only ever matches within LDraw colors
 * whose transparency (the ALPHA attribute) agrees with $isTrans — an exact
 * RGB hit against the wrong transparency group is worse than a slightly-off
 * RGB match within the right one (see getLdrawColorCodeMap()'s doc comment
 * for the real collision that motivated this). Only falls back to the full
 * palette if the matching-transparency group has nothing usable at all.
 */
function matchLdrawColorCode(string $rgbHex, bool $isTrans): ?int
{
    $rgbHex = strtoupper(ltrim($rgbHex, '#'));
    if (strlen($rgbHex) !== 6) {
        return null;
    }

    $map = getLdrawColorCodeMap();
    if (empty($map)) {
        return null;
    }

    $sameGroup = array_filter($map, fn (array $c): bool => $c['trans'] === $isTrans);
    $searchIn = !empty($sameGroup) ? $sameGroup : $map;

    foreach ($searchIn as $code => $c) {
        if ($c['rgb'] === $rgbHex) {
            return (int) $code;
        }
    }

    $target = sscanf($rgbHex, '%02x%02x%02x');
    [$tr, $tg, $tb] = $target;

    $bestCode = null;
    $bestDistance = null;
    foreach ($searchIn as $code => $c) {
        $parsed = sscanf($c['rgb'], '%02x%02x%02x');
        [$r, $g, $b] = $parsed;
        $distance = ($r - $tr) ** 2 + ($g - $tg) ** 2 + ($b - $tb) ** 2;
        if ($bestDistance === null || $distance < $bestDistance) {
            $bestDistance = $distance;
            $bestCode = (int) $code;
        }
    }
    return $bestCode;
}

/**
 * Builds a minimal single-part LDraw scene (line type 1: color + identity
 * position/rotation + the part file) and renders it headless. LeoCAD needs a
 * real (virtual) GL context to render — Qt's "offscreen" platform alone
 * wasn't enough in testing ("Error creating OpenGL context"), xvfb-run +
 * software Mesa was required.
 *
 * @return ?bool true on success, false on a genuine/permanent render failure
 *   (caller marks the pair unavailable), null if the render had to be killed
 *   for running past LDRAW_RENDER_EXEC_TIMEOUT_SECONDS — that's a transient
 *   condition, not proof the part can never render, so the caller must leave
 *   it unresolved (no DB row) rather than permanently blacklisting it.
 */
function renderLdrawPartImage(string $ldrawId, int $ldrawColorCode, string $outputPath, string $viewpoint = 'home'): ?bool
{
    $partFile = $ldrawId . '.dat';
    if (!is_file(getLdrawPartFilePath($ldrawId))) {
        return false;
    }
    // Whitelisted, not just escapeshellarg()'d — this value can originate
    // from a DB queue row (ldraw_render_queue.angle), so it must be
    // constrained to known-good LeoCAD --viewpoint keywords before ever
    // reaching exec(), not just shell-escaped.
    if (!in_array($viewpoint, LDRAW_PICK_DETAIL_ANGLES, true)) {
        $viewpoint = 'home';
    }

    $tmpDir = getLdrawRenderTmpDir();
    $snippetPath = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.ldr';
    $written = file_put_contents(
        $snippetPath,
        sprintf("1 %d 0 0 0 1 0 0 0 1 0 0 0 1 %s\n", $ldrawColorCode, $partFile)
    );
    if ($written === false) {
        return false;
    }

    $cmd = sprintf(
        'nice -n %d timeout %d xvfb-run -a env LIBGL_ALWAYS_SOFTWARE=1 leocad -l %s -i %s -w %d -h %d --stud-style %d --aa-samples %d --viewpoint %s %s 2>&1',
        LDRAW_RENDER_NICE_LEVEL,
        LDRAW_RENDER_EXEC_TIMEOUT_SECONDS,
        escapeshellarg(getLdrawLibraryRootDir()),
        escapeshellarg($outputPath),
        LDRAW_RENDER_IMAGE_SIZE,
        LDRAW_RENDER_IMAGE_SIZE,
        LDRAW_STUD_STYLE,
        LDRAW_AA_SAMPLES,
        escapeshellarg($viewpoint),
        escapeshellarg($snippetPath)
    );
    exec($cmd, $outputLines, $exitCode);
    @unlink($snippetPath);

    if ($exitCode === TIMEOUT_COMMAND_EXIT_CODE) {
        return null;
    }

    return $exitCode === 0 && is_file($outputPath) && filesize($outputPath) > 0;
}

/**
 * @return array{stage:string, zipBytes:int, zipTotalBytes:?int, extractIndex:int, extractTotal:int}
 */
function initLdrawLibraryState(): array
{
    return [
        'stage' => 'probe',
        'zipBytes' => is_file(getLdrawLibraryZipPath()) ? filesize(getLdrawLibraryZipPath()) : 0,
        'zipTotalBytes' => null,
        'extractIndex' => 0,
        'extractTotal' => 0,
    ];
}

/**
 * One bounded step of the library download+extract state machine. Mirrors
 * the Rebrickable import's per-file stage progression (probe -> download ->
 * extract -> done), just for a single archive instead of many CSVs.
 *
 * @return array{done: bool, stage: string}
 */
function stepLdrawLibraryDownload(array &$state): array
{
    if (isLdrawLibraryReady() && $state['stage'] !== 'extract') {
        $state['stage'] = 'done';
        return ['done' => true, 'stage' => 'done'];
    }

    switch ($state['stage']) {
        case 'probe':
            $probe = probeRemoteFile(LDRAW_LIBRARY_URL);
            $state['zipTotalBytes'] = $probe['size'];
            $state['stage'] = 'download';
            return ['done' => false, 'stage' => 'probe'];

        case 'download':
            $zipPath = getLdrawLibraryZipPath();
            $currentBytes = is_file($zipPath) ? filesize($zipPath) : 0;
            $totalBytes = $state['zipTotalBytes'];

            if ($totalBytes !== null && $currentBytes >= $totalBytes) {
                $state['stage'] = 'extract';
                return ['done' => false, 'stage' => 'download'];
            }

            $result = downloadFileRangeChunk(LDRAW_LIBRARY_URL, $zipPath, $currentBytes, LDRAW_DOWNLOAD_CHUNK_SIZE);
            $state['zipBytes'] = $currentBytes + $result['bytesRead'];

            if ($result['bytesRead'] < LDRAW_DOWNLOAD_CHUNK_SIZE) {
                // Short read: either done, or the host doesn't support Range
                // requests and served the whole thing in one go either way.
                $state['stage'] = 'extract';
            }
            return ['done' => false, 'stage' => 'download'];

        case 'extract':
            $extractDir = getLdrawLibraryExtractDir();
            if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
                throw new RuntimeException('Konnte Extraktionsverzeichnis nicht erstellen: ' . $extractDir);
            }

            $zip = new ZipArchive();
            if ($zip->open(getLdrawLibraryZipPath()) !== true) {
                throw new RuntimeException('Konnte LDraw-Archiv nicht öffnen.');
            }
            $state['extractTotal'] = $zip->numFiles;

            $end = min($state['extractIndex'] + LDRAW_EXTRACT_BATCH_SIZE, $zip->numFiles);
            for ($i = $state['extractIndex']; $i < $end; $i++) {
                $zip->extractTo($extractDir, [$zip->getNameIndex($i)]);
            }
            $state['extractIndex'] = $end;

            if ($end >= $zip->numFiles) {
                $zip->close();
                $state['stage'] = 'done';
                return ['done' => true, 'stage' => 'extract'];
            }
            $zip->close();
            return ['done' => false, 'stage' => 'extract'];

        default:
            return ['done' => true, 'stage' => 'done'];
    }
}

function isLdrawRenderingEnabled(): bool
{
    return getAppSetting('ldraw_rendering_enabled', '0') === '1';
}

/**
 * Cheap short-circuit for set_detail: skip building any candidate list at
 * all when the feature is off or the one-time library download hasn't
 * finished yet, so a normal page view (feature disabled, the common case)
 * costs nothing extra.
 */
function ldrawContextualRenderingReady(): bool
{
    return isLdrawRenderingEnabled() && isLdrawLibraryReady();
}

/**
 * Finds which of a set's part+color pairs (as already fetched by
 * getSetPartsList() for whichever tab is being shown) still need a
 * locally-rendered image. Sticker sheets are marked permanently
 * unavailable right here (an INSERT with a NULL path) instead of being
 * queued, since they have no 3D geometry to render — same end state a
 * failed render would reach, just without wasting a worker attempt on
 * something guaranteed to fail.
 *
 * Deliberately stops at (part_id, color_id) identity — it used to also
 * resolve ldraw_id/is_trans/ldraw_color_id here (and was called on every
 * ~1s poll while an overlay was open, so that resolution, including a
 * Rebrickable API call for ldraw_id, ran redundantly on every single poll).
 * That resolution is the render worker's job now (see
 * resolveLdrawRenderTarget()), done once when it actually claims a queue
 * row — this function only needs to know what's still missing so it can be
 * enqueued.
 *
 * @param array $items getSetPartsList()'s rows
 * @return array<int, array{part_id:int, color_id:int, part_num:string}>
 */
function getMissingLdrawRenderPairs(PDO $pdo, array $items): array
{
    $candidates = [];
    foreach ($items as $item) {
        if (($item['rebrickable_color_id'] ?? null) === null) {
            continue;
        }
        $key = $item['part_id'] . ':' . $item['rebrickable_color_id'];
        $candidates[$key] = [
            'part_id' => (int) $item['part_id'],
            'color_id' => (int) $item['rebrickable_color_id'],
            'part_num' => (string) $item['part_num'],
        ];
    }
    if (empty($candidates)) {
        return [];
    }

    $pairPlaceholders = implode(',', array_fill(0, count($candidates), '(?,?)'));
    $pairParams = [];
    foreach ($candidates as $c) {
        $pairParams[] = $c['part_id'];
        $pairParams[] = $c['color_id'];
    }

    // A pair with a 'home'-angle row already (a real path, or a NULL "can't
    // render" marker) is settled for THIS (single-angle) code path — drop it
    // before it ever reaches the queue. Scoped to 'home' specifically: a
    // part that's only had its 'front'/'left'/'back' angle rendered so far
    // (via the Pickliste's on-demand 4-view feature, src/pick_lists.php)
    // still needs its 'home' angle enqueued here, since that's the only one
    // this set_detail/location_content code path ever displays.
    $existingStmt = $pdo->prepare("SELECT part_id, color_id FROM part_color_images WHERE angle = 'home' AND (part_id, color_id) IN ($pairPlaceholders)");
    $existingStmt->execute($pairParams);
    foreach ($existingStmt->fetchAll() as $row) {
        unset($candidates[$row['part_id'] . ':' . $row['color_id']]);
    }
    if (empty($candidates)) {
        return [];
    }

    $partIds = array_values(array_unique(array_column($candidates, 'part_id')));
    $partIdPlaceholders = implode(',', array_fill(0, count($partIds), '?'));

    $stickerStmt = $pdo->prepare(
        "SELECT p.id FROM parts p
         INNER JOIN part_categories pc ON pc.part_cat_id = p.part_category
         WHERE p.id IN ($partIdPlaceholders) AND pc.name = 'Stickers'"
    );
    $stickerStmt->execute($partIds);
    $stickerPartIds = array_flip(array_map('intval', array_column($stickerStmt->fetchAll(), 'id')));

    if (!empty($stickerPartIds)) {
        $markStmt = $pdo->prepare('INSERT IGNORE INTO part_color_images (part_id, color_id, local_image_path) VALUES (?, ?, NULL)');
        foreach ($candidates as $key => $c) {
            if (isset($stickerPartIds[$c['part_id']])) {
                $markStmt->execute([$c['part_id'], $c['color_id']]);
                unset($candidates[$key]);
            }
        }
    }

    return array_values($candidates);
}

/**
 * Total distinct renderable (part_id, color_id) pairs for a set, regardless
 * of whether each one is already resolved — the denominator for
 * getLdrawSetRenderProgress()'s percentage, computed the same way
 * getMissingLdrawRenderPairs() builds its candidate list before checking
 * what's already settled.
 */
function countLdrawRenderablePairs(array $items): int
{
    $keys = [];
    foreach ($items as $item) {
        if (($item['rebrickable_color_id'] ?? null) === null) {
            continue;
        }
        $keys[$item['part_id'] . ':' . $item['rebrickable_color_id']] = true;
    }
    return count($keys);
}

/**
 * INSERT IGNOREs each pair into ldraw_render_queue — the UNIQUE key on
 * (part_id, color_id, angle) is what makes this safe to call from many
 * concurrent pollers (different sets/users sharing a part) without ever
 * double-queuing the same pair+angle. Each pair may carry an optional
 * 'angle' key; omitted, it defaults to 'home' (every existing caller's
 * behavior, unchanged) via the column's own DEFAULT.
 */
function enqueueLdrawRenders(PDO $pdo, array $pairs): void
{
    if (empty($pairs)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO ldraw_render_queue (part_id, color_id, angle) VALUES (?, ?, ?)');
    foreach ($pairs as $pair) {
        $stmt->execute([$pair['part_id'], $pair['color_id'], $pair['angle'] ?? 'home']);
    }
}

/**
 * Thin wrapper around enqueueLdrawRenders() for the Pickliste's "4 views"
 * detail button (src/pick_lists.php) — enqueues whichever of
 * LDRAW_PICK_DETAIL_ANGLES aren't already queued/rendered for one specific
 * part+color, given $rebrickableColorId (matches ldraw_render_queue.color_id
 * / part_color_images.color_id, which are keyed the same way
 * getMissingLdrawRenderPairs() already uses — Rebrickable's own color_id,
 * not the colors.id surrogate storage_items uses).
 */
function enqueueLdrawRenderAngles(PDO $pdo, int $partId, int $rebrickableColorId, array $angles): void
{
    $pairs = [];
    foreach ($angles as $angle) {
        $pairs[] = ['part_id' => $partId, 'color_id' => $rebrickableColorId, 'angle' => $angle];
    }
    enqueueLdrawRenders($pdo, $pairs);
}

/**
 * The web-facing half of rendering: enqueues whatever's still missing for
 * this set (cheap — no exec(), no Rebrickable API call) and reports overall
 * progress, including what the persistent worker (see
 * runLdrawRenderWorkerOnce()) is doing right now, so the polling overlay can
 * show it. Replaces the old session-persisted stepLdrawSetRenderBatch() tick
 * entirely — every call here is self-contained and safe to repeat, so no
 * per-set state needs to survive between polls.
 *
 * @param array $items getSetPartsList()'s rows
 * @return array{status:string, percent:int, done:int, total:int, currentPart:?string, queueDepth:int}
 */
function getLdrawSetRenderProgress(PDO $pdo, array $items): array
{
    $total = countLdrawRenderablePairs($items);
    $missing = getMissingLdrawRenderPairs($pdo, $items);
    enqueueLdrawRenders($pdo, $missing);

    $done = $total - count($missing);
    $percent = $total > 0 ? (int) round(min(1.0, $done / $total) * 100) : 100;
    $queueStatus = getLdrawQueueStatus($pdo);

    return [
        'status' => count($missing) > 0 ? 'running' : 'done',
        'percent' => $percent,
        'done' => $done,
        'total' => $total,
        'currentPart' => $queueStatus['currentPart'],
        'queueDepth' => $queueStatus['queueDepth'],
    ];
}

/**
 * The 4 cached angle images for one part+color, whichever already exist —
 * one query, keyed by angle. A NULL local_image_path (a permanently
 * unrenderable pair, e.g. a sticker) shows up the same as any other
 * settled/non-missing angle, distinguished from "not yet rendered" (key
 * absent entirely). Used directly by the Pickliste's active-picking screen
 * (renderPickListActive(), src/pick_pages.php) to build its image gallery —
 * rendering itself was already queued in full at pick-list creation time
 * (enqueueLdrawAnglesForPickListItems(), src/pick_lists.php), so this just
 * reads back whatever's finished by the time the user gets to this item, no
 * polling/enqueueing needed here.
 *
 * @return array<string, ?string> angle => local_image_path, only for angles that already have a row
 */
function getLdrawFourAngleImages(PDO $pdo, int $partId, int $rebrickableColorId): array
{
    $stmt = $pdo->prepare(
        'SELECT angle, local_image_path FROM part_color_images WHERE part_id = ? AND color_id = ? AND angle IN (' .
        implode(',', array_fill(0, count(LDRAW_PICK_DETAIL_ANGLES), '?')) . ')'
    );
    $stmt->execute(array_merge([$partId, $rebrickableColorId], LDRAW_PICK_DETAIL_ANGLES));
    $images = [];
    foreach ($stmt->fetchAll() as $row) {
        $images[$row['angle']] = $row['local_image_path'];
    }
    return $images;
}

/**
 * What the persistent render worker is doing right now — shared by
 * getLdrawSetRenderProgress() and any other caller that enqueues renders
 * and wants to show live status (see the location Explorer's loose-parts
 * enqueueing in src/routes/actions.php).
 *
 * @return array{currentPart: ?string, queueDepth: int}
 */
function getLdrawQueueStatus(PDO $pdo): array
{
    $currentPart = $pdo->query(
        "SELECT p.part_num FROM ldraw_render_queue q
         INNER JOIN parts p ON p.id = q.part_id
         WHERE q.status = 'rendering' LIMIT 1"
    )->fetchColumn();

    // The queue's total row count (not just status='pending') so the number
    // shown next to "currently rendering: X" is everything still waiting
    // *behind* X, not a separate/contradictory-looking count — a pair being
    // actively rendered has status='rendering', not 'pending', so counting
    // only 'pending' rows made "0 in queue" show up right next to "currently
    // rendering: X", which read as a contradiction.
    $totalQueued = (int) $pdo->query('SELECT COUNT(*) FROM ldraw_render_queue')->fetchColumn();
    $queueDepth = $currentPart !== false ? max(0, $totalQueued - 1) : $totalQueued;

    return [
        'currentPart' => $currentPart !== false ? (string) $currentPart : null,
        'queueDepth' => $queueDepth,
    ];
}

// A hanging render (killed by LDRAW_RENDER_EXEC_TIMEOUT_SECONDS) is sent to
// the back of the queue and retried rather than given up on immediately —
// but not forever, so one genuinely-broken part can't tie up the single
// worker indefinitely across repeated attempts.
const LDRAW_RENDER_MAX_ATTEMPTS = 5;

/**
 * Resolves the one thing the queue itself doesn't carry: which LDraw part
 * file and color code a (part_id, color_id) pair renders as. Mirrors what
 * getMissingLdrawRenderPairs() used to precompute for a whole set at once,
 * but done per-item, at claim time, only by the worker that's actually about
 * to render it — see runLdrawRenderWorkerOnce().
 *
 * @return array{ldrawId: ?string, ldrawColorCode: ?int}
 */
function resolveLdrawRenderTarget(PDO $pdo, int $partId, string $partNum, int $colorId): array
{
    $cachedStmt = $pdo->prepare('SELECT ldraw_id FROM parts WHERE id = ?');
    $cachedStmt->execute([$partId]);
    $cached = $cachedStmt->fetchColumn();
    $hitNetwork = $cached === false || $cached === null;

    $ldrawId = resolvePartLdrawId($pdo, $partId, $partNum);
    if ($hitNetwork) {
        // Only the first lookup per part ever reaches Rebrickable's API
        // (resolvePartLdrawId() caches on parts.ldraw_id after that) — see
        // LDRAW_API_CALL_DELAY_MICROSECONDS' doc comment for why this is
        // paced at all.
        usleep(LDRAW_API_CALL_DELAY_MICROSECONDS);
    }

    $colorStmt = $pdo->prepare('SELECT rgb, is_trans, ldraw_color_id FROM colors WHERE color_id = ?');
    $colorStmt->execute([$colorId]);
    $colorRow = $colorStmt->fetch();

    // Rebrickable's own mapping (synced by syncExternalColorIds()) is
    // authoritative where it exists; matchLdrawColorCode()'s RGB-nearest-
    // neighbor guess only kicks in for colors Rebrickable doesn't map at all.
    $storedLdrawColorId = $colorRow['ldraw_color_id'] ?? null;
    $ldrawColorCode = $storedLdrawColorId !== null
        ? (int) $storedLdrawColorId
        : (($colorRow['rgb'] ?? null) !== null ? matchLdrawColorCode($colorRow['rgb'], !empty($colorRow['is_trans'])) : null);

    return ['ldrawId' => $ldrawId, 'ldrawColorCode' => $ldrawColorCode];
}

/**
 * One claim-resolve-render-settle cycle for the persistent LDraw render
 * worker (bin/ldraw_render_worker.php). Runs entirely outside any web
 * request, so a render taking the 60-120s it normally takes on this
 * hardware never ties up an Apache worker or risks an external gateway's
 * own request timeout — both real problems with the old design, where
 * stepLdrawSetRenderBatch() rendered synchronously inside
 * action=ldraw_set_render_tick.
 *
 * @return bool true if a queue row was claimed and processed, false if the
 *   queue was empty (caller should back off before calling again)
 */
function runLdrawRenderWorkerOnce(PDO $pdo): bool
{
    $pdo->beginTransaction();
    $row = $pdo->query(
        "SELECT id, part_id, color_id, angle, attempts FROM ldraw_render_queue
         WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
    )->fetch();
    if (!$row) {
        $pdo->rollBack();
        return false;
    }
    $pdo->prepare("UPDATE ldraw_render_queue SET status = 'rendering', started_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    $pdo->commit();

    $upsertStmt = $pdo->prepare(
        'INSERT INTO part_color_images (part_id, color_id, angle, local_image_path)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE local_image_path = VALUES(local_image_path), fetched_at = CURRENT_TIMESTAMP'
    );
    $deleteStmt = $pdo->prepare('DELETE FROM ldraw_render_queue WHERE id = ?');
    $requeueStmt = $pdo->prepare(
        "UPDATE ldraw_render_queue SET status = 'pending', started_at = NULL, attempts = ?, created_at = NOW() WHERE id = ?"
    );

    try {
        $partNumStmt = $pdo->prepare('SELECT part_num FROM parts WHERE id = ?');
        $partNumStmt->execute([$row['part_id']]);
        $partNum = (string) $partNumStmt->fetchColumn();

        $target = resolveLdrawRenderTarget($pdo, (int) $row['part_id'], $partNum, (int) $row['color_id']);

        if ($target['ldrawId'] === null || $target['ldrawColorCode'] === null) {
            // Definitively resolved either way (confirmed no LDraw mapping, or
            // no usable color) — permanent, mark it rather than re-attempting.
            $upsertStmt->execute([$row['part_id'], $row['color_id'], $row['angle'], null]);
            $deleteStmt->execute([$row['id']]);
            return true;
        }

        // Angle suffix only for non-'home' renders, so every filename/path
        // this feature predates (all of them 'home') stays byte-for-byte
        // unchanged — no migration/rename pass needed for existing renders.
        $angleSuffix = $row['angle'] !== 'home' ? '_' . $row['angle'] : '';
        $filename = $row['color_id'] . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $target['ldrawId']) . $angleSuffix . '.png';
        $shard = getImageShard($filename);
        $dir = getImageStorageDir('part_color_images', $shard);
        $absolutePath = $dir . '/' . $filename;
        $relativePath = getImageRelativePath('part_color_images', $shard, $filename);

        $renderResult = renderLdrawPartImage($target['ldrawId'], $target['ldrawColorCode'], $absolutePath, $row['angle']);
    } catch (Throwable $e) {
        // A Rebrickable API hiccup during resolveLdrawRenderTarget() (network
        // blip, transient 5xx) is not a verdict on the part — send it to the
        // back of the queue like a render timeout, rather than leaving the
        // row stuck in 'rendering' forever or wrongly blacklisting it.
        $requeueStmt->execute([(int) $row['attempts'] + 1, $row['id']]);
        throw $e;
    }

    if ($renderResult === true) {
        $upsertStmt->execute([$row['part_id'], $row['color_id'], $row['angle'], $relativePath]);
        $deleteStmt->execute([$row['id']]);
    } elseif ($renderResult === false) {
        // The .dat file wasn't found in the library — a real (if rare) gap,
        // permanent for this library version.
        $upsertStmt->execute([$row['part_id'], $row['color_id'], $row['angle'], null]);
        $deleteStmt->execute([$row['id']]);
    } else {
        // null: killed by LDRAW_RENDER_EXEC_TIMEOUT_SECONDS — transient, not
        // proof this part can never render. Sent to the back of the queue
        // (fresh created_at) so a single hanging part can't monopolize the
        // only worker; only given up on after repeated attempts.
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= LDRAW_RENDER_MAX_ATTEMPTS) {
            $upsertStmt->execute([$row['part_id'], $row['color_id'], $row['angle'], null]);
            $deleteStmt->execute([$row['id']]);
        } else {
            $requeueStmt->execute([$attempts, $row['id']]);
        }
    }

    return true;
}

/**
 * A full-screen dimming overlay with a circular progress ring, shown over a
 * set's inventory tab while its still-missing part+color images render —
 * self-contained (own inline script, own tick loop against
 * action=ldraw_set_render_tick) so it can just be appended to $content
 * without the caller needing to know anything about how rendering works.
 * Reloads the page once done so the now-available images show up the same
 * way any other freshly-rendered part_color_images row would.
 */
function renderLdrawRenderOverlay(int $inventoryId): string
{
    $labelsJson = json_encode([
        'message' => t('ldraw_set_render_message'),
        'errorMessage' => t('ldraw_set_render_error'),
        'skipLabel' => t('ldraw_set_render_skip'),
        // Left with literal {part}/{count} placeholders (t() only replaces
        // what's passed in $vars) so the client can substitute live values
        // into it on every poll, rather than re-fetching a translation.
        'currentTemplate' => t('ldraw_set_render_current'),
        'waitingMessage' => t('ldraw_set_render_waiting'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html = '<div class="ldraw-render-overlay" id="ldraw-render-overlay">';
    $html .= '<div class="ldraw-render-panel">';
    $html .= '<div class="ldraw-render-ring-wrap">';
    $html .= '<svg class="ldraw-render-ring" viewBox="0 0 100 100" aria-hidden="true">';
    $html .= '<circle class="ldraw-render-ring-bg" cx="50" cy="50" r="45"></circle>';
    $html .= '<circle class="ldraw-render-ring-fg" id="ldraw-render-ring-fg" cx="50" cy="50" r="45"></circle>';
    $html .= '</svg>';
    $html .= '<span class="ldraw-render-percent" id="ldraw-render-percent">0%</span>';
    $html .= '</div>';
    $html .= '<p class="ldraw-render-message" id="ldraw-render-message"></p>';
    $html .= '<p class="ldraw-render-status" id="ldraw-render-status"></p>';
    $html .= '<button type="button" class="ldraw-render-skip" id="ldraw-render-skip">' . htmlspecialchars(t('ldraw_set_render_skip')) . '</button>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var overlay = document.getElementById("ldraw-render-overlay");
  var ringFg = document.getElementById("ldraw-render-ring-fg");
  var percentLabel = document.getElementById("ldraw-render-percent");
  var messageLabel = document.getElementById("ldraw-render-message");
  var statusLabel = document.getElementById("ldraw-render-status");
  var skipButton = document.getElementById("ldraw-render-skip");
  if (!overlay || !ringFg || !percentLabel || !messageLabel || !statusLabel || !skipButton) {
    return;
  }

  var circumference = 2 * Math.PI * 45;
  ringFg.style.strokeDasharray = circumference.toFixed(2);
  messageLabel.textContent = texts.message;

  var stopped = false;
  var consecutiveFailures = 0;
  var maxAutoRetries = 4;

  function setPercent(percent) {
    percentLabel.textContent = percent + "%";
    ringFg.style.strokeDashoffset = (circumference * (1 - percent / 100)).toFixed(2);
  }
  setPercent(0);

  function updateStatusLine(data) {
    if (data.currentPart) {
      statusLabel.textContent = texts.currentTemplate
        .replace('{part}', data.currentPart)
        .replace('{count}', data.queueDepth);
    } else if (data.queueDepth > 0) {
      statusLabel.textContent = texts.waitingMessage;
    } else {
      statusLabel.textContent = '';
    }
  }

  skipButton.addEventListener("click", function() {
    stopped = true;
    overlay.style.display = "none";
  });

  async function tick() {
    var formData = new FormData();
    formData.set('action', 'ldraw_set_render_tick');
    formData.set('inventory_id', '$inventoryId');
    var response = await fetch('?', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    if (!response.ok && response.status !== 500) {
      throw new Error('tick failed with status ' + response.status);
    }
    return await response.json();
  }

  function loop() {
    if (stopped) {
      return;
    }
    tick().then(function(data) {
      consecutiveFailures = 0;
      setPercent(data.percent || 0);
      updateStatusLine(data);
      if (data.status === 'done') {
        window.location.reload();
        return;
      }
      if (data.status === 'error') {
        messageLabel.textContent = data.message || texts.errorMessage;
        return;
      }
      // Rendering itself now happens in a separate persistent worker, not
      // in this request — this tick only enqueues and reports state, so
      // it's always fast and there's no lock-contention backoff to make
      // anymore; a flat ~1s poll is plenty responsive.
      setTimeout(loop, 1000);
    }).catch(function() {
      consecutiveFailures++;
      if (consecutiveFailures <= maxAutoRetries) {
        setTimeout(loop, 1000 * consecutiveFailures);
        return;
      }
      messageLabel.textContent = texts.errorMessage;
    });
  }

  loop();
})();
</script>
SCRIPT;

    return $html;
}

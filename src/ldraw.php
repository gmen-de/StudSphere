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

// Originally 20s, on the reasoning that this module only runs on a
// self-hosted install with a real admin behind it, so a long tick wasn't a
// shared-hosting execution-time problem the way IMAGE_DOWNLOAD_TIME_BUDGET_SECONDS's
// 4s is. In practice a 20s tick is long enough to itself trip an SSL
// offloader/reverse proxy's own gateway timeout (confirmed: a real HTTP 504
// while viewing sets with lots of missing renders) — especially with several
// set pages open at once, each running its own tick loop and competing for
// CPU. Kept short instead; see also stepLdrawSetRenderBatch()'s render lock,
// which handles the multi-tab CPU contention half of the same problem.
const LDRAW_RENDER_TIME_BUDGET_SECONDS = 5.0;
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
// otherwise block the request holding stepLdrawSetRenderBatch()'s render
// lock indefinitely, tying up an Apache worker forever instead of just for a
// while. 180s is well above any observed legitimate render, so this is a
// safety net against a true hang, not a budget for normal renders — the
// existing per-tick 5s check (LDRAW_RENDER_TIME_BUDGET_SECONDS) already
// accepts that a single render can and normally does run well past it.
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
// the network (resolvePartLdrawId() caches on parts.ldraw_id after that),
// so throttling just the network-hitting calls keeps a normal tick fast
// while still respecting the limit on a library's first full pass.
const LDRAW_MAX_API_CALLS_PER_TICK = 15;
const LDRAW_API_CALL_DELAY_MICROSECONDS = 350_000; // ~2.8 req/s

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

function getLdrawRenderLockPath(): string
{
    return getLdrawStorageDir() . '/render.lock';
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
function renderLdrawPartImage(string $ldrawId, int $ldrawColorCode, string $outputPath): ?bool
{
    $partFile = $ldrawId . '.dat';
    if (!is_file(getLdrawPartFilePath($ldrawId))) {
        return false;
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
        'nice -n %d timeout %d xvfb-run -a env LIBGL_ALWAYS_SOFTWARE=1 leocad -l %s -i %s -w %d -h %d --stud-style %d --aa-samples %d --viewpoint home %s 2>&1',
        LDRAW_RENDER_NICE_LEVEL,
        LDRAW_RENDER_EXEC_TIMEOUT_SECONDS,
        escapeshellarg(getLdrawLibraryRootDir()),
        escapeshellarg($outputPath),
        LDRAW_RENDER_IMAGE_SIZE,
        LDRAW_RENDER_IMAGE_SIZE,
        LDRAW_STUD_STYLE,
        LDRAW_AA_SAMPLES,
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
 * handed to the render step, since they have no 3D geometry to render —
 * same end state a failed render would reach, just without wasting an
 * attempt on something guaranteed to fail.
 *
 * @param array $items getSetPartsList()'s rows
 * @return array<int, array{part_id:int, color_id:int, part_num:string, ldraw_id:?string, rgb:?string}>
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
            'rgb' => $item['color_rgb'] ?? null,
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

    // A pair with ANY row already (a real path, or a NULL "can't render"
    // marker) is settled — drop it before it ever reaches the render step.
    $existingStmt = $pdo->prepare("SELECT part_id, color_id FROM part_color_images WHERE (part_id, color_id) IN ($pairPlaceholders)");
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
    if (empty($candidates)) {
        return [];
    }

    $ldrawIdStmt = $pdo->prepare("SELECT id, ldraw_id FROM parts WHERE id IN ($partIdPlaceholders)");
    $ldrawIdStmt->execute($partIds);
    $ldrawIdByPart = array_column($ldrawIdStmt->fetchAll(), 'ldraw_id', 'id');

    // getSetPartsList()'s rows carry color_rgb but not is_trans/ldraw_color_id
    // — is_trans is needed so matchLdrawColorCode() only matches within the
    // correct opaque/transparent LDraw color group (see its doc comment);
    // ldraw_color_id (see syncExternalColorIds() in src/rebrickable.php) is
    // Rebrickable's own authoritative color mapping, preferred over
    // matchLdrawColorCode()'s RGB-nearest-neighbor guess wherever it's set.
    $colorIds = array_values(array_unique(array_column($candidates, 'color_id')));
    $colorIdPlaceholders = implode(',', array_fill(0, count($colorIds), '?'));
    $transStmt = $pdo->prepare("SELECT color_id, is_trans, ldraw_color_id FROM colors WHERE color_id IN ($colorIdPlaceholders)");
    $transStmt->execute($colorIds);
    $colorRows = $transStmt->fetchAll();
    $isTransByColor = array_column($colorRows, 'is_trans', 'color_id');
    $ldrawColorIdByColor = array_column($colorRows, 'ldraw_color_id', 'color_id');

    foreach ($candidates as $key => $c) {
        $candidates[$key]['ldraw_id'] = $ldrawIdByPart[$c['part_id']] ?? null;
        $candidates[$key]['is_trans'] = !empty($isTransByColor[$c['color_id']] ?? 0);
        $storedLdrawColorId = $ldrawColorIdByColor[$c['color_id']] ?? null;
        $candidates[$key]['ldraw_color_id'] = $storedLdrawColorId !== null ? (int) $storedLdrawColorId : null;
    }

    return array_values($candidates);
}

/**
 * @param array<int, array{part_id:int, color_id:int, part_num:string, ldraw_id:?string, rgb:?string, is_trans:bool, ldraw_color_id:?int}> $pairs
 * @return array{pairs:array, index:int, stats:array{processed:int, rendered:int, skipped:int, errors:int}}
 */
function initLdrawSetRenderState(array $pairs): array
{
    return [
        'pairs' => array_values($pairs),
        'index' => 0,
        'stats' => ['processed' => 0, 'rendered' => 0, 'skipped' => 0, 'errors' => 0, 'timedOut' => 0],
    ];
}

/**
 * One time-budgeted batch over a fixed, already-known list of pairs (a
 * single set's missing renders — typically dozens to a few hundred, not
 * the whole catalog), so a plain index cursor is enough; no DB-side
 * "already exists" re-check is needed per row since getMissingLdrawRenderPairs()
 * already did that once up front.
 *
 * A pair only ever gets a permanent NULL "can't render" marker for
 * definitive reasons (Rebrickable confirms no LDraw mapping, no usable
 * color match, or the .dat file is missing from the library) — a
 * Rebrickable API hiccup leaves no row at all, so the pair is simply
 * missing again (and retried) whenever any set containing it is next
 * viewed, rather than being wrongly marked unavailable forever.
 *
 * @return array{done: bool, lockBusy?: bool}
 */
function stepLdrawSetRenderBatch(array &$state): array
{
    $pdo = getPDO();
    $pairs = $state['pairs'];
    $total = count($pairs);

    if ($state['index'] >= $total) {
        return ['done' => true];
    }

    // Only one render loop across the whole app runs at a time — several set
    // pages left open at once would otherwise each spawn their own tick loop
    // and compete for CPU on the same leocad+Xvfb renders, which is exactly
    // what pushed a single tick's response time past an SSL offloader's
    // gateway timeout (see LDRAW_RENDER_TIME_BUDGET_SECONDS's doc comment).
    // Non-blocking trylock: a tick that loses the race does no work and
    // returns immediately instead of queuing up behind the lock holder.
    // 'lockBusy' tells the client's tick loop to back off hard instead of
    // polling again almost immediately — with several set pages open, each
    // losing this race, hammering the lock every tick is exactly the request
    // flood that made the server unresponsive under real use (a single
    // render legitimately takes 60-120s, so a losing tab has no reason to
    // ask again in under a couple of seconds).
    $lockHandle = fopen(getLdrawRenderLockPath(), 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if ($lockHandle !== false) {
            fclose($lockHandle);
        }
        return ['done' => false, 'lockBusy' => true];
    }

    try {
        $upsertStmt = $pdo->prepare(
            'INSERT INTO part_color_images (part_id, color_id, local_image_path)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE local_image_path = VALUES(local_image_path), fetched_at = CURRENT_TIMESTAMP'
        );

        $start = microtime(true);
        $networkCalls = 0;
        $consecutiveApiFailures = 0;

        while ($state['index'] < $total) {
            $pair = $pairs[$state['index']];
            $needsApiCall = $pair['ldraw_id'] === null;

            if ($needsApiCall && ($networkCalls >= LDRAW_MAX_API_CALLS_PER_TICK || $consecutiveApiFailures >= 3)) {
                // Per-tick cap reached, or the API looks unavailable right now
                // — stop attempting more lookups this tick. $state['index']
                // isn't advanced past this pair, so the next tick retries it.
                break;
            }

            if ($needsApiCall) {
                if ($networkCalls > 0) {
                    usleep(LDRAW_API_CALL_DELAY_MICROSECONDS);
                }
                $networkCalls++;
            }

            try {
                $ldrawId = $needsApiCall
                    ? resolvePartLdrawId($pdo, $pair['part_id'], $pair['part_num'])
                    : ($pair['ldraw_id'] !== '' ? $pair['ldraw_id'] : null);
                $consecutiveApiFailures = 0;
            } catch (Throwable $e) {
                if ($needsApiCall) {
                    $consecutiveApiFailures++;
                }
                $state['index']++;
                $state['stats']['processed']++;
                $state['stats']['errors']++;
                continue;
            }

            $state['index']++;
            $state['stats']['processed']++;

            // Rebrickable's own mapping (synced by syncExternalColorIds())
            // is authoritative where it exists; matchLdrawColorCode()'s
            // RGB-nearest-neighbor guess only kicks in for the ~39% of
            // colors Rebrickable doesn't map to an LDraw color at all.
            $ldrawColorCode = $pair['ldraw_color_id']
                ?? ($pair['rgb'] !== null ? matchLdrawColorCode($pair['rgb'], $pair['is_trans']) : null);

            if ($ldrawId === null || $ldrawColorCode === null) {
                // Definitively resolved either way (confirmed no LDraw mapping,
                // or no usable color) — permanent, mark it.
                $upsertStmt->execute([$pair['part_id'], $pair['color_id'], null]);
                $state['stats']['skipped']++;
                continue;
            }

            $filename = $pair['color_id'] . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $ldrawId) . '.png';
            $shard = getImageShard($filename);
            $dir = getImageStorageDir('part_color_images', $shard);
            $absolutePath = $dir . '/' . $filename;
            $relativePath = getImageRelativePath('part_color_images', $shard, $filename);

            $renderResult = renderLdrawPartImage($ldrawId, $ldrawColorCode, $absolutePath);
            if ($renderResult === true) {
                $upsertStmt->execute([$pair['part_id'], $pair['color_id'], $relativePath]);
                $state['stats']['rendered']++;
            } elseif ($renderResult === false) {
                // The .dat file wasn't found in the library — a real (if rare)
                // gap, permanent for this library version, so it's marked too
                // rather than re-attempted every time this part comes up.
                $upsertStmt->execute([$pair['part_id'], $pair['color_id'], null]);
                $state['stats']['errors']++;
            } else {
                // null: killed by LDRAW_RENDER_EXEC_TIMEOUT_SECONDS — transient,
                // not proof this part can never render. Deliberately no upsert
                // here, so this pair is left unresolved and simply retried
                // whenever a set containing it is next viewed, same as one
                // that's never been attempted at all.
                $state['stats']['timedOut']++;
            }

            if ((microtime(true) - $start) >= LDRAW_RENDER_TIME_BUDGET_SECONDS) {
                break;
            }
        }

        return ['done' => $state['index'] >= $total, 'lockBusy' => false];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/**
 * @return array{status:string, percent:int, processed:int, total:int, rendered:int, skipped:int, errors:int, lockBusy:bool}
 */
function buildLdrawSetRenderProgressPayload(array $state, bool $done, bool $lockBusy = false): array
{
    $total = count($state['pairs']);
    $processed = $state['stats']['processed'];
    $percent = $total > 0 ? (int) round(min(1.0, $processed / $total) * 100) : 100;

    return [
        'status' => $done ? 'done' : 'running',
        'percent' => $done ? 100 : $percent,
        'processed' => $processed,
        'total' => $total,
        'lockBusy' => $lockBusy,
        'rendered' => $state['stats']['rendered'],
        'skipped' => $state['stats']['skipped'],
        'errors' => $state['stats']['errors'],
    ];
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
  var skipButton = document.getElementById("ldraw-render-skip");
  if (!overlay || !ringFg || !percentLabel || !messageLabel || !skipButton) {
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
      if (data.status === 'done') {
        window.location.reload();
        return;
      }
      if (data.status === 'error') {
        messageLabel.textContent = data.message || texts.errorMessage;
        return;
      }
      // A single render legitimately takes 60-120s, so a tick that lost the
      // render.lock race (another set page is already working) has no
      // reason to ask again in under a couple of seconds — with several set
      // pages open at once, all polling as fast as possible here is exactly
      // what turned into a request flood against the server.
      setTimeout(loop, data.lockBusy ? 3000 : 500);
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/owned_sets.php';
require_once __DIR__ . '/rebrickable.php';

/**
 * BrickLink's price guide (average price of currently-listed items, per
 * condition) for the user's owned sets — read-only, best-effort enrichment,
 * fetched from BrickLink's own public catalog pages rather than their
 * official Store API (which requires a seller account the user doesn't
 * have, see the conversation this feature came out of). Two pages are
 * involved:
 *
 * - catalogitem.page?S={set_num} — the normal catalog page a browser would
 *   load; its inline JS embeds "idItem: <n>", BrickLink's own internal
 *   numeric catalog id (distinct from the set number). Resolved once per
 *   set and cached in sets.bricklink_item_id forever.
 * - catalogitem_pgtab.page?idItem=<n>&... — the price-guide tab's own
 *   content, loaded via AJAX by a real browser. Its first data row is a
 *   fixed 4-column summary (Last 6 Months Sales: New/Used, Current Items
 *   for Sale: New/Used), each a small, consistently-formatted table — that
 *   row is all this parses; the much larger per-listing breakdown below it
 *   is ignored.
 *
 * Neither page sits behind BrickLink's AWS WAF bot challenge (confirmed by
 * hand — only the older catalogPG.asp endpoint does), so a plain HTTP GET
 * with a normal browser User-Agent works. This is still scraping a public
 * page rather than using an API meant for this, so every call site in this
 * app is deliberately low-frequency and throttled — see
 * stepBricklinkPriceSync()'s doc comment.
 */
const BRICKLINK_PRICE_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const BRICKLINK_PRICE_HTTP_TIMEOUT_SECONDS = 8;

/**
 * Same file_get_contents-then-curl-fallback pattern as fetchLatestRelease()
 * in src/updater.php, with a short timeout so a slow/unreachable BrickLink
 * never meaningfully delays whatever page opportunistically triggered this.
 */
function fetchBricklinkPage(string $url): ?string
{
    $headers = 'User-Agent: ' . BRICKLINK_PRICE_USER_AGENT . "\r\n";
    $context = stream_context_create([
        'http' => ['header' => $headers, 'timeout' => BRICKLINK_PRICE_HTTP_TIMEOUT_SECONDS, 'ignore_errors' => true],
    ]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: ' . BRICKLINK_PRICE_USER_AGENT]);
        curl_setopt($ch, CURLOPT_TIMEOUT, BRICKLINK_PRICE_HTTP_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $status >= 400) {
            return null;
        }
    } elseif ($response === false) {
        return null;
    }

    return (string) $response;
}

/**
 * BrickLink's own internal numeric catalog id for a set number (e.g.
 * "4558-1") — extracted from the catalog page's inline "idItem: <n>" JS
 * assignment. Null if the page didn't load or the set isn't in BrickLink's
 * catalog at all.
 */
function resolveBricklinkItemId(string $setNum): ?int
{
    $url = 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . urlencode($setNum);
    $html = fetchBricklinkPage($url);
    if ($html === null) {
        return null;
    }
    if (preg_match('/idItem:\s*(\d+)/', $html, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Same as resolveBricklinkItemId(), for a minifig's own BrickLink catalog
 * page (?M=<code>) instead of a set's (?S=<setnum>). The input here is
 * BrickLink's own alphanumeric minifig code (e.g. "sw0001a", minifigs.
 * bricklink_id — resolved separately via getOrFetchBricklinkMinifigId(),
 * src/owned_sets.php), not the Rebrickable fig_num; every catalog page,
 * regardless of item type, embeds the same "idItem: <n>" numeric id this
 * extracts.
 */
function resolveBricklinkMinifigCatalogItemId(string $bricklinkId): ?int
{
    $url = 'https://www.bricklink.com/v2/catalog/catalogitem.page?M=' . urlencode($bricklinkId);
    $html = fetchBricklinkPage($url);
    if ($html === null) {
        return null;
    }
    if (preg_match('/idItem:\s*(\d+)/', $html, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Same as resolveBricklinkItemId(), for a part's own BrickLink catalog page
 * (?P=<bricklink_part_id>) instead of a set's (?S=<setnum>) — no color
 * parameter: confirmed live that a part's idItem is the same regardless of
 * color (catalogitem.page?P=3024 and ?P=3024&C=11 both embed idItem: 381),
 * so this only ever needs to run once per part, cached on
 * parts.bricklink_item_id, and reused for every color that part comes in.
 */
function resolveBricklinkPartItemId(string $bricklinkPartId): ?int
{
    $url = 'https://www.bricklink.com/v2/catalog/catalogitem.page?P=' . urlencode($bricklinkPartId);
    $html = fetchBricklinkPage($url);
    if ($html === null) {
        return null;
    }
    if (preg_match('/idItem:\s*(\d+)/', $html, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * One condition's ("New"/"Used") "Current Items for Sale" figures from one
 * pcipgSummaryTable HTML fragment. Null fields mean that line wasn't
 * present (e.g. no active listings at all for that condition).
 *
 * @return array{lots: ?int, minPrice: ?float, avgPrice: ?float, maxPrice: ?float, currency: ?string}
 */
function parseBricklinkSummaryTable(string $tableHtml): array
{
    $result = ['lots' => null, 'minPrice' => null, 'avgPrice' => null, 'maxPrice' => null, 'currency' => null];

    if (preg_match('/Total Lots:<\/TD><TD><b>(\d+)<\/b>/i', $tableHtml, $m)) {
        $result['lots'] = (int) $m[1];
    }
    if (preg_match('/Min Price:<\/TD><TD><b>([A-Z]{3})\s*([\d,]+\.\d+)<\/b>/i', $tableHtml, $m)) {
        $result['minPrice'] = (float) str_replace(',', '', $m[2]);
        $result['currency'] = $m[1];
    }
    if (preg_match('/Avg Price:<\/TD><TD><b>([A-Z]{3})\s*([\d,]+\.\d+)<\/b>/i', $tableHtml, $m)) {
        $result['avgPrice'] = (float) str_replace(',', '', $m[2]);
        $result['currency'] = $m[1];
    }
    if (preg_match('/Max Price:<\/TD><TD><b>([A-Z]{3})\s*([\d,]+\.\d+)<\/b>/i', $tableHtml, $m)) {
        $result['maxPrice'] = (float) str_replace(',', '', $m[2]);
        $result['currency'] = $m[1];
    }

    return $result;
}

/**
 * Fetches and parses the "Current Items for Sale" New/Used summary for one
 * BrickLink catalog item id. currency=2 is EUR — BrickLink's price guide
 * always needs a currency parameter and this app has no per-user currency
 * preference (yet), so it's a fixed default matching StudSphere's actual
 * userbase. Null if the page couldn't be loaded or didn't have the expected
 * 4-table summary row at all.
 *
 * $bricklinkColorId is for parts (BrickLink prices are color-specific,
 * unlike sets/minifigs which pass null here): confirmed live against real
 * BrickLink responses that idItem alone is NOT enough to get a per-color
 * price guide for a part — the page instead returns an unfiltered, multi-MB
 * dump of every listing across every color. Adding &idColor=<id> (not
 * colorID, not C — both wrong guesses, live-tested) plus gm=1 instead of
 * gm=0 is the confirmed-working combination; gm's exact meaning isn't known,
 * only that this is the parameter set BrickLink's own part pages actually
 * use. Left null (sets/minifigs), the URL and behavior are unchanged.
 *
 * @return array{currency: ?string, new: array, used: array}|null
 */
function fetchBricklinkPriceGuide(int $itemId, ?int $bricklinkColorId = null): ?array
{
    $url = 'https://www.bricklink.com/v2/catalog/catalogitem_pgtab.page'
        . '?idItem=' . $itemId;
    if ($bricklinkColorId !== null) {
        $url .= '&idColor=' . $bricklinkColorId . '&st=2&gm=1&gc=0&ei=0&prec=2&showflag=0&showbulk=0&currency=2';
    } else {
        $url .= '&st=2&gm=0&gc=0&ei=0&prec=2&showflag=0&showbulk=0&currency=2';
    }
    $html = fetchBricklinkPage($url);
    if ($html === null) {
        return null;
    }

    preg_match_all('/CLASS="pcipgSummaryTable">(.*?)<\/TABLE>/is', $html, $matches);
    $tables = $matches[1] ?? [];
    // Column order matches the page's own header row: Last-6mo New, Last-6mo
    // Used, Current New, Current Used — only the last two (indexes 2/3) are
    // "currently for sale", which is the only figure this app shows.
    if (count($tables) < 4) {
        return null;
    }

    $new = parseBricklinkSummaryTable($tables[2]);
    $used = parseBricklinkSummaryTable($tables[3]);

    return [
        'currency' => $new['currency'] ?? $used['currency'],
        'new' => $new,
        'used' => $used,
    ];
}

/**
 * Resolves (if needed) and refreshes one set's BrickLink price fields.
 * Always stamps bricklink_price_checked_at, even on failure — a set that
 * isn't in BrickLink's catalog, or a request that timed out, must not be
 * retried on every single sync tick, only on the next scheduled one.
 */
function refreshBricklinkPriceForSet(PDO $pdo, array $set): bool
{
    $itemId = $set['bricklink_item_id'] ?? null;
    if ($itemId === null) {
        $itemId = resolveBricklinkItemId($set['rebrickable_set_num']);
        if ($itemId !== null) {
            $pdo->prepare('UPDATE sets SET bricklink_item_id = ? WHERE id = ?')->execute([$itemId, $set['id']]);
        }
    }

    if ($itemId === null) {
        $pdo->prepare('UPDATE sets SET bricklink_price_checked_at = NOW() WHERE id = ?')->execute([$set['id']]);
        return false;
    }

    $guide = fetchBricklinkPriceGuide((int) $itemId);
    if ($guide === null) {
        $pdo->prepare('UPDATE sets SET bricklink_price_checked_at = NOW() WHERE id = ?')->execute([$set['id']]);
        return false;
    }

    $pdo->prepare(
        'UPDATE sets SET bricklink_price_new = ?, bricklink_price_used = ?, bricklink_price_currency = ?, bricklink_price_checked_at = NOW() WHERE id = ?'
    )->execute([$guide['new']['avgPrice'], $guide['used']['avgPrice'], $guide['currency'], $set['id']]);

    return true;
}

/**
 * Minifig counterpart to refreshBricklinkPriceForSet() — one extra
 * resolution step first, since a minifig needs BrickLink's own catalog code
 * (minifigs.bricklink_id, e.g. "sw0001a") before its numeric idItem can even
 * be looked up, whereas a set number doubles as its own catalog page query
 * directly. getOrFetchBricklinkMinifigId() (src/owned_sets.php) already
 * exists for the Wanted-List export and the modal's BrickLink link, and is
 * reused here unchanged — it already caches its result on
 * minifigs.bricklink_id, so this only ever pays that cost once per minifig
 * regardless of how many times its price gets refreshed afterward.
 */
function refreshBricklinkPriceForMinifig(PDO $pdo, array $minifig): bool
{
    $bricklinkId = getOrFetchBricklinkMinifigId($pdo, $minifig['id'], $minifig['fig_num']);
    if ($bricklinkId === null) {
        $pdo->prepare('UPDATE minifigs SET bricklink_price_checked_at = NOW() WHERE id = ?')->execute([$minifig['id']]);
        return false;
    }

    $itemId = $minifig['bricklink_price_item_id'] ?? null;
    if ($itemId === null) {
        $itemId = resolveBricklinkMinifigCatalogItemId($bricklinkId);
        if ($itemId !== null) {
            $pdo->prepare('UPDATE minifigs SET bricklink_price_item_id = ? WHERE id = ?')->execute([$itemId, $minifig['id']]);
        }
    }

    if ($itemId === null) {
        $pdo->prepare('UPDATE minifigs SET bricklink_price_checked_at = NOW() WHERE id = ?')->execute([$minifig['id']]);
        return false;
    }

    $guide = fetchBricklinkPriceGuide((int) $itemId);
    if ($guide === null) {
        $pdo->prepare('UPDATE minifigs SET bricklink_price_checked_at = NOW() WHERE id = ?')->execute([$minifig['id']]);
        return false;
    }

    $pdo->prepare(
        'UPDATE minifigs SET bricklink_price_new = ?, bricklink_price_used = ?, bricklink_price_currency = ?, bricklink_price_checked_at = NOW() WHERE id = ?'
    )->execute([$guide['new']['avgPrice'], $guide['used']['avgPrice'], $guide['currency'], $minifig['id']]);

    return true;
}

const BRICKLINK_SYNC_INTERVAL_DAYS = 30;
// Floor between two opportunistic syncs, regardless of how many page loads
// happen in between — this (not literal random scheduling, which would need
// a cron this app's shared-hosting target may not have) is what keeps the
// background refresh from ever looking like a burst against BrickLink: a
// large collection just naturally trickles through its 30-day cycle across
// however many page loads happen to occur.
const BRICKLINK_SYNC_MIN_INTERVAL_SECONDS = 600;

/**
 * One set (the catalog set behind an owned instance) whose BrickLink price
 * hasn't been checked in BRICKLINK_SYNC_INTERVAL_DAYS days, or null if every
 * owned set is currently up to date. Two-bucket priority:
 * - Never checked at all: most-recently-added owned instance first — a set
 *   someone just added to their collection jumps ahead of the rest of an
 *   old, still-unprocessed backlog (e.g. right after this feature first
 *   shipped, every existing owned set started out "never checked" at once;
 *   without this, a freshly added set would otherwise wait behind however
 *   much of that backlog hadn't been worked through yet — sets.id/creation
 *   order is no use here, since it reflects the Rebrickable catalog import,
 *   not when the user actually added the set to their own collection).
 * - Already checked once but overdue for a recheck: oldest-checked first,
 *   same as before.
 */
function getNextOwnedSetDueForBricklinkSync(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.rebrickable_set_num, s.bricklink_item_id, s.bricklink_price_checked_at
         FROM sets s
         INNER JOIN owned_sets os ON os.set_id = s.id
         WHERE s.bricklink_price_checked_at IS NULL
            OR s.bricklink_price_checked_at < (NOW() - INTERVAL ' . BRICKLINK_SYNC_INTERVAL_DAYS . ' DAY)
         GROUP BY s.id, s.rebrickable_set_num, s.bricklink_item_id, s.bricklink_price_checked_at
         ORDER BY
            CASE WHEN s.bricklink_price_checked_at IS NULL THEN 0 ELSE 1 END,
            CASE
                WHEN s.bricklink_price_checked_at IS NULL THEN -UNIX_TIMESTAMP(MAX(os.created_at))
                ELSE UNIX_TIMESTAMP(s.bricklink_price_checked_at)
            END
         LIMIT 1'
    );
    $stmt->execute();
    $set = $stmt->fetch();
    if ($set === false) {
        return null;
    }
    $set['id'] = (int) $set['id'];
    $set['bricklink_item_id'] = $set['bricklink_item_id'] !== null ? (int) $set['bricklink_item_id'] : null;
    return $set;
}

/**
 * Called opportunistically from index.php on every page load (same idea as
 * schemaMigrationPending()/partTranslationsSyncPending() — no cron, no
 * background worker, everything piggybacks on real requests since that's
 * all shared hosting guarantees). Refreshes at most one set, and only if
 * BRICKLINK_SYNC_MIN_INTERVAL_SECONDS has passed since the last attempt —
 * never adds noticeable latency to more than an occasional page load, and
 * never throws: this is best-effort background enrichment, not something
 * that may ever break the page it happens to run on.
 */
function stepBricklinkPriceSync(PDO $pdo): void
{
    try {
        $lastRun = getAppSetting('bricklink_sync_last_run');
        if ($lastRun !== null && (time() - strtotime($lastRun)) < BRICKLINK_SYNC_MIN_INTERVAL_SECONDS) {
            return;
        }

        $due = getNextOwnedSetDueForBricklinkSync($pdo);
        if ($due === null) {
            return;
        }

        setAppSetting('bricklink_sync_last_run', date('Y-m-d H:i:s'));
        refreshBricklinkPriceForSet($pdo, $due);
    } catch (Throwable $e) {
        // Best-effort background enrichment — swallow everything.
    }
}

/**
 * Minifig counterpart to getNextOwnedSetDueForBricklinkSync() — same
 * two-bucket priority (never checked, most-recently-stored instance first;
 * otherwise oldest-checked first), joined over minifig_storage_items
 * instead of owned_sets.
 */
function getNextMinifigDueForBricklinkSync(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.id, m.fig_num, m.bricklink_price_item_id, m.bricklink_price_checked_at
         FROM minifigs m
         INNER JOIN minifig_storage_items msi ON msi.minifig_id = m.id
         WHERE m.bricklink_price_checked_at IS NULL
            OR m.bricklink_price_checked_at < (NOW() - INTERVAL ' . BRICKLINK_SYNC_INTERVAL_DAYS . ' DAY)
         GROUP BY m.id, m.fig_num, m.bricklink_price_item_id, m.bricklink_price_checked_at
         ORDER BY
            CASE WHEN m.bricklink_price_checked_at IS NULL THEN 0 ELSE 1 END,
            CASE
                WHEN m.bricklink_price_checked_at IS NULL THEN -UNIX_TIMESTAMP(MAX(msi.updated_at))
                ELSE UNIX_TIMESTAMP(m.bricklink_price_checked_at)
            END
         LIMIT 1'
    );
    $stmt->execute();
    $minifig = $stmt->fetch();
    if ($minifig === false) {
        return null;
    }
    $minifig['id'] = (int) $minifig['id'];
    $minifig['bricklink_price_item_id'] = $minifig['bricklink_price_item_id'] !== null ? (int) $minifig['bricklink_price_item_id'] : null;
    return $minifig;
}

/**
 * Minifig counterpart to stepBricklinkPriceSync() — its own last-run marker
 * and the same throttle/never-throw reasoning, kept fully independent of
 * the sets sync so neither one's backlog delays the other.
 */
function stepBricklinkMinifigPriceSync(PDO $pdo): void
{
    try {
        $lastRun = getAppSetting('bricklink_minifig_sync_last_run');
        if ($lastRun !== null && (time() - strtotime($lastRun)) < BRICKLINK_SYNC_MIN_INTERVAL_SECONDS) {
            return;
        }

        $due = getNextMinifigDueForBricklinkSync($pdo);
        if ($due === null) {
            return;
        }

        setAppSetting('bricklink_minifig_sync_last_run', date('Y-m-d H:i:s'));
        refreshBricklinkPriceForMinifig($pdo, $due);
    } catch (Throwable $e) {
        // Best-effort background enrichment — swallow everything.
    }
}

const BRICKLINK_PART_PRICE_SYNC_INTERVAL_MONTHS = 6;
// Unlike BRICKLINK_SYNC_MIN_INTERVAL_SECONDS's fixed floor, the gap between
// two part-price fetches is randomized within [MIN,MAX] every single time
// (see stepBricklinkPartPriceSync()) — deliberately, so this never looks
// like a fixed-interval bot poll. No separate "6 requests/minute" ceiling is
// enforced anywhere: since the floor of that random range is 10 seconds, the
// rate can never exceed 6/minute regardless, so a second, independently
// tunable cap would just be redundant (and risk drifting out of sync with
// these two numbers if ever changed separately).
const BRICKLINK_PART_PRICE_MIN_DELAY_SECONDS = 10;
const BRICKLINK_PART_PRICE_MAX_DELAY_SECONDS = 300;

/**
 * Marks one part+color as checked right now without touching its price
 * fields — the "give up on this one until the next scheduled check" path,
 * used by refreshBricklinkPriceForPartColor() on every failure branch so a
 * permanently-unresolvable pair isn't retried on every single tick. Upserts
 * since (unlike sets/minifigs) a part_bricklink_prices row may not exist yet
 * the first time this runs for a given pair.
 */
function stampPartBricklinkPriceCheckedAt(PDO $pdo, int $partId, int $colorId): void
{
    $pdo->prepare(
        'INSERT INTO part_bricklink_prices (part_id, color_id, bricklink_price_checked_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE bricklink_price_checked_at = NOW()'
    )->execute([$partId, $colorId]);
}

/**
 * Resolves (if needed) and refreshes one part+color's BrickLink price
 * fields. Always stamps bricklink_price_checked_at, even on failure, same
 * reasoning as refreshBricklinkPriceForSet(). Two resolution steps happen
 * lazily, each cached so it's only ever paid once:
 * - parts.bricklink_part_id (Rebrickable's own external-id mapping, via
 *   applyBricklinkPartIdBatch() in src/rebrickable.php — an API call to
 *   Rebrickable, not BrickLink, so this does not consume the BrickLink
 *   throttle).
 * - parts.bricklink_item_id (BrickLink's own internal catalog id, resolved
 *   via resolveBricklinkPartItemId() — one BrickLink request, shared across
 *   every color of this part, confirmed live that idItem does not vary by
 *   color).
 * $partColor is the row shape getNextOwnedPartColorDueForBricklinkPriceSync()
 * returns.
 */
function refreshBricklinkPriceForPartColor(PDO $pdo, array $partColor): bool
{
    $partId = (int) $partColor['part_id'];
    $colorId = (int) $partColor['color_id'];

    $bricklinkPartId = $partColor['bricklink_part_id'] ?? null;
    if ($bricklinkPartId === null) {
        applyBricklinkPartIdBatch($pdo, [$partColor['part_num']]);
        $stmt = $pdo->prepare('SELECT bricklink_part_id FROM parts WHERE id = ?');
        $stmt->execute([$partId]);
        $refetched = $stmt->fetchColumn();
        $bricklinkPartId = $refetched !== false ? $refetched : null;
    }

    if ($bricklinkPartId === null || $partColor['bricklink_color_id'] === null) {
        stampPartBricklinkPriceCheckedAt($pdo, $partId, $colorId);
        return false;
    }

    $itemId = $partColor['bricklink_item_id'] ?? null;
    if ($itemId === null) {
        $itemId = resolveBricklinkPartItemId((string) $bricklinkPartId);
        if ($itemId !== null) {
            $pdo->prepare('UPDATE parts SET bricklink_item_id = ? WHERE id = ?')->execute([$itemId, $partId]);
        }
    }

    if ($itemId === null) {
        stampPartBricklinkPriceCheckedAt($pdo, $partId, $colorId);
        return false;
    }

    $guide = fetchBricklinkPriceGuide((int) $itemId, (int) $partColor['bricklink_color_id']);
    if ($guide === null) {
        stampPartBricklinkPriceCheckedAt($pdo, $partId, $colorId);
        return false;
    }

    $pdo->prepare(
        'INSERT INTO part_bricklink_prices (part_id, color_id, bricklink_price_new, bricklink_price_used, bricklink_price_currency, bricklink_price_checked_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE bricklink_price_new = VALUES(bricklink_price_new), bricklink_price_used = VALUES(bricklink_price_used), bricklink_price_currency = VALUES(bricklink_price_currency), bricklink_price_checked_at = NOW()'
    )->execute([$partId, $colorId, $guide['new']['avgPrice'], $guide['used']['avgPrice'], $guide['currency']]);

    return true;
}

/**
 * One owned part+color pair (any storage location — deliberately broader
 * than computeLoosePartsBricklinkValueTotal()'s loose-only scope, since
 * queueing what to price is independent of what counts toward the
 * collection-value sum) whose BrickLink price hasn't been checked in
 * BRICKLINK_PART_PRICE_SYNC_INTERVAL_MONTHS months, or null if everything
 * owned is up to date. Same two-bucket priority as
 * getNextOwnedSetDueForBricklinkSync(): never-checked-first (most-recently-
 * stocked first), then oldest-checked-first. storage_items rows with no
 * color assigned are excluded — there's nothing color-specific to look up
 * for those.
 */
function getNextOwnedPartColorDueForBricklinkPriceSync(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT si.part_id, si.color_id, p.part_num, p.bricklink_part_id, p.bricklink_item_id,
                c.bricklink_color_id, pbp.bricklink_price_checked_at
         FROM storage_items si
         INNER JOIN parts p ON p.id = si.part_id
         INNER JOIN colors c ON c.id = si.color_id
         LEFT JOIN part_bricklink_prices pbp ON pbp.part_id = si.part_id AND pbp.color_id = si.color_id
         WHERE si.color_id IS NOT NULL
         GROUP BY si.part_id, si.color_id, p.part_num, p.bricklink_part_id, p.bricklink_item_id,
                  c.bricklink_color_id, pbp.bricklink_price_checked_at
         HAVING SUM(si.quantity) > 0
            AND (pbp.bricklink_price_checked_at IS NULL
                 OR pbp.bricklink_price_checked_at < (NOW() - INTERVAL ' . BRICKLINK_PART_PRICE_SYNC_INTERVAL_MONTHS . ' MONTH))
         ORDER BY
            CASE WHEN pbp.bricklink_price_checked_at IS NULL THEN 0 ELSE 1 END,
            CASE
                WHEN pbp.bricklink_price_checked_at IS NULL THEN -UNIX_TIMESTAMP(MAX(si.updated_at))
                ELSE UNIX_TIMESTAMP(pbp.bricklink_price_checked_at)
            END
         LIMIT 1'
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['part_id'] = (int) $row['part_id'];
    $row['color_id'] = (int) $row['color_id'];
    $row['bricklink_item_id'] = $row['bricklink_item_id'] !== null ? (int) $row['bricklink_item_id'] : null;
    $row['bricklink_color_id'] = $row['bricklink_color_id'] !== null ? (int) $row['bricklink_color_id'] : null;
    return $row;
}

/**
 * Called opportunistically from index.php on every page load, same idea as
 * stepBricklinkPriceSync()/stepBricklinkMinifigPriceSync() — plus it's also
 * invoked by bin/bricklink_part_price_sync.php, a real crontab entry point
 * for installs that have one. Both share the exact same throttle state
 * (bricklink_part_sync_next_allowed_at in app_settings) and a MySQL/MariaDB
 * advisory lock (GET_LOCK, 0-second/non-blocking wait — a process that loses
 * the race just returns immediately rather than delaying whatever page or
 * cron run triggered it), so the two invocation paths can never compound
 * into a faster-than-intended rate even if both are active at once. Unlike
 * the fixed 600-second floor the set/minifig syncs use (where a race between
 * two overlapping requests was practically irrelevant), a 10-300 second
 * window with two independent triggers needs this explicit mutex.
 *
 * Gated behind bricklink_part_sync_enabled ('1'/'0' in app_settings) — the
 * Settings page toggle both this and the CLI cron script read.
 */
function stepBricklinkPartPriceSync(PDO $pdo): void
{
    try {
        if (getAppSetting('bricklink_part_sync_enabled', '0') !== '1') {
            return;
        }

        $locked = (int) $pdo->query("SELECT GET_LOCK('studsphere_bricklink_part_sync', 0)")->fetchColumn();
        if ($locked !== 1) {
            return; // a cron run or another concurrent page load is already ticking
        }
        try {
            $nextAllowed = getAppSetting('bricklink_part_sync_next_allowed_at');
            if ($nextAllowed !== null && time() < strtotime($nextAllowed)) {
                return;
            }

            $due = getNextOwnedPartColorDueForBricklinkPriceSync($pdo);
            if ($due === null) {
                return;
            }

            refreshBricklinkPriceForPartColor($pdo, $due);
            $delay = random_int(BRICKLINK_PART_PRICE_MIN_DELAY_SECONDS, BRICKLINK_PART_PRICE_MAX_DELAY_SECONDS);
            setAppSetting('bricklink_part_sync_next_allowed_at', date('Y-m-d H:i:s', time() + $delay));
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('studsphere_bricklink_part_sync')");
        }
    } catch (Throwable $e) {
        // Best-effort background enrichment — swallow everything, same as
        // stepBricklinkPriceSync()/stepBricklinkMinifigPriceSync().
    }
}

/**
 * Batch-reads cached BrickLink prices for a list of owned part+color pairs
 * — for the "Mein Lager" location explorer, so a card can show its own
 * price without a per-card round trip. Pure cache read, no lazy compute
 * (unlike getPartSetCounts()'s shape): a price is a network fetch, not a
 * cheap SQL aggregate, so a missing entry here just means "not synced yet",
 * left to stepBricklinkPartPriceSync()/the cronjob rather than fetched
 * inline. $partColorPairs items need part_id/color_id keys (colors.id, the
 * same surrogate PK storage_items.color_id uses — this table's own PK, no
 * Rebrickable-id juggling needed here unlike the image lookup).
 *
 * @param array<array{part_id:int, color_id:int}> $partColorPairs
 * @return array<string, array{new: ?float, used: ?float, currency: ?string}> keyed by "{part_id}:{color_id}"
 */
function getPartBricklinkPrices(PDO $pdo, array $partColorPairs): array
{
    $uniquePairs = [];
    foreach ($partColorPairs as $pair) {
        $uniquePairs[$pair['part_id'] . ':' . $pair['color_id']] = $pair;
    }
    if (empty($uniquePairs)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($uniquePairs), '(?,?)'));
    $params = [];
    foreach ($uniquePairs as $pair) {
        $params[] = $pair['part_id'];
        $params[] = $pair['color_id'];
    }

    $stmt = $pdo->prepare(
        "SELECT part_id, color_id, bricklink_price_new, bricklink_price_used, bricklink_price_currency
         FROM part_bricklink_prices
         WHERE (part_id, color_id) IN ($placeholders)
            AND (bricklink_price_new IS NOT NULL OR bricklink_price_used IS NOT NULL)"
    );
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['part_id'] . ':' . $row['color_id']] = [
            'new' => $row['bricklink_price_new'] !== null ? (float) $row['bricklink_price_new'] : null,
            'used' => $row['bricklink_price_used'] !== null ? (float) $row['bricklink_price_used'] : null,
            'currency' => $row['bricklink_price_currency'],
        ];
    }
    return $result;
}

/**
 * How many distinct owned part+color pairs already have a cached BrickLink
 * price vs. how many are owned in total — same scope (all locations,
 * quantity > 0, color assigned) as
 * getNextOwnedPartColorDueForBricklinkPriceSync()'s queue, so this number
 * directly reflects "how far the sync has gotten through the backlog", for
 * the ?page=my_bricks_top100 overview.
 *
 * @return array{priced: int, total: int}
 */
function getBricklinkPartPriceCoverage(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN pbp.bricklink_price_new IS NOT NULL OR pbp.bricklink_price_used IS NOT NULL THEN 1 ELSE 0 END) AS priced
         FROM (
             SELECT si.part_id, si.color_id
             FROM storage_items si
             WHERE si.color_id IS NOT NULL
             GROUP BY si.part_id, si.color_id
             HAVING SUM(si.quantity) > 0
         ) owned
         LEFT JOIN part_bricklink_prices pbp ON pbp.part_id = owned.part_id AND pbp.color_id = owned.color_id"
    );
    $row = $stmt->fetch();
    return [
        'priced' => $row !== false ? (int) $row['priced'] : 0,
        'total' => $row !== false ? (int) $row['total'] : 0,
    ];
}

/**
 * Sum of loose (non-owned-set-location) stock's BrickLink value, priced per
 * part+color from part_bricklink_prices. Deliberately excludes stock that's
 * currently materialized inside an owned set — that set's own aggregate
 * BrickLink price (computeOwnedSetsBricklinkValueTotal()) already values it
 * as part of the whole set, so including it here too would double-count it.
 * Quantity-weighted (unlike the set/minifig totals, which sum one row per
 * instance) since a storage_items row can hold quantity > 1.
 *
 * @return array{total: float, currency: ?string}
 */
function computeLoosePartsBricklinkValueTotal(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN si.condition_type = 'new' THEN pbp.bricklink_price_new ELSE pbp.bricklink_price_used END
                * (si.quantity - si.damaged_quantity)) AS total,
            MAX(pbp.bricklink_price_currency) AS currency
         FROM storage_items si
         INNER JOIN storage_locations sl ON sl.id = si.location_id
         INNER JOIN part_bricklink_prices pbp ON pbp.part_id = si.part_id AND pbp.color_id = si.color_id
         WHERE (sl.location_type IS NULL OR sl.location_type != 'owned_set')"
    );
    $row = $stmt->fetch();
    return [
        'total' => $row !== false && $row['total'] !== null ? (float) $row['total'] : 0.0,
        'currency' => $row !== false ? $row['currency'] : null,
    ];
}

/**
 * The N owned parts (any storage location — loose and materialized inside
 * owned sets alike, unlike computeLoosePartsBricklinkValueTotal()'s scope)
 * with the highest priced BrickLink unit price, for the "100 teuersten
 * Bauteile" overview (?page=my_bricks_top100). Mirrors
 * getTopValuedOwnedMinifigs()'s shape: SQL does the per-part/color/condition
 * aggregation (storage_items already groups that way, unlike
 * minifig_storage_items' one-row-per-instance shape, so no PHP-side grouping
 * is needed here), PHP computes total_value and sorts.
 *
 * ldraw_thumbnail/rebrickable_color_id are the same color-correct-image pair
 * getLocationContentRecursive() (src/storage.php) and getSetPartsList()
 * (src/sets.php) expose — part_color_images is keyed by Rebrickable's own
 * color_id, not colors.id, hence the separate column. The caller resolves
 * the actual display thumbnail (ldraw_thumbnail, falling back to a generic
 * catalog image) the same way those two callers do, via renderPartCard().
 *
 * @return array<array{part_id:int, color_id:int, condition_type:string, part_num:string, part_name:string, color_name:?string, color_rgb:?string, rebrickable_color_id:?int, ldraw_thumbnail:?string, quantity:int, unit_price:float, currency:?string, total_value:float}>
 */
function getTopValuedOwnedParts(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->query(
        "SELECT si.part_id, si.color_id, si.condition_type,
                p.part_num, p.name AS part_name, c.name AS color_name, c.rgb AS color_rgb,
                c.color_id AS rebrickable_color_id, MAX(pci.local_image_path) AS ldraw_thumbnail,
                SUM(si.quantity - si.damaged_quantity) AS quantity,
                CASE si.condition_type WHEN 'new' THEN pbp.bricklink_price_new ELSE pbp.bricklink_price_used END AS unit_price,
                pbp.bricklink_price_currency AS currency
         FROM storage_items si
         INNER JOIN parts p ON p.id = si.part_id
         INNER JOIN colors c ON c.id = si.color_id
         INNER JOIN part_bricklink_prices pbp ON pbp.part_id = si.part_id AND pbp.color_id = si.color_id
         LEFT JOIN part_color_images pci ON pci.part_id = si.part_id AND pci.color_id = c.color_id
         WHERE (CASE si.condition_type WHEN 'new' THEN pbp.bricklink_price_new ELSE pbp.bricklink_price_used END) IS NOT NULL
         GROUP BY si.part_id, si.color_id, si.condition_type, p.part_num, p.name, c.name, c.rgb,
                  c.color_id, pbp.bricklink_price_new, pbp.bricklink_price_used, pbp.bricklink_price_currency
         HAVING SUM(si.quantity - si.damaged_quantity) > 0"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['part_id'] = (int) $row['part_id'];
        $row['color_id'] = (int) $row['color_id'];
        $row['rebrickable_color_id'] = $row['rebrickable_color_id'] !== null ? (int) $row['rebrickable_color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
        $row['unit_price'] = (float) $row['unit_price'];
        $row['total_value'] = $row['unit_price'] * $row['quantity'];
    }
    unset($row);

    usort($rows, fn (array $a, array $b): int => $b['unit_price'] <=> $a['unit_price']);

    return array_slice($rows, 0, $limit);
}

/**
 * ISO 4217 code -> symbol for the handful of currencies BrickLink's price
 * guide realistically returns for this app's userbase; falls back to the
 * raw code for anything else rather than guessing a symbol.
 */
function bricklinkCurrencySymbol(?string $code): string
{
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
    if ($code === null) {
        return '';
    }
    return $symbols[$code] ?? $code;
}

/**
 * Sum of every owned set instance's own-condition BrickLink price (a "new"
 * instance counts its bricklink_price_new, a "used" one its
 * bricklink_price_used) — the status bar's "Sammlungswert" figure. Instances
 * whose set has no price yet (never synced, or BrickLink has no current
 * listings for that condition) simply contribute nothing, same as the other
 * status-bar sums treat anything not yet known. Computed fresh on every
 * render rather than cached alongside APP_STATS_CACHE_KEYS: unlike those,
 * this only ever changes when a set is added/removed or a price gets
 * (re)synced, and both of those already end in a full page render, so there
 * was never a "went stale until reload" case to protect against for this
 * one — see refreshAppStatsCache()'s doc comment in src/stats.php for the
 * caching reasoning that doesn't apply here.
 *
 * @return array{total: float, currency: ?string}
 */
function computeOwnedSetsBricklinkValueTotal(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN os.condition_type = 'new' THEN s.bricklink_price_new ELSE s.bricklink_price_used END) AS total,
            MAX(s.bricklink_price_currency) AS currency
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id"
    );
    $row = $stmt->fetch();
    return [
        'total' => $row !== false && $row['total'] !== null ? (float) $row['total'] : 0.0,
        'currency' => $row !== false ? $row['currency'] : null,
    ];
}

/**
 * Minifig counterpart to computeOwnedSetsBricklinkValueTotal() — same
 * per-condition sum, one row per stored minifig instance instead of one per
 * owned set. The status bar adds this to the sets total (see index.php).
 *
 * @return array{total: float, currency: ?string}
 */
function computeMinifigStorageBricklinkValueTotal(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN msi.condition_type = 'new' THEN m.bricklink_price_new ELSE m.bricklink_price_used END) AS total,
            MAX(m.bricklink_price_currency) AS currency
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id"
    );
    $row = $stmt->fetch();
    return [
        'total' => $row !== false && $row['total'] !== null ? (float) $row['total'] : 0.0,
        'currency' => $row !== false ? $row['currency'] : null,
    ];
}

/**
 * The owned_set_detail sidebar's "Ø BrickLink-Preis" row — shared by the
 * initial page render and the manual-refresh button's live JS update (see
 * the inline script next to that row in src/routes/pages.php), so the two
 * can never drift apart in formatting. Shows only the price matching this
 * particular instance's own condition_type (a "used" set has no business
 * being compared against BrickLink's "new" average and vice versa) rather
 * than both New and Used side by side. The "last updated" date isn't shown
 * inline — it's the row's title attribute (a hover tooltip), so the row
 * itself stays just the price.
 *
 * @return array{text: string, title: ?string}
 */
function formatBricklinkPriceSummary(?float $priceNew, ?float $priceUsed, ?string $currency, ?string $checkedAt, string $conditionType): array
{
    if ($checkedAt === null) {
        return ['text' => t('owned_set_bricklink_price_never'), 'title' => null];
    }
    $price = $conditionType === 'new' ? $priceNew : $priceUsed;
    $priceText = $price !== null ? formatNumber($price, 2) . ' ' . bricklinkCurrencySymbol($currency) : '–';
    return [
        'text' => $priceText,
        'title' => t('owned_set_bricklink_price_updated_title', ['date' => formatDate($checkedAt, true)]),
    ];
}

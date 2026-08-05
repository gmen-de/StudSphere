<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

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
 * @return array{currency: ?string, new: array, used: array}|null
 */
function fetchBricklinkPriceGuide(int $itemId): ?array
{
    $url = 'https://www.bricklink.com/v2/catalog/catalogitem_pgtab.page'
        . '?idItem=' . $itemId . '&st=2&gm=0&gc=0&ei=0&prec=2&showflag=0&showbulk=0&currency=2';
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

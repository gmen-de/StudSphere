<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/rebrickable.php';
require_once __DIR__ . '/stats.php';

/**
 * The sorting machine's API (api/sorter.php): each key belongs to one sorter.
 *
 * A key is shown once when it's made and only its SHA-256 is stored — the
 * key is 32 random bytes, so a plain hash is enough (no password hashing
 * needed for a secret nobody picked). The first sorter that sets up with a
 * key binds it to its machine id; another machine using the same key is
 * refused, so a copied key can't quietly mix two sorters' stock.
 *
 * Setting up builds the sorter's location tree:
 *   <sorter name>
 *     BIN-Storage
 *       L1-S1-B1, L1-S1-B2, ...   (one per bin, created on demand too)
 *     ZIP-Storage
 *       ZIP0001, ...              (one per emptied bin with a receipt)
 * Every change then goes through the same storage.php functions the web UI
 * uses, so it shows in storage_movements like any other booking, under the
 * user who made the key, with the sorter's name in the note.
 *
 * The sorter sends its changes as numbered operations from an outbox. Each
 * key remembers the stream id and the last sequence number it applied, so a
 * batch the sorter re-sends after a timeout applies only once. A new stream
 * id (the sorter re-synced from scratch) starts the count over.
 */

const SORTER_API_BIN_STORAGE = 'BIN-Storage';
const SORTER_API_ZIP_STORAGE = 'ZIP-Storage';
const SORTER_API_MAX_OPS = 500;

class SorterApiError extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/** Returns the new key in plain text — the only time it exists as such. */
function createSorterApiKey(string $name, ?int $userId): string
{
    $key = 'ssk_' . bin2hex(random_bytes(32));
    $stmt = getPDO()->prepare('INSERT INTO sorter_api_keys (name, key_hash, key_hint, user_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, hash('sha256', $key), substr($key, 0, 8) . '…' . substr($key, -4), $userId]);
    return $key;
}

function listSorterApiKeys(): array
{
    return getPDO()->query(
        'SELECT k.id, k.name, k.key_hint, k.machine_id, k.sorter_name, k.root_location_id, k.last_used_at, k.created_at, k.revoked_at, u.username
         FROM sorter_api_keys k LEFT JOIN users u ON u.id = k.user_id
         ORDER BY k.revoked_at IS NOT NULL, k.created_at DESC'
    )->fetchAll();
}

function revokeSorterApiKey(int $id): void
{
    getPDO()->prepare('UPDATE sorter_api_keys SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND revoked_at IS NULL')->execute([$id]);
}

/** Frees a key from its sorter, so another machine can set up with it. */
function unbindSorterApiKey(int $id): void
{
    getPDO()->prepare('UPDATE sorter_api_keys SET machine_id = NULL, stream_id = NULL, last_seq = 0 WHERE id = ?')->execute([$id]);
}

function authenticateSorterApiKey(string $authorizationHeader): array
{
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorizationHeader), $m)) {
        throw new SorterApiError('Missing API key (Authorization: Bearer ...).', 401);
    }
    $stmt = getPDO()->prepare('SELECT * FROM sorter_api_keys WHERE key_hash = ?');
    $stmt->execute([hash('sha256', $m[1])]);
    $key = $stmt->fetch();
    if ($key === false) {
        throw new SorterApiError('Unknown API key.', 401);
    }
    if ($key['revoked_at'] !== null) {
        throw new SorterApiError('This API key was revoked.', 401);
    }
    getPDO()->prepare('UPDATE sorter_api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$key['id']]);
    return $key;
}

function sorterApiChildLocationId(int $parentId, string $name): int
{
    $stmt = getPDO()->prepare('SELECT id FROM storage_locations WHERE parent_id = ? AND name = ? ORDER BY id LIMIT 1');
    $stmt->execute([$parentId, $name]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : createStorageLocation($parentId, $name);
}

/** The sorter's root location, recreated when someone deleted it. */
function sorterApiRootLocationId(array &$key, string $sorterName): int
{
    $rootId = $key['root_location_id'] !== null ? (int) $key['root_location_id'] : null;
    if ($rootId !== null && getStorageLocation($rootId) === null) {
        $rootId = null;
    }
    if ($rootId === null) {
        $rootId = createStorageLocation(null, $sorterName);
        getPDO()->prepare('UPDATE sorter_api_keys SET root_location_id = ? WHERE id = ?')->execute([$rootId, $key['id']]);
        $key['root_location_id'] = $rootId;
    } elseif ($sorterName !== '' && $sorterName !== (string) $key['sorter_name']) {
        renameStorageLocation($rootId, $sorterName);
    }
    return $rootId;
}

function sorterApiCleanName(mixed $value, string $what, int $max = 100): string
{
    $name = trim((string) $value);
    if ($name === '' || mb_strlen($name) > $max) {
        throw new SorterApiError("Invalid $what.");
    }
    return $name;
}

/**
 * Binds the key to the machine, builds the location tree and creates a
 * location for each bin it lists. Returns where the stream stands.
 */
function sorterApiSetup(array $key, array $body): array
{
    $machineId = sorterApiCleanName($body['machine_id'] ?? '', 'machine_id');
    $sorterName = sorterApiCleanName($body['sorter_name'] ?? '', 'sorter_name', 255);
    $streamId = sorterApiCleanName($body['stream_id'] ?? '', 'stream_id', 64);
    if ($key['machine_id'] !== null && $key['machine_id'] !== $machineId) {
        throw new SorterApiError('This API key belongs to another sorter. Make a new key for this one, or free the key in StudSphere first.', 409);
    }

    $rootId = sorterApiRootLocationId($key, $sorterName);
    $binStorageId = sorterApiChildLocationId($rootId, SORTER_API_BIN_STORAGE);
    sorterApiChildLocationId($rootId, SORTER_API_ZIP_STORAGE);
    foreach ((array) ($body['bins'] ?? []) as $binName) {
        sorterApiChildLocationId($binStorageId, sorterApiCleanName($binName, 'bin name'));
    }

    $sameStream = $key['stream_id'] === $streamId;
    getPDO()->prepare(
        'UPDATE sorter_api_keys SET machine_id = ?, sorter_name = ?, stream_id = ?, last_seq = ? WHERE id = ?'
    )->execute([$machineId, $sorterName, $streamId, $sameStream ? (int) $key['last_seq'] : 0, $key['id']]);

    return [
        'ok' => true,
        'key_name' => $key['name'],
        'location' => getStorageLocationPath($rootId),
        'root_location_id' => $rootId,
        'stream_id' => $streamId,
        'last_seq' => $sameStream ? (int) $key['last_seq'] : 0,
    ];
}

const SORTER_API_REBRICKABLE_LOOKUPS = 5;
const SORTER_API_ALIAS_SETTING = 'sorter_api_part_aliases';
const SORTER_API_ALIAS_RETRY_S = 86400;
// Small enough for app_settings.setting_value (TEXT, 64 KB).
const SORTER_API_ALIAS_MAX = 500;
const SORTER_API_LOOKUP_MAX_LENGTH = 40;

function sorterApiNewResolveState(): array
{
    return ['parts' => [], 'colors' => [], 'via' => [], 'budget' => SORTER_API_REBRICKABLE_LOOKUPS, 'aliases' => null, 'aliasesDirty' => false];
}

/**
 * Asks Rebrickable which of its parts is this BrickLink number: the sorter's
 * classifier speaks BrickLink, and parts.bricklink_part_id is only filled for
 * parts of owned sets (see applyBricklinkPartIdBatch()), while many printed,
 * assembled and old parts are numbered differently in the two catalogs.
 *
 * What Rebrickable answered is remembered in app_settings (a hit for good, a
 * miss for a day), and a request asks it at most SORTER_API_REBRICKABLE_LOOKUPS
 * times, since each call can take seconds. A hit also fills the part's own
 * bricklink_part_id where that's still empty. Returns parts.id, or null.
 */
function sorterApiRebrickableLookup(string $part, array &$state): ?int
{
    // An empty bricklink_id would not filter at all and match any part.
    if ($part === '' || mb_strlen($part) > SORTER_API_LOOKUP_MAX_LENGTH) {
        return null;
    }
    if ($state['aliases'] === null) {
        $decoded = json_decode((string) getAppSetting(SORTER_API_ALIAS_SETTING, '{}'), true);
        $state['aliases'] = is_array($decoded) ? $decoded : [];
    }
    $known = $state['aliases'][$part] ?? null;
    if (is_array($known) && ($known['part_num'] !== null || time() - (int) $known['at'] < SORTER_API_ALIAS_RETRY_S)) {
        $partNum = $known['part_num'];
    } else {
        if ($state['budget'] <= 0) {
            return null;
        }
        $state['budget']--;
        try {
            $response = callRebrickableApi('lego/parts/?bricklink_id=' . urlencode($part) . '&page_size=1');
        } catch (Throwable $e) {
            return null; // not remembered, so it's asked again next time
        }
        $found = $response['results'][0]['part_num'] ?? null;
        $partNum = is_string($found) && $found !== '' ? $found : null;
        $state['aliases'][$part] = ['part_num' => $partNum, 'at' => time()];
        $state['aliasesDirty'] = true;
    }
    if ($partNum === null) {
        return null;
    }
    $stmt = getPDO()->prepare('SELECT id FROM parts WHERE part_num = ?');
    $stmt->execute([$partNum]);
    $partId = $stmt->fetchColumn();
    if ($partId === false) {
        return null;
    }
    if (mb_strlen($part) <= 20) { // the column's size
        getPDO()->prepare('UPDATE parts SET bricklink_part_id = ? WHERE id = ? AND bricklink_part_id IS NULL')->execute([$part, $partId]);
    }
    return (int) $partId;
}

function sorterApiSaveAliases(array $state): void
{
    if (!$state['aliasesDirty']) {
        return;
    }
    $aliases = $state['aliases'];
    if (count($aliases) > SORTER_API_ALIAS_MAX) {
        // Keep the hits and the newest: misses first, oldest first, go.
        uasort($aliases, fn (array $a, array $b): int => [$a['part_num'] !== null, $a['at']] <=> [$b['part_num'] !== null, $b['at']]);
        $aliases = array_slice($aliases, count($aliases) - SORTER_API_ALIAS_MAX, null, true);
    }
    setAppSetting(SORTER_API_ALIAS_SETTING, json_encode($aliases, JSON_FORCE_OBJECT));
}

/**
 * The other numbers the sorter sends along for a part ("alt": what its parts
 * catalog calls the same part: a Rebrickable-style number, an LDraw id).
 * Strings only, a few at most.
 */
function sorterApiCleanAlts(mixed $value): array
{
    $alts = [];
    $candidates = is_array($value) ? $value : [];
    foreach ($candidates as $alt) {
        if (is_string($alt) && $alt !== '' && mb_strlen($alt) <= 100 && !in_array($alt, $alts, true)) {
            $alts[] = $alt;
        }
    }
    return array_slice($alts, 0, 3);
}

/**
 * BrickLink part number (what the sorter's classifier speaks) to parts.id.
 * The sure ways come first: Rebrickable's own BrickLink mapping, the same
 * number here, the same LDraw id, and Rebrickable's answer when asked.
 * Only when all of those find nothing are the sorter's "alt" numbers tried.
 * That one is a hint, not a mapping (the catalog behind it also uses these
 * numbers to borrow dimensions from a similar part), so it's never saved on
 * the part: it's noted on the booking and reported back, to be checked.
 */
function sorterApiResolvePart(string $part, array &$state, array $alts = []): ?int
{
    if (array_key_exists($part, $state['parts'])) {
        return $state['parts'][$part];
    }
    if ($part === '') {
        return null;
    }
    $pdo = getPDO();
    $partId = false;
    // Rebrickable's own BrickLink mapping first, then the same number in
    // Rebrickable's or LDraw's numbering, which is the same for most parts.
    foreach (['bricklink_part_id', 'part_num', 'ldraw_id'] as $column) {
        $stmt = $pdo->prepare("SELECT id FROM parts WHERE $column = ? ORDER BY id LIMIT 1");
        $stmt->execute([$part]);
        $partId = $stmt->fetchColumn();
        if ($partId !== false) {
            break;
        }
    }
    $resolved = $partId !== false ? (int) $partId : sorterApiRebrickableLookup($part, $state);
    if ($resolved === null) {
        foreach ($alts as $alt) {
            foreach (['part_num', 'ldraw_id'] as $column) {
                $stmt = $pdo->prepare("SELECT id FROM parts WHERE $column = ? ORDER BY id LIMIT 1");
                $stmt->execute([$alt]);
                $altId = $stmt->fetchColumn();
                if ($altId !== false) {
                    $state['via'][$part] = $alt;
                    $resolved = (int) $altId;
                    break 2;
                }
            }
        }
    }
    return $state['parts'][$part] = $resolved;
}

/** BrickLink color id to colors.id (see syncExternalColorIds()). */
function sorterApiResolveColor(string $color, array &$state): ?int
{
    if (array_key_exists($color, $state['colors'])) {
        return $state['colors'][$color];
    }
    $colorId = false;
    if (ctype_digit($color)) {
        $stmt = getPDO()->prepare('SELECT id FROM colors WHERE bricklink_color_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([(int) $color]);
        $colorId = $stmt->fetchColumn();
    }
    return $state['colors'][$color] = $colorId !== false ? (int) $colorId : null;
}

/** @return array{part: ?int, color: ?int} either null when StudSphere doesn't know it */
function sorterApiResolvePartColor(string $part, string $color, array &$state, array $alts = []): array
{
    return ['part' => sorterApiResolvePart($part, $state, $alts), 'color' => sorterApiResolveColor($color, $state)];
}

/** The booking's note: says so when the part was found by a hint. */
function sorterApiNoteFor(string $note, string $part, array $state): string
{
    return isset($state['via'][$part]) ? $note . ' (' . $part . ' → ' . $state['via'][$part] . ')' : $note;
}

function sorterApiNoteUnmapped(array &$unmapped, string $part, string $color, array $resolved): void
{
    $unmapped[$part . '|' . $color] = $resolved['part'] === null ? ($resolved['color'] === null ? 'both' : 'part') : 'color';
}

function sorterApiLocationItems(int $locationId): array
{
    $stmt = getPDO()->prepare(
        'SELECT part_id, color_id, condition_type, quantity FROM storage_items
         WHERE location_id = ? AND (quantity <> 0 OR damaged_quantity <> 0 OR spare_quantity <> 0 OR spare_damaged_quantity <> 0)'
    );
    $stmt->execute([$locationId]);
    return $stmt->fetchAll();
}

/**
 * Applies the sorter's operations in order, skipping any it already applied
 * (seq at or below the key's last_seq). Returns the new last_seq and the
 * part/color pairs this database doesn't know, which were left out.
 */
function sorterApiApplyOps(array $key, array $body): array
{
    if ($key['machine_id'] === null || $key['root_location_id'] === null) {
        throw new SorterApiError('Set up first (action=setup).', 409);
    }
    if (($body['stream_id'] ?? null) !== $key['stream_id']) {
        throw new SorterApiError('Stream changed: set up again.', 409);
    }
    $ops = $body['ops'] ?? null;
    if (!is_array($ops) || count($ops) > SORTER_API_MAX_OPS) {
        throw new SorterApiError('ops must be a list of at most ' . SORTER_API_MAX_OPS . ' operations.');
    }
    $condition = ($body['condition'] ?? 'used') === 'new' ? 'new' : 'used';
    $userId = $key['user_id'] !== null ? (int) $key['user_id'] : null;
    $note = 'Sorter ' . $key['sorter_name'];

    $rootId = (int) $key['root_location_id'];
    $binStorageId = sorterApiChildLocationId($rootId, SORTER_API_BIN_STORAGE);
    $zipStorageId = sorterApiChildLocationId($rootId, SORTER_API_ZIP_STORAGE);
    $binIds = [];
    $binId = function (array $op) use (&$binIds, $binStorageId): int {
        $name = sorterApiCleanName($op['bin'] ?? '', 'bin name');
        return $binIds[$name] ??= sorterApiChildLocationId($binStorageId, $name);
    };

    $lastSeq = (int) $key['last_seq'];
    $state = sorterApiNewResolveState();
    $unmapped = [];
    $applied = 0;
    $seqStmt = getPDO()->prepare('UPDATE sorter_api_keys SET last_seq = ? WHERE id = ?');

    // finally: operations before a failing one are already committed.
    try {
        foreach ($ops as $op) {
            $seq = (int) ($op['seq'] ?? 0);
            if ($seq <= $lastSeq) {
                continue;
            }
            switch ($op['type'] ?? '') {
                case 'add':
                    $resolved = sorterApiResolvePartColor((string) ($op['part'] ?? ''), (string) ($op['color'] ?? ''), $state, sorterApiCleanAlts($op['alt'] ?? null));
                    $quantity = (int) ($op['qty'] ?? 1);
                    if ($resolved['part'] === null || $resolved['color'] === null) {
                        sorterApiNoteUnmapped($unmapped, (string) ($op['part'] ?? ''), (string) ($op['color'] ?? ''), $resolved);
                    } elseif ($quantity > 0) {
                        addStorageStock($binId($op), $resolved['part'], $resolved['color'], $condition, $quantity, $userId, null, null, sorterApiNoteFor($note, (string) ($op['part'] ?? ''), $state));
                    }
                    break;

                case 'set':
                    // The bin's whole content, as the sorter counts it: used when
                    // it (re)connects, so StudSphere starts from the same state.
                    $locationId = $binId($op);
                    $wanted = [];
                    $wantedPart = [];
                    foreach ((array) ($op['items'] ?? []) as $item) {
                        $resolved = sorterApiResolvePartColor((string) ($item['part'] ?? ''), (string) ($item['color'] ?? ''), $state, sorterApiCleanAlts($item['alt'] ?? null));
                        if ($resolved['part'] === null || $resolved['color'] === null) {
                            sorterApiNoteUnmapped($unmapped, (string) ($item['part'] ?? ''), (string) ($item['color'] ?? ''), $resolved);
                            continue;
                        }
                        $wantedKey = $resolved['part'] . '|' . $resolved['color'] . '|' . $condition;
                        $wanted[$wantedKey] = ($wanted[$wantedKey] ?? 0) + max(0, (int) ($item['qty'] ?? 0));
                        $wantedPart[$wantedKey] = (string) ($item['part'] ?? '');
                    }
                    foreach (sorterApiLocationItems($locationId) as $row) {
                        $rowKey = $row['part_id'] . '|' . $row['color_id'] . '|' . $row['condition_type'];
                        if (!isset($wanted[$rowKey]) && (int) $row['quantity'] !== 0) {
                            setStorageItemQuantity($locationId, (int) $row['part_id'], (int) $row['color_id'], $row['condition_type'], 0, $userId, null, null, $note);
                        }
                    }
                    foreach ($wanted as $wantedKey => $quantity) {
                        [$partId, $colorId, $cond] = explode('|', $wantedKey);
                        $current = getStorageItemRow($locationId, (int) $partId, (int) $colorId, $cond);
                        if ($current === null || (int) $current['quantity'] !== $quantity) {
                            setStorageItemQuantity($locationId, (int) $partId, (int) $colorId, $cond, $quantity, $userId, null, null, sorterApiNoteFor($note, $wantedPart[$wantedKey], $state));
                        }
                    }
                    break;

                case 'clear':
                    // Emptied without a receipt: the parts left the bin, but to
                    // nowhere StudSphere knows, so the stock goes to zero.
                    $locationId = $binId($op);
                    foreach (sorterApiLocationItems($locationId) as $row) {
                        if ((int) $row['quantity'] !== 0) {
                            setStorageItemQuantity($locationId, (int) $row['part_id'], (int) $row['color_id'], $row['condition_type'], 0, $userId, null, null, $note . ': bin emptied');
                        }
                    }
                    break;

                case 'to_zip':
                    // Emptied into a bag with a receipt: everything in the bin
                    // moves to that bag's own location.
                    $locationId = $binId($op);
                    $zipId = sorterApiChildLocationId($zipStorageId, sorterApiCleanName($op['zip'] ?? '', 'ZIP name'));
                    foreach (sorterApiLocationItems($locationId) as $row) {
                        moveStorageItem($locationId, $zipId, (int) $row['part_id'], (int) $row['color_id'], $row['condition_type'], $userId, $note);
                    }
                    break;

                default:
                    throw new SorterApiError('Unknown operation type at seq ' . $seq . '.');
            }
            $lastSeq = $seq;
            $applied++;
            $seqStmt->execute([$lastSeq, $key['id']]);
        }
    } finally {
        try {
            sorterApiSaveAliases($state);
        } catch (Throwable $e) {
            // Only a cache of Rebrickable's answers: asked again next time.
        }
        if ($applied > 0) {
            refreshAppStatsCache(getPDO());
        }
    }

    $report = [];
    foreach ($unmapped as $pair => $missing) {
        [$unmappedPart, $unmappedColor] = explode('|', (string) $pair, 2);
        $report[] = ['part' => $unmappedPart, 'color' => $unmappedColor, 'missing' => $missing];
    }
    $hinted = [];
    foreach ($state['via'] as $viaPart => $viaAs) {
        $hinted[] = ['part' => (string) $viaPart, 'as' => $viaAs];
    }
    return ['ok' => true, 'applied' => $applied, 'last_seq' => $lastSeq, 'unmapped' => $report, 'hinted' => $hinted];
}

/**
 * The settings page's "Sortiermaschinen" section: the address a sorter
 * needs, the keys, and a form for a new one. A new key is shown here once,
 * right after it's made.
 */
function renderSorterApiKeysSection(string $message, ?string $newKey): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/') . '/';

    $html = '<h2 id="sorter-keys">' . htmlspecialchars(t('sorter_keys_title')) . '</h2>';
    $html .= '<p>' . htmlspecialchars(t('sorter_keys_help')) . '</p>';
    $html .= '<p>' . htmlspecialchars(t('sorter_keys_address')) . ' <code>' . htmlspecialchars($base) . '</code></p>';
    if ($message !== '') {
        $html .= '<p><strong>' . htmlspecialchars($message) . '</strong></p>';
    }
    if ($newKey !== null) {
        $html .= '<section class="card"><p>' . htmlspecialchars(t('sorter_keys_copy_now')) . '</p>'
            . '<p><code class="sorter-key-new">' . htmlspecialchars($newKey) . '</code></p></section>';
    }

    $keys = listSorterApiKeys();
    if ($keys === []) {
        $html .= '<p>' . htmlspecialchars(t('sorter_keys_none')) . '</p>';
    } else {
        $html .= '<ul class="admin-user-list">';
        foreach ($keys as $key) {
            $html .= '<li><strong>' . htmlspecialchars($key['name']) . '</strong> <code>' . htmlspecialchars($key['key_hint']) . '</code>';
            if ($key['revoked_at'] !== null) {
                $html .= ' – ' . htmlspecialchars(t('sorter_keys_state_revoked', ['date' => formatDate($key['revoked_at'], true)]));
            } else {
                $html .= ' – ' . htmlspecialchars($key['machine_id'] !== null
                    ? t('sorter_keys_state_bound', ['sorter' => (string) $key['sorter_name']])
                    : t('sorter_keys_state_unused'));
                if ($key['last_used_at'] !== null) {
                    $html .= ', ' . htmlspecialchars(t('sorter_keys_last_used', ['date' => formatDate($key['last_used_at'], true)]));
                }
                $html .= ' <form method="post" style="display:inline" onsubmit="return confirm(' . htmlspecialchars(json_encode(t('sorter_keys_revoke_confirm')), ENT_QUOTES) . ')">'
                    . '<input type="hidden" name="action" value="admin_revoke_sorter_key"><input type="hidden" name="id" value="' . (int) $key['id'] . '">'
                    . '<button type="submit">' . htmlspecialchars(t('sorter_keys_revoke_button')) . '</button></form>';
                if ($key['machine_id'] !== null) {
                    $html .= ' <form method="post" style="display:inline" onsubmit="return confirm(' . htmlspecialchars(json_encode(t('sorter_keys_unbind_confirm')), ENT_QUOTES) . ')">'
                        . '<input type="hidden" name="action" value="admin_unbind_sorter_key"><input type="hidden" name="id" value="' . (int) $key['id'] . '">'
                        . '<button type="submit">' . htmlspecialchars(t('sorter_keys_unbind_button')) . '</button></form>';
                }
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
    }

    $html .= '<h3>' . htmlspecialchars(t('sorter_keys_add_heading')) . '</h3>';
    $html .= '<form method="post" action="?page=settings#sorter-keys">';
    $html .= '<input type="hidden" name="action" value="admin_create_sorter_key">';
    $html .= '<label>' . htmlspecialchars(t('sorter_keys_name_label')) . '<input name="name" maxlength="100" autocomplete="off" required></label>';
    $html .= '<button type="submit">' . htmlspecialchars(t('sorter_keys_add_button')) . '</button>';
    $html .= '</form>';
    return $html;
}

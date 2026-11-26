<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
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

/**
 * BrickLink part number / color id (what the sorter's classifier speaks)
 * to this database's parts.id / colors.id. Falls back to the Rebrickable
 * part number, which is the same for most parts.
 */
function sorterApiResolvePartColor(string $part, string $color, array &$cache): ?array
{
    $cacheKey = $part . '|' . $color;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $pdo = getPDO();
    $partStmt = $pdo->prepare('SELECT id FROM parts WHERE bricklink_part_id = ? ORDER BY id LIMIT 1');
    $partStmt->execute([$part]);
    $partId = $partStmt->fetchColumn();
    if ($partId === false) {
        $partStmt = $pdo->prepare('SELECT id FROM parts WHERE part_num = ?');
        $partStmt->execute([$part]);
        $partId = $partStmt->fetchColumn();
    }
    $colorId = false;
    if (ctype_digit($color)) {
        $colorStmt = $pdo->prepare('SELECT id FROM colors WHERE bricklink_color_id = ? ORDER BY id LIMIT 1');
        $colorStmt->execute([(int) $color]);
        $colorId = $colorStmt->fetchColumn();
    }
    $cache[$cacheKey] = $partId !== false && $colorId !== false ? [(int) $partId, (int) $colorId] : null;
    return $cache[$cacheKey];
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
    $cache = [];
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
                    $resolved = sorterApiResolvePartColor((string) ($op['part'] ?? ''), (string) ($op['color'] ?? ''), $cache);
                    $quantity = (int) ($op['qty'] ?? 1);
                    if ($resolved === null) {
                        $unmapped[(string) ($op['part'] ?? '') . '|' . (string) ($op['color'] ?? '')] = true;
                    } elseif ($quantity > 0) {
                        addStorageStock($binId($op), $resolved[0], $resolved[1], $condition, $quantity, $userId, null, null, $note);
                    }
                    break;

                case 'set':
                    // The bin's whole content, as the sorter counts it: used when
                    // it (re)connects, so StudSphere starts from the same state.
                    $locationId = $binId($op);
                    $wanted = [];
                    foreach ((array) ($op['items'] ?? []) as $item) {
                        $resolved = sorterApiResolvePartColor((string) ($item['part'] ?? ''), (string) ($item['color'] ?? ''), $cache);
                        if ($resolved === null) {
                            $unmapped[(string) ($item['part'] ?? '') . '|' . (string) ($item['color'] ?? '')] = true;
                            continue;
                        }
                        $wantedKey = $resolved[0] . '|' . $resolved[1] . '|' . $condition;
                        $wanted[$wantedKey] = ($wanted[$wantedKey] ?? 0) + max(0, (int) ($item['qty'] ?? 0));
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
                            setStorageItemQuantity($locationId, (int) $partId, (int) $colorId, $cond, $quantity, $userId, null, null, $note);
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
        if ($applied > 0) {
            refreshAppStatsCache(getPDO());
        }
    }

    return [
        'ok' => true,
        'applied' => $applied,
        'last_seq' => $lastSeq,
        'unmapped' => array_map(
            fn (string $pair): array => array_combine(['part', 'color'], explode('|', $pair, 2)),
            array_keys($unmapped)
        ),
    ];
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

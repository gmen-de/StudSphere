<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Stores PHP's own session data in the app's database instead of the host's
 * shared session directory. index.php already sets gc_maxlifetime to 12
 * months via ini_set() so a login stays valid that long, but on shared
 * hosting (1&1/Strato — no php.ini access, see the hosting-constraint notes
 * elsewhere in this app) that setting alone doesn't actually work: the
 * session save path is shared across every customer on the host, and
 * whichever site's request happens to trigger PHP's probabilistic garbage
 * collection sweep does so using ITS OWN gc_maxlifetime — deleting this
 * app's session files too, regardless of what this app configured. Owning
 * the storage completely (a table only this app ever writes to) sidesteps
 * that entirely; the ini_set() is still needed since PHP passes its value
 * straight through to the gc() callback below.
 *
 * Must be called before session_start(), and only once the "sessions" table
 * is guaranteed to exist (i.e. after installDatabase()/migrations have run).
 */
function registerDatabaseSessionHandler(): void
{
    session_set_save_handler(
        function (): bool {
            return true;
        },
        function (): bool {
            return true;
        },
        function (string $id): string {
            $stmt = getPDO()->prepare('SELECT data FROM sessions WHERE id = ?');
            $stmt->execute([$id]);
            $data = $stmt->fetchColumn();
            return $data !== false ? (string) $data : '';
        },
        function (string $id, string $data): bool {
            $stmt = getPDO()->prepare(
                'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)'
            );
            return $stmt->execute([$id, $data]);
        },
        function (string $id): bool {
            $stmt = getPDO()->prepare('DELETE FROM sessions WHERE id = ?');
            return $stmt->execute([$id]);
        },
        function (int $maxLifetime) {
            $stmt = getPDO()->prepare('DELETE FROM sessions WHERE last_activity < (NOW() - INTERVAL ? SECOND)');
            $stmt->execute([$maxLifetime]);
            return $stmt->rowCount();
        }
    );

    // Recommended whenever a custom save handler is registered (PHP manual,
    // SessionHandler) — without it, the session can end up written after
    // objects it depends on (here: the PDO connection) are already torn
    // down during request shutdown.
    register_shutdown_function('session_write_close');
}

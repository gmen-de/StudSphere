<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Ordered schema migrations, keyed by the schema version they upgrade TO (i.e.
 * key 2 takes the DB from version 1 to version 2). installDatabase() always
 * creates the current shape directly via CREATE TABLE, so a fresh install is
 * stamped at CURRENT_SCHEMA_VERSION immediately and never replays this list —
 * migrations only run for a pre-existing install whose stored schema_version
 * is behind. When changing the schema going forward: update installDatabase()
 * for fresh installs AND add a migration entry here for existing ones, then
 * bump CURRENT_SCHEMA_VERSION.
 */
function getSchemaMigrations(): array
{
    return [
        // 2 => function (PDO $pdo): void {
        //     $pdo->exec('ALTER TABLE ... ');
        // },
    ];
}

const CURRENT_SCHEMA_VERSION = 1;

function getInstalledSchemaVersion(): int
{
    $value = getAppSetting('schema_version');
    return $value !== null ? (int) $value : 0;
}

function setInstalledSchemaVersion(int $version): void
{
    setAppSetting('schema_version', (string) $version);
}

function schemaMigrationPending(): bool
{
    return getInstalledSchemaVersion() < CURRENT_SCHEMA_VERSION;
}

/**
 * Runs exactly one pending migration step (if any) and advances the stored
 * version — bounded, tick-safe, mirrors the same one-step-per-call pattern
 * used by the Rebrickable import and image download.
 *
 * Deliberately does NOT wrap the migration in a transaction: MySQL/MariaDB
 * implicitly commits on DDL (ALTER TABLE, CREATE, DROP, ...), which is what
 * migrations mostly consist of — beginTransaction()/rollBack() around DDL is
 * not actually atomic and a rollBack() after a DDL statement throws "there is
 * no active transaction", masking the real error. Write migrations to be safe
 * to re-run (e.g. check-before-ALTER) so a failure partway through can retry.
 * @return array{done: bool, ranVersion: ?int}
 */
function stepSchemaMigration(): array
{
    $installed = getInstalledSchemaVersion();
    if ($installed >= CURRENT_SCHEMA_VERSION) {
        return ['done' => true, 'ranVersion' => null];
    }

    $migrations = getSchemaMigrations();
    $nextVersion = $installed + 1;

    if (isset($migrations[$nextVersion])) {
        $migrations[$nextVersion](getPDO());
    }

    setInstalledSchemaVersion($nextVersion);

    return ['done' => $nextVersion >= CURRENT_SCHEMA_VERSION, 'ranVersion' => $nextVersion];
}

/**
 * Synchronous convenience wrapper: runs every pending migration in a loop
 * within one call. Fine for schema-only ALTERs on a personal-scale DB; a
 * future migration that needs to backfill large tables row-by-row should be
 * driven via stepSchemaMigration() through a tick loop instead of this.
 */
function runAllPendingMigrations(): int
{
    $count = 0;
    do {
        $result = stepSchemaMigration();
        if ($result['ranVersion'] !== null) {
            $count++;
        }
    } while (!$result['done']);

    return $count;
}

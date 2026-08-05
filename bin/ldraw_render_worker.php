#!/usr/bin/env php
<?php

declare(strict_types=1);

// Persistent CLI worker, meant to run as one or more instances of a systemd
// template unit (see deploy/ldraw-render-worker@.service) — not part of the
// shared-hosting-safe tick pattern the rest of this app uses, since LDraw
// rendering itself (leocad/xvfb-run) is already restricted to self-hosted/
// full-VM installs with a real admin behind them. Claims one
// ldraw_render_queue row at a time and renders it via
// runLdrawRenderWorkerOnce() (src/ldraw.php) — see that function's doc
// comment for why rendering was moved out of the web request cycle
// entirely.
//
// leocad only ever uses a single CPU core per render, so on a multi-core
// host the only way to actually use the rest is running several of these
// processes side by side — safe to do: runLdrawRenderWorkerOnce()'s
// SELECT ... FOR UPDATE already makes claiming a queue row race-free across
// concurrent instances, and renderLdrawPartImage() already gives each call
// its own random temp scene filename and relies on xvfb-run's own -a
// (auto-picks a free X display), so concurrent renders don't collide there
// either. The one thing that must stay exclusive is a given *slot* — mainly
// to protect against a systemd restart racing with a not-yet-dead old
// process for that same slot — so the lock file is keyed by slot number
// (passed as argv[1] by the systemd template unit's %i) rather than shared
// by every instance the way the old single-worker lock was.

require_once __DIR__ . '/../src/ldraw.php';

$workerSlot = $argv[1] ?? '0';
$lockPath = getLdrawStorageDir() . '/worker-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $workerSlot) . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "ldraw_render_worker: another instance already holds slot \"$workerSlot\", exiting.\n");
    exit(1);
}

fwrite(STDOUT, "ldraw_render_worker: started (slot $workerSlot).\n");

// getPDO() caches its connection in a function-static, so a dropped
// connection (DB restart, network blip) can't be recovered from within this
// loop — deliberately fatal here instead, so systemd's Restart=always
// (deploy/ldraw-render-worker@.service) respawns the process with a clean
// connection rather than spinning on a dead one forever.
$pdo = getPDO();

while (true) {
    try {
        $processed = runLdrawRenderWorkerOnce($pdo);
        if (!$processed) {
            sleep(2);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "ldraw_render_worker (slot $workerSlot): " . $e->getMessage() . "\n");
        exit(1);
    }
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

// Persistent CLI worker, meant to run as a systemd service (see
// deploy/ldraw-render-worker.service) — not part of the shared-hosting-safe
// tick pattern the rest of this app uses, since LDraw rendering itself
// (leocad/xvfb-run) is already restricted to self-hosted/full-VM installs
// with a real admin behind them. Claims one ldraw_render_queue row at a
// time and renders it via runLdrawRenderWorkerOnce() (src/ldraw.php) — see
// that function's doc comment for why rendering was moved out of the web
// request cycle entirely.

require_once __DIR__ . '/../src/ldraw.php';

$lockPath = getLdrawStorageDir() . '/worker.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "ldraw_render_worker: another instance is already running, exiting.\n");
    exit(1);
}

fwrite(STDOUT, "ldraw_render_worker: started.\n");

// getPDO() caches its connection in a function-static, so a dropped
// connection (DB restart, network blip) can't be recovered from within this
// loop — deliberately fatal here instead, so systemd's Restart=always
// (deploy/ldraw-render-worker.service) respawns the process with a clean
// connection rather than spinning on a dead one forever.
$pdo = getPDO();

while (true) {
    try {
        $processed = runLdrawRenderWorkerOnce($pdo);
        if (!$processed) {
            sleep(2);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'ldraw_render_worker: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

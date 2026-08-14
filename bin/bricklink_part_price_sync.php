#!/usr/bin/env php
<?php

declare(strict_types=1);

// One-shot cron entry point: does at most one throttled BrickLink part-price
// lookup and exits — unlike ldraw_render_worker.php (a persistent daemon,
// the wrong precedent here), this is meant to be invoked frequently (e.g.
// every minute) by a real crontab on installs that have one. It's not this
// script's own invocation frequency that enforces the actual cadence:
// stepBricklinkPartPriceSync() (src/bricklink_prices.php) gates itself on a
// randomized 10-300 second next-allowed-at timestamp shared, via the same
// app_settings row and a MariaDB advisory lock, with the opportunistic
// "web cron" tick already called from index.php on every page load — so
// running this more often than that cadence is harmless, just a no-op, and
// running both this and relying on page-load traffic at the same time is
// safe too (they throttle each other, not just themselves).
//
// See README.md's "Cronjob" section for the crontab line to add, and the
// Settings page for the on/off toggle both invocation paths read.

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/rebrickable.php';
require_once __DIR__ . '/../src/bricklink_prices.php';

stepBricklinkPartPriceSync(getPDO());

<?php

declare(strict_types=1);

/**
 * Authenticated AJAX/form action handlers — required by index.php right
 * after the "not logged in -> show login form" gate, so every handler here
 * can assume $_SESSION['user_id'] is set. Covers the whole tick/import/
 * storage/owned-set/part action surface in one file (not split further by
 * domain) because several handlers set a $xMessage variable a page render
 * later in src/routes/pages.php reads back (e.g. $locationMessage,
 * $ownedSetDetailMessage) — keeping them in their original relative order,
 * all required before pages.php, is what makes that still work. The two
 * small helper functions in the middle (buildOwnedSetLiveUpdatePayload(),
 * findOwnedSetMinifig()) are only used by the owned-set-minifig handlers
 * around them, not general library code, which is why they live here
 * rather than in src/owned_sets.php.
 *
 * import/update_data/update_rebrickable_settings/update_ldraw_settings
 * lead the file (rather than sitting with the other tick handlers further
 * down, matching their original position) because they used to live in
 * src/routes/pre_auth.php, reachable by an unauthenticated POST — moved
 * here, behind the login gate, to close that gap.
 */

$importMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        $type = $_POST['import_type'] ?? '';
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(t('error_import_file_required'));
        }
        $result = importCsv($_FILES['import_file']['tmp_name'], $type);
        $importMessage = t('import_success_rows', ['count' => $result['rows'] ?? 0]);
    } catch (Throwable $e) {
        $importMessage = t('import_failure_message', ['message' => $e->getMessage()]);
    }
}

// Replaces the old synchronous "update_data" action, which ran
// downloadAndImportRebrickableData() start-to-finish in one HTTP request —
// measured 455s against a real Rebrickable export (1.5M+ inventory_parts
// rows alone), reliably past both PHP's own max_execution_time and the
// SSL offloader's timeout, with zero progress shown while it ran. Drives
// the same stepRebrickableImport() tick machine setup.php's first-run
// install already uses (see calculateFileProgressFraction()'s doc comment
// in src/download.php for why the two share their payload builder), one
// bounded chunk per request, polled by the settings page's "Update jetzt"
// modal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rebrickable_update_tick') {
    header('Content-Type: application/json');
    $state = null;
    try {
        $state = $_SESSION['rebrickable_update_state'] ?? null;
        if (!is_array($state)) {
            $state = initRebrickableImportState();
        }

        $result = stepRebrickableImport($state);
        $_SESSION['rebrickable_update_state'] = $state;

        $payload = buildImportProgressPayload($state, $result['done']);

        if ($result['done']) {
            // Mirrors downloadAndImportRebrickableData()'s own tail —
            // one-shot cleanup/enrichment stepRebrickableImport() itself
            // doesn't do per-tick, only once every file has settled.
            try {
                $pdo->exec('TRUNCATE TABLE part_set_counts');
            } catch (Throwable $e) {
                // Table may not exist yet on a pre-migration install.
            }
            syncExternalColorIds();
            unset($_SESSION['rebrickable_update_state']);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Keep whatever state exists so the next tick resumes instead of
        // restarting from file #1 (mirrors setup.php's import_tick).
        $payload = is_array($state)
            ? array_merge(buildImportProgressPayload($state, false), [
                'status' => 'error',
                'message' => t('import_error', ['message' => $e->getMessage()]),
            ])
            : ['status' => 'error', 'percent' => 0, 'message' => t('import_error', ['message' => $e->getMessage()]), 'files' => []];
        http_response_code(500);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// "Baubare Sets" scan tick (?page=build_sets, src/build_sets.php) — same
// $_SESSION-state/bounded-chunk-per-request pattern as
// rebrickable_update_tick above, just for the internal set-completeness
// scan instead of a Rebrickable download. Every tick (not just the first)
// carries the chosen scope (theme/year_from/year_to) as POST fields —
// cheap, and it lets a scope mismatch against the session's stored state be
// detected below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build_sets_scan_tick') {
    header('Content-Type: application/json');
    try {
        $scanThemeId = isset($_POST['theme']) && $_POST['theme'] !== '' ? (int) $_POST['theme'] : null;
        $scanYearFrom = isset($_POST['year_from']) && $_POST['year_from'] !== '' ? (int) $_POST['year_from'] : null;
        $scanYearTo = isset($_POST['year_to']) && $_POST['year_to'] !== '' ? (int) $_POST['year_to'] : null;
        $requestedScope = ['theme_id' => $scanThemeId, 'year_from' => $scanYearFrom, 'year_to' => $scanYearTo];

        $state = $_SESSION['build_sets_scan_state'] ?? null;
        // A stored state from a *different* scope (the user reconfigured
        // and started a new scan while an old, unfinished one was still
        // sitting in the session) must not be silently resumed under the
        // new scope's name — discard it and start over. A reload of the
        // same in-progress scan sends the same scope every tick, so this
        // never interrupts a legitimate resume.
        if (is_array($state) && ($state['scope'] ?? null) !== $requestedScope) {
            $state = null;
        }
        if (!is_array($state)) {
            $state = initBuildSetsScanState($pdo, $scanThemeId, $scanYearFrom, $scanYearTo);
        }

        $result = stepBuildSetsScan($pdo, $state);
        if ($result['done']) {
            unset($_SESSION['build_sets_scan_state']);
        } else {
            $_SESSION['build_sets_scan_state'] = $state;
        }

        echo json_encode([
            'processed' => $result['processed'],
            'total' => $result['total'],
            'percent' => $result['total'] > 0 ? (int) round(($result['processed'] / $result['total']) * 100) : 100,
            'done' => $result['done'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        unset($_SESSION['build_sets_scan_state']);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => t('import_error', ['message' => $e->getMessage()])], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Same tick-based scan pattern as build_sets_scan_tick above, for "Baubare
// Minifiguren" (src/build.php) — always the whole catalog, no scope to
// carry along.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build_minifigs_scan_tick') {
    header('Content-Type: application/json');
    try {
        $state = $_SESSION['build_minifigs_scan_state'] ?? null;
        if (!is_array($state)) {
            $state = initBuildMinifigsScanState($pdo);
        }

        $result = stepBuildMinifigsScan($pdo, $state);
        if ($result['done']) {
            unset($_SESSION['build_minifigs_scan_state']);
        } else {
            $_SESSION['build_minifigs_scan_state'] = $state;
        }

        echo json_encode([
            'processed' => $result['processed'],
            'total' => $result['total'],
            'percent' => $result['total'] > 0 ? (int) round(($result['processed'] / $result['total']) * 100) : 100,
            'done' => $result['done'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        unset($_SESSION['build_minifigs_scan_state']);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => t('import_error', ['message' => $e->getMessage()])], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$collectionMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_collection_settings') {
    $newCollectionName = trim($_POST['collection_name'] ?? '');
    if ($newCollectionName === '') {
        $collectionMessage = t('error_collection_name_required');
    } else {
        setAppSetting('collection_name', $newCollectionName);
        $collectionMessage = t('settings_collection_saved');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_rebrickable_settings') {
    setAppSetting('rebrickable_download_base_url', trim($_POST['download_base_url'] ?? ''));
    setAppSetting('rebrickable_api_url', trim($_POST['api_url'] ?? ''));
    setAppSetting('rebrickable_api_key', trim($_POST['api_key'] ?? ''));
    $importMessage = t('settings_rebrickable_saved');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ldraw_settings') {
    setAppSetting('ldraw_rendering_enabled', ($_POST['ldraw_enabled'] ?? '') === '1' ? '1' : '0');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_bricklink_part_sync_settings') {
    setAppSetting('bricklink_part_sync_enabled', ($_POST['bricklink_part_sync_enabled'] ?? '') === '1' ? '1' : '0');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'image_tick') {
    header('Content-Type: application/json');
    try {
        $state = $_SESSION['image_download_state'] ?? null;
        if (!is_array($state)) {
            $forceRefresh = ($_POST['force_refresh'] ?? '') === '1';
            $state = initImageDownloadState($forceRefresh);
        }

        $result = stepImageDownload($state);
        $_SESSION['image_download_state'] = $state;

        $payload = buildImageProgressPayload($state, $result['done']);

        if ($result['done']) {
            unset($_SESSION['image_download_state']);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'percent' => 0,
            'message' => t('import_error', ['message' => $e->getMessage()]),
            'tables' => [],
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ldraw_library_tick') {
    header('Content-Type: application/json');
    try {
        $toolsCheck = ldrawToolsAvailable();
        if (!$toolsCheck['available']) {
            throw new RuntimeException(t('ldraw_tools_unavailable', ['missing' => implode(', ', $toolsCheck['missing'])]));
        }

        $state = $_SESSION['ldraw_library_state'] ?? null;
        if (!is_array($state)) {
            $state = initLdrawLibraryState();
        }

        $result = stepLdrawLibraryDownload($state);
        $_SESSION['ldraw_library_state'] = $state;

        if ($result['done']) {
            unset($_SESSION['ldraw_library_state']);
            echo json_encode(['status' => 'done', 'percent' => 100, 'message' => t('ldraw_library_ready')], JSON_UNESCAPED_UNICODE);
        } else {
            $downloadFraction = ($state['zipTotalBytes'] ?? 0) > 0 ? min(1.0, $state['zipBytes'] / $state['zipTotalBytes']) : 0.0;
            $extractFraction = $state['extractTotal'] > 0 ? min(1.0, $state['extractIndex'] / $state['extractTotal']) : 0.0;
            $fraction = in_array($state['stage'], ['extract', 'done'], true) ? 0.8 + $extractFraction * 0.2 : $downloadFraction * 0.8;
            $stageMessageKey = [
                'probe' => 'ldraw_stage_probe',
                'download' => 'ldraw_stage_download',
                'extract' => 'ldraw_stage_extract',
            ][$state['stage']] ?? 'ldraw_stage_probe';
            echo json_encode([
                'status' => 'running',
                'percent' => (int) round($fraction * 100),
                'message' => t($stageMessageKey),
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        unset($_SESSION['ldraw_library_state']);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'percent' => 0, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ldraw_set_render_tick') {
    header('Content-Type: application/json');
    // Rendering itself now happens entirely outside this request — see
    // runLdrawRenderWorkerOnce() (src/ldraw.php) and bin/ldraw_render_worker.php.
    // This tick only enqueues what's still missing and reports queue state,
    // so it's always fast; no set_time_limit(0)/session state needed anymore.
    try {
        if (!ldrawContextualRenderingReady()) {
            throw new RuntimeException(t('ldraw_tools_unavailable', ['missing' => 'leocad, xvfb']));
        }

        $inventoryId = (int) ($_POST['inventory_id'] ?? 0);
        if ($inventoryId <= 0) {
            throw new RuntimeException(t('ldraw_invalid_inventory'));
        }

        $items = getSetPartsList($pdo, $inventoryId, false, getLocale());
        $payload = getLdrawSetRenderProgress($pdo, $items);

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'percent' => 0, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Catalog set_detail's "Bauteile auf Pickliste setzen" dialog polls this
// right after creating a pick list (renderCreatePickListFromSetModal(),
// src/pick_lists.php) — same tick shape as ldraw_set_render_tick above, just
// scoped to one pick list's own items (getLdrawPickListRenderProgress(),
// src/ldraw.php) instead of a whole set's inventory. Duplicated in
// src/routes/pick_actions.php for the PWA's own create screen, which is a
// genuinely separate entry point with its own action dispatch (see that
// file's doc comment) — not reachable from here.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ldraw_pick_list_render_tick') {
    header('Content-Type: application/json');
    try {
        if (!ldrawContextualRenderingReady()) {
            throw new RuntimeException(t('ldraw_tools_unavailable', ['missing' => 'leocad, xvfb']));
        }

        $tickPickListId = (int) ($_POST['pick_list_id'] ?? 0);
        if ($tickPickListId <= 0) {
            throw new RuntimeException(t('ldraw_invalid_inventory'));
        }

        echo json_encode(getLdrawPickListRenderProgress($pdo, $tickPickListId), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'percent' => 0, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_update') {
    header('Content-Type: application/json');
    try {
        $release = isUpdateAvailable();
        if ($release !== null) {
            $_SESSION['update_available_release'] = $release;
        } else {
            unset($_SESSION['update_available_release']);
        }
        echo json_encode([
            'available' => $release !== null,
            'release' => $release,
            'currentVersion' => getCurrentVersion(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['available' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_tick') {
    header('Content-Type: application/json');
    try {
        $state = $_SESSION['update_state'] ?? null;
        if (!is_array($state)) {
            $release = $_SESSION['update_available_release'] ?? null;
            if ($release === null) {
                throw new RuntimeException(t('update_no_release_selected'));
            }
            $state = initUpdateState($release);
        }

        $result = stepUpdate($state);
        $_SESSION['update_state'] = $state;

        $payload = buildUpdateProgressPayload($state, $result['done']);

        if ($result['done']) {
            unset($_SESSION['update_state'], $_SESSION['update_available_release']);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        unset($_SESSION['update_state']);
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'percent' => 0,
            'message' => t('update_failed', ['message' => $e->getMessage()]),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$locationMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_location') {
    $name = trim($_POST['name'] ?? '');
    $parentId = trim($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
    $bulkEnabled = ($_POST['bulk_enabled'] ?? '') === '1';
    $childCount = (int) ($_POST['child_count'] ?? 0);

    if ($name === '') {
        $locationMessage = t('location_name_required');
    } else {
        try {
            // No separate "naming pattern" field — bulk mode just uses the
            // name itself as the pattern (e.g. "Fach {n}"), creating
            // $childCount numbered children of $parentId directly.
            if ($bulkEnabled && $childCount > 0) {
                createBulkStorageLocations($parentId, $childCount, $name);
            } else {
                createStorageLocation($parentId, $name);
            }
            $locationMessage = t('location_added_message', ['name' => $name]);
        } catch (Throwable $e) {
            $locationMessage = t('location_save_failed', ['message' => $e->getMessage()]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_owned_set') {
    header('Content-Type: application/json');
    $ownedSetSetId = (int) ($_POST['set_id'] ?? 0);
    $parentLocationRaw = trim((string) ($_POST['parent_location_id'] ?? ''));
    $parentLocationId = $parentLocationRaw !== '' ? (int) $parentLocationRaw : null;
    $conditionType = ($_POST['condition_type'] ?? 'used') === 'new' ? 'new' : 'used';
    $hasInstructions = ($_POST['has_instructions'] ?? '') === '1';
    $hasBox = ($_POST['has_box'] ?? '') === '1';
    $boxComplete = ($_POST['box_complete'] ?? '') === '1';
    $ownedSetNotes = trim((string) ($_POST['notes'] ?? ''));
    $ownedSetNotes = $ownedSetNotes !== '' ? $ownedSetNotes : null;
    $instructionsNotes = trim((string) ($_POST['instructions_notes'] ?? ''));
    $instructionsNotes = $instructionsNotes !== '' ? $instructionsNotes : null;
    $boxNotes = trim((string) ($_POST['box_notes'] ?? ''));
    $boxNotes = $boxNotes !== '' ? $boxNotes : null;
    $boxCompleteNotes = trim((string) ($_POST['box_complete_notes'] ?? ''));
    $boxCompleteNotes = $boxCompleteNotes !== '' ? $boxCompleteNotes : null;
    $stickersApplied = ($_POST['stickers_applied'] ?? '') === '1';
    $stickersNotes = trim((string) ($_POST['stickers_notes'] ?? ''));
    $stickersNotes = $stickersNotes !== '' ? $stickersNotes : null;
    $inventoryIdRaw = trim((string) ($_POST['inventory_id'] ?? ''));
    $ownedSetInventoryId = $inventoryIdRaw !== '' ? (int) $inventoryIdRaw : null;
    $pickListIdRaw = trim((string) ($_POST['pick_list_id'] ?? ''));
    $sourcePickListId = $pickListIdRaw !== '' ? (int) $pickListIdRaw : null;

    try {
        $ownedSetCatalogSet = $ownedSetSetId > 0 ? getSetById($pdo, $ownedSetSetId) : null;
        if ($ownedSetCatalogSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        if ($parentLocationId === null) {
            throw new RuntimeException(t('owned_set_wizard_location_required'));
        }
        if ($sourcePickListId !== null) {
            $sourcePickList = getPickList($pdo, $sourcePickListId);
            if ($sourcePickList === null || (int) $sourcePickList['user_id'] !== (int) $_SESSION['user_id']
                || !in_array($sourcePickList['status'], ['active', 'completed'], true)
            ) {
                throw new RuntimeException(t('owned_set_invalid_pick_list'));
            }
        }
        $newOwnedSetId = addOwnedSet(
            $pdo,
            $ownedSetSetId,
            $parentLocationId,
            $conditionType,
            $hasInstructions,
            $hasBox,
            $boxComplete,
            $ownedSetNotes,
            (int) $_SESSION['user_id'],
            $instructionsNotes,
            $boxNotes,
            $boxCompleteNotes,
            $ownedSetInventoryId,
            $stickersApplied,
            $stickersNotes,
            $sourcePickListId
        );
        refreshAppStatsCache($pdo);
        // Fetched synchronously here (not left to the opportunistic
        // background sync) per user feedback: adding a set should behave
        // like an immediate manual price refresh, not something that might
        // sit queued for a while — see stepBricklinkPriceSync()'s doc
        // comment for why *that* one stays throttled/background-only (it's
        // triggered by every page load, this is one deliberate add).
        try {
            setAppSetting('bricklink_sync_last_run', date('Y-m-d H:i:s'));
            refreshBricklinkPriceForSet($pdo, $ownedSetCatalogSet);
        } catch (Throwable $e) {
            // Best-effort — a slow/unreachable BrickLink must never block
            // adding the set itself.
        }
        echo json_encode(['success' => true, 'ownedSetId' => $newOwnedSetId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// owned_set_detail's "Pickliste" action-bar button (src/routes/pages.php) —
// creates a pick list scoped to only what's still MISSING from this owned
// instance (nominal - actual + damaged, same "wanted" formula used
// elsewhere in this file's BrickLink-XML export), not the set's full parts
// list from scratch. Redirects into /pick/ itself rather than returning
// JSON, since the button is a plain form submit, not a fetch() call —
// simpler than duplicating the description-prompt + fetch dance the /pick/
// PWA's own create screen uses, for a button that only ever needs one
// specific owned set as its target.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pick_list_from_owned_set') {
    $ownedSetIdForPickList = (int) ($_POST['owned_set_id'] ?? 0);
    $pickListName = trim((string) ($_POST['name'] ?? ''));
    $pickListDescription = trim((string) ($_POST['description'] ?? ''));
    $ownedSetForPickList = $ownedSetIdForPickList > 0 ? getOwnedSetById($pdo, $ownedSetIdForPickList) : null;
    if ($ownedSetForPickList === null || $pickListName === '' || $pickListDescription === '') {
        http_response_code(400);
        exit;
    }

    $missingParts = [];
    foreach (getOwnedSetPartsWithStatus($pdo, $ownedSetForPickList) as $item) {
        $wanted = max(0, $item['nominal_quantity'] - $item['actual_quantity']) + $item['damaged_quantity'];
        if ($wanted > 0) {
            $missingParts[$item['part_id'] . ':' . $item['color_id']] = $wanted;
        }
    }
    $missingMinifigs = [];
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSetForPickList) as $fig) {
        $wanted = max(0, $fig['nominal_quantity'] - $fig['actual_quantity']) + $fig['damaged_quantity'];
        if ($wanted > 0) {
            $missingMinifigs[$fig['minifig_id']] = $wanted;
        }
    }

    $newPickListId = createPickList(
        $pdo, (int) $_SESSION['user_id'], 'set', (int) $ownedSetForPickList['set_id'], $pickListName, $pickListDescription,
        $ownedSetIdForPickList, ['parts' => $missingParts, 'minifigs' => $missingMinifigs]
    );
    if ($newPickListId === null) {
        http_response_code(400);
        exit;
    }
    header('Location: pick/index.php?screen=pick&id=' . $newPickListId);
    exit;
}

// Catalog set_detail's "Bauteile auf Pickliste setzen" dialog
// (src/routes/pages.php) — lists exactly which of this set's needed
// part+colors already have loose stock, so the dialog can pre-check them
// (getSetAvailablePartsForPickList(), src/pick_lists.php).
if (isset($_GET['action']) && $_GET['action'] === 'set_available_parts_for_pick_list') {
    header('Content-Type: application/json');
    $availPartsSetId = (int) ($_GET['set_id'] ?? 0);
    $availPartsSet = $availPartsSetId > 0 ? getSetById($pdo, $availPartsSetId) : null;
    if ($availPartsSet === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('pick_error_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $availPartsInventoryId = getSetInventoryId($pdo, $availPartsSet['rebrickable_set_num']);
    $availableParts = $availPartsInventoryId !== null
        ? getSetAvailablePartsForPickList($pdo, $availPartsInventoryId, getLocale())
        : [];
    echo json_encode([
        'success' => true,
        'parts' => $availableParts,
        'defaultDescription' => $availPartsSet['rebrickable_set_num'] . ' - ' . $availPartsSet['name'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pick_list_from_set_available') {
    header('Content-Type: application/json');
    $availSetId = (int) ($_POST['set_id'] ?? 0);
    $availListName = trim((string) ($_POST['name'] ?? ''));
    $availDescription = trim((string) ($_POST['description'] ?? ''));
    // "part_id:color_id" => requested quantity — createPickListFromAvailableParts()
    // clamps each to the freshly-recomputed available amount itself, this
    // only filters out blank/non-numeric/zero-or-negative client input.
    $availQuantities = [];
    foreach ((array) ($_POST['quantities'] ?? []) as $availKey => $availQtyRaw) {
        $availQty = (int) $availQtyRaw;
        if ($availQty > 0) {
            $availQuantities[trim((string) $availKey)] = $availQty;
        }
    }

    if ($availSetId <= 0 || $availListName === '' || $availDescription === '' || empty($availQuantities)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('pick_error_invalid_request')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $newAvailPickListResult = createPickListFromAvailableParts($pdo, (int) $_SESSION['user_id'], $availSetId, $availListName, $availDescription, $availQuantities);
        if ($newAvailPickListResult === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => t('pick_error_no_inventory')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'success' => true,
            'pickListId' => $newAvailPickListResult['pickListId'],
            'description' => $availDescription,
            'message' => t('set_pick_list_success', [
                'name' => $availListName,
                'count' => (string) $newAvailPickListResult['totalQuantity'],
            ]),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename_location') {
    $locationId = (int) ($_POST['location_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    // Present whenever the edit form's own move-picker rendered (always, for
    // this action) — '' means "top level" (no parent), same convention
    // move_owned_set/the other location pickers use.
    $newParentIdRaw = trim((string) ($_POST['parent_id'] ?? ''));
    $newParentId = $newParentIdRaw !== '' ? (int) $newParentIdRaw : null;

    if ($name === '') {
        $locationMessage = t('location_name_required');
    } else {
        try {
            renameStorageLocation($locationId, $name);
            $currentLocation = getStorageLocation($locationId);
            $currentParentId = $currentLocation !== null && $currentLocation['parent_id'] !== null
                ? (int) $currentLocation['parent_id']
                : null;
            if ($currentLocation !== null && $newParentId !== $currentParentId) {
                moveStorageLocation($locationId, $newParentId);
            }
            $locationMessage = t('location_updated_message');
        } catch (Throwable $e) {
            $locationMessage = t('location_save_failed', ['message' => $e->getMessage()]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_location') {
    $locationId = (int) ($_POST['location_id'] ?? 0);
    if (locationHasChildren($locationId)) {
        $locationMessage = t('location_delete_blocked_children');
    } elseif (locationHasStock($locationId)) {
        $locationMessage = t('location_delete_blocked_stock');
    } else {
        try {
            deleteStorageLocation($locationId);
            $locationMessage = t('location_deleted_message');
        } catch (Throwable $e) {
            $locationMessage = t('location_save_failed', ['message' => $e->getMessage()]);
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'location_children') {
    header('Content-Type: application/json');
    $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int) $_GET['parent_id'] : null;
    $children = getChildLocations($parentId);
    echo json_encode(['children' => $children], JSON_UNESCAPED_UNICODE);
    exit;
}

// window.createLocationPicker()'s optional pre-selection (app.js) — given a
// remembered location id, the picker needs its full root-to-target ancestor
// chain up front to restore the breadcrumb/select state without a click.
if (isset($_GET['action']) && $_GET['action'] === 'location_ancestors') {
    header('Content-Type: application/json');
    $ancestorsLocationId = (int) ($_GET['id'] ?? 0);
    $ancestors = $ancestorsLocationId > 0 ? getStorageLocationAncestors($ancestorsLocationId) : [];
    echo json_encode(['ancestors' => $ancestors], JSON_UNESCAPED_UNICODE);
    exit;
}

// The location Explorer's right pane (src/routes/pages.php's ?page=locations,
// see renderLocationExplorer() there) — everything stored anywhere under the
// clicked location, recursively, grouped for display. Also doubles as the
// content view for a boxed set's own auto-generated node (location_type
// 'owned_set', now shown in the tree too — see getStorageLocationTree()'s
// doc comment, src/storage.php): getLocationContentRecursive() already
// resolves such a node's own materialized parts/minifigs correctly (its
// exclusion of 'owned_set' only ever applies to *descendants*, and one of
// these never has any) — the one thing this route adds for that case is
// $readOnly, since editing a set's own tracked inventory through the
// generic "move/relocate a storage item" actions would silently desync its
// completeness tracking (see getOwnedSetCompleteness(), src/owned_sets.php)
// without going through the set's own proper edit flow.
if (isset($_GET['action']) && $_GET['action'] === 'location_content') {
    header('Content-Type: application/json');
    $locationId = (int) ($_GET['location_id'] ?? 0);
    $location = getStorageLocation($locationId);
    if ($location === null) {
        http_response_code(404);
        echo json_encode(['error' => t('location_detail_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $readOnly = in_array($location['location_type'], ['owned_set', 'pick_list'], true);
    $ownedSetId = null;
    if ($readOnly) {
        $ownedSetIdStmt = $pdo->prepare('SELECT id FROM owned_sets WHERE location_id = ?');
        $ownedSetIdStmt->execute([$locationId]);
        $ownedSetIdValue = $ownedSetIdStmt->fetchColumn();
        $ownedSetId = $ownedSetIdValue !== false ? (int) $ownedSetIdValue : null;
    }

    // Locations under "Bauanleitungen" (location_type='instructions_root')
    // are dedicated exclusively to instruction manuals — never mixed with
    // storage_items/minifig_storage_items — so this short-circuits into its
    // own response shape entirely, skipping the parts/minifig assembly below.
    if (isLocationInInstructionsSubtree($pdo, $locationId)) {
        $instructionManuals = getInstructionManualTilesForLocation($pdo, $locationId, getLocale());

        $manualLocationInfoCache = [];
        foreach ($instructionManuals as &$manual) {
            $manualLocationId = $manual['location_id'];
            if ($manualLocationId === $locationId) {
                $manual['location_label'] = null;
            } else {
                if (!array_key_exists($manualLocationId, $manualLocationInfoCache)) {
                    $ancestors = getStorageLocationAncestors($manualLocationId);
                    $rootIndex = null;
                    foreach ($ancestors as $i => $ancestor) {
                        if ($ancestor['id'] === $locationId) {
                            $rootIndex = $i;
                            break;
                        }
                    }
                    $relevant = $rootIndex !== null ? array_slice($ancestors, $rootIndex + 1) : $ancestors;
                    $manualLocationInfoCache[$manualLocationId] = implode(' -> ', array_column($relevant, 'name'));
                }
                $manual['location_label'] = $manualLocationInfoCache[$manualLocationId];
            }
        }
        unset($manual);

        echo json_encode([
            'isInstructionsLocation' => true,
            'instructionManuals' => $instructionManuals,
            'categories' => [],
            'minifigs' => [],
            'ldraw' => null,
            'readOnly' => false,
            'ownedSetId' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $content = getLocationContentRecursive($pdo, $locationId);

    $allPartsFlat = [];
    foreach ($content['partsByCategory'] as $parts) {
        $allPartsFlat = array_merge($allPartsFlat, $parts);
    }

    // Enqueue whatever's missing a color-correct image with the same
    // persistent worker the set-detail page uses (see getLdrawSetRenderProgress()
    // in src/ldraw.php) — this was the only gap: nothing outside the set-detail
    // page ever called into the render queue, so loose parts viewed here never
    // got their missing images generated at all, however long you waited.
    $ldrawStatus = null;
    if (ldrawContextualRenderingReady()) {
        $missingLdrawPairs = getMissingLdrawRenderPairs($pdo, $allPartsFlat);
        enqueueLdrawRenders($pdo, $missingLdrawPairs);
        $queueStatus = getLdrawQueueStatus($pdo);
        $ldrawStatus = [
            'missingCount' => count($missingLdrawPairs),
            'currentPart' => $queueStatus['currentPart'],
            'queueDepth' => $queueStatus['queueDepth'],
        ];
    }

    $allPartIds = array_values(array_unique(array_column($allPartsFlat, 'part_id')));
    $translations = getLocale() !== 'en' ? getPartTranslations($pdo, $allPartIds, getLocale()) : [];

    // Falls back to getPartThumbnails()'s catalog-wide, color-agnostic image
    // when there's no color-verified one (previously: no image at all here,
    // on purpose — that helper finds *any* image for the part number
    // regardless of color, which had shown a plausibly-wrong-colored image
    // for at least one real part). Per explicit follow-up request, a
    // possibly-wrong-colored image beats no image — but the client still
    // needs to know which is which, so $part['thumbnail_unverified'] flags
    // exactly the rows using this fallback, for a visual "color unconfirmed"
    // marker instead of silently presenting it as equally reliable.
    $genericThumbnails = getPartThumbnails($pdo, $allPartIds);

    // Cached BrickLink unit price per part+color (see getPartBricklinkPrices()'s
    // own doc comment) — pure cache read, no fetch triggered here; a part
    // simply shows no price until stepBricklinkPartPriceSync()/the cronjob
    // gets to it.
    $partColorPrices = getPartBricklinkPrices($pdo, array_map(
        fn (array $p): array => ['part_id' => $p['part_id'], 'color_id' => $p['color_id']],
        $allPartsFlat
    ));

    // Sub-grouping by where an item actually sits, for the client — only
    // meaningful once a recursively-viewed location (see
    // getLocationContentRecursive()'s own doc comment, src/storage.php)
    // actually spans more than one distinct spot, which the client decides
    // (it skips the sub-header entirely when everything resolves to the
    // same label). label is null for "directly at $locationId itself, not
    // in any sub-location" — anything else is the path from $locationId
    // down to wherever the item really is. readOnly piggybacks on the same
    // ancestor walk: getLocationSubtreeIds() (src/storage.php) now includes
    // nested owned_set locations too, so a recursive view can mix genuinely
    // loose items with ones that are actually inside a tracked set — those
    // must stay non-interactive per item (not gated on the whole response
    // like $readOnly above, which only covers *directly* viewing a set's
    // own node) since editing them through the generic move/relocate
    // actions would desync that set's own completeness tracking. An item
    // directly at $locationId itself just inherits the page-level $readOnly.
    // Cached per location_id since many rows commonly share one.
    $locationInfoCache = [];
    $resolveLocationInfo = function (int $itemLocationId) use ($locationId, $readOnly, &$locationInfoCache): array {
        if ($itemLocationId === $locationId) {
            return ['label' => null, 'readOnly' => $readOnly];
        }
        if (!array_key_exists($itemLocationId, $locationInfoCache)) {
            $ancestors = getStorageLocationAncestors($itemLocationId);
            $rootIndex = null;
            foreach ($ancestors as $i => $ancestor) {
                if ($ancestor['id'] === $locationId) {
                    $rootIndex = $i;
                    break;
                }
            }
            $relevant = $rootIndex !== null ? array_slice($ancestors, $rootIndex + 1) : $ancestors;
            $ownLocationType = !empty($ancestors) ? end($ancestors)['location_type'] : null;
            $locationInfoCache[$itemLocationId] = [
                'label' => implode(' -> ', array_column($relevant, 'name')),
                'readOnly' => in_array($ownLocationType, ['owned_set', 'pick_list'], true),
            ];
        }
        return $locationInfoCache[$itemLocationId];
    };

    $categories = [];
    foreach ($content['partsByCategory'] as $categoryName => $parts) {
        foreach ($parts as &$part) {
            $part['part_name'] = $translations[$part['part_id']] ?? $part['part_name'];
            if ($part['ldraw_thumbnail'] !== null) {
                $part['thumbnail'] = $part['ldraw_thumbnail'];
                $part['thumbnail_unverified'] = false;
            } else {
                $part['thumbnail'] = $genericThumbnails[$part['part_id']] ?? null;
                $part['thumbnail_unverified'] = $part['thumbnail'] !== null;
            }
            $locationInfo = $resolveLocationInfo($part['location_id']);
            $part['location_label'] = $locationInfo['label'];
            $part['read_only'] = $locationInfo['readOnly'];
            $priceEntry = $partColorPrices[$part['part_id'] . ':' . $part['color_id']] ?? null;
            $unitPrice = $priceEntry !== null ? ($part['condition_type'] === 'new' ? $priceEntry['new'] : $priceEntry['used']) : null;
            if ($unitPrice !== null) {
                $currencySymbol = bricklinkCurrencySymbol($priceEntry['currency']);
                $priceText = formatNumber($unitPrice, 2) . ' ' . $currencySymbol;
                // Only worth showing a total alongside the unit price once
                // quantity makes them actually differ — at 1x they're the
                // same number twice.
                if ((int) $part['quantity'] > 1) {
                    $priceText .= ' (' . formatNumber($unitPrice * (int) $part['quantity'], 2) . ' ' . $currencySymbol . ')';
                }
                $part['bricklink_unit_price'] = $priceText;
            } else {
                $part['bricklink_unit_price'] = null;
            }
            unset($part['ldraw_thumbnail'], $part['rebrickable_color_id']);
        }
        unset($part);
        $categories[] = [
            'name' => $categoryName !== '' ? $categoryName : t('location_content_uncategorized'),
            'parts' => $parts,
        ];
    }

    $minifigs = $content['minifigs'];
    foreach ($minifigs as &$minifig) {
        $locationInfo = $resolveLocationInfo($minifig['location_id']);
        $minifig['location_label'] = $locationInfo['label'];
        $minifig['read_only'] = $locationInfo['readOnly'];
    }
    unset($minifig);

    echo json_encode([
        'categories' => $categories,
        'minifigs' => $minifigs,
        'ldraw' => $ldrawStatus,
        'readOnly' => $readOnly,
        'ownedSetId' => $ownedSetId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// The location Explorer's per-card "edit" modal AND part_modal.php's
// "Bestand bearbeiten" tab (quantity, damaged quantity, optional new
// location, optional new condition type — see updateStorageItem() in
// src/storage.php). new_location_id stays supported for the location
// Explorer's own edit card; "Bestand bearbeiten" deliberately never sends
// it (no move-to-location field there, moving stays reachable via the
// Explorer's own "Umlagern" bulk action instead).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_storage_item') {
    header('Content-Type: application/json');
    try {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $partId = (int) ($_POST['part_id'] ?? 0);
        $colorId = (int) ($_POST['color_id'] ?? 0);
        $conditionType = ($_POST['condition_type'] ?? '') === 'new' ? 'new' : 'used';
        $quantity = (int) ($_POST['quantity'] ?? -1);
        $newLocationId = isset($_POST['new_location_id']) && $_POST['new_location_id'] !== ''
            ? (int) $_POST['new_location_id'] : null;
        $damagedQuantity = isset($_POST['damaged_quantity']) && $_POST['damaged_quantity'] !== ''
            ? (int) $_POST['damaged_quantity'] : null;
        $newConditionTypeParam = $_POST['new_condition_type'] ?? '';
        $newConditionType = ($newConditionTypeParam === 'new' || $newConditionTypeParam === 'used') ? $newConditionTypeParam : null;

        if ($locationId <= 0 || $partId <= 0 || $colorId <= 0 || $quantity < 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }
        if ($damagedQuantity !== null && ($damagedQuantity < 0 || $damagedQuantity > $quantity)) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        updateStorageItem($locationId, $partId, $colorId, $conditionType, $quantity, $newLocationId, (int) $_SESSION['user_id'], $damagedQuantity, $newConditionType);
        $stats = refreshAppStatsCache($pdo);

        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Location Explorer: moves one specific minifig instance to another
// location — no quantity concept anymore, each instance is exactly one
// physical minifig (see minifig_storage_items' own doc comment,
// src/setup.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_minifig_storage_item') {
    header('Content-Type: application/json');
    try {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $newLocationId = (int) ($_POST['new_location_id'] ?? 0);

        if ($instanceId <= 0 || $newLocationId <= 0 || getMinifigStorageItemById($pdo, $instanceId) === null) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        moveMinifigStorageItemInstance($instanceId, $newLocationId);
        $stats = refreshAppStatsCache($pdo);

        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Location Explorer: removes one specific minifig instance entirely (the
// loose-minifig counterpart of removing an owned set).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_minifig_storage_item') {
    header('Content-Type: application/json');
    try {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        if ($instanceId <= 0 || getMinifigStorageItemById($pdo, $instanceId) === null) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        deleteMinifigStorageItemInstance($instanceId);
        $stats = refreshAppStatsCache($pdo);

        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// The location Explorer's multi-select "Umlagern" bar — moves several
// already-selected cards (parts and/or minifigs) to one target location in
// one request. $items is a JSON-encoded array of
// {kind:'part', locationId, partId, colorId, conditionType} or
// {kind:'minifig', instanceId}, built client-side from the same data
// attributes the single-card edit modal uses.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_move_storage_items') {
    header('Content-Type: application/json');
    try {
        $targetLocationId = (int) ($_POST['target_location_id'] ?? 0);
        $items = json_decode((string) ($_POST['items'] ?? '[]'), true);

        if ($targetLocationId <= 0 || !is_array($items) || empty($items)) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $userId = (int) $_SESSION['user_id'];
        $moved = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['kind'] ?? '') === 'minifig') {
                $instanceId = (int) ($item['instanceId'] ?? 0);
                if ($instanceId <= 0) {
                    continue;
                }
                moveMinifigStorageItemInstance($instanceId, $targetLocationId);
            } else {
                $fromLocationId = (int) ($item['locationId'] ?? 0);
                $conditionType = ($item['conditionType'] ?? '') === 'new' ? 'new' : 'used';
                $partId = (int) ($item['partId'] ?? 0);
                $colorId = (int) ($item['colorId'] ?? 0);
                if ($fromLocationId <= 0 || $partId <= 0 || $colorId <= 0) {
                    continue;
                }
                moveStorageItem($fromLocationId, $targetLocationId, $partId, $colorId, $conditionType, $userId);
            }
            $moved++;
        }
        $stats = refreshAppStatsCache($pdo);

        echo json_encode(['success' => true, 'moved' => $moved, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'part_detail') {
    header('Content-Type: application/json');
    $partId = (int) ($_GET['part_id'] ?? 0);
    $colorId = isset($_GET['color_id']) && $_GET['color_id'] !== '' ? (int) $_GET['color_id'] : null;
    $locationId = isset($_GET['location_id']) && $_GET['location_id'] !== '' ? (int) $_GET['location_id'] : null;
    $conditionTypeParam = $_GET['condition_type'] ?? '';
    $conditionType = $conditionTypeParam === 'new' ? 'new' : ($conditionTypeParam === 'used' ? 'used' : null);
    $part = getPartDetail($pdo, $partId, $colorId, $locationId, $conditionType);
    if ($part === null) {
        http_response_code(404);
        echo json_encode(['error' => t('part_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // A real catalog-item link once the id is known (editable via
    // action=update_part_external_ids, part_modal.php's "Informationen"
    // tab), otherwise a best-effort catalog search link built from
    // part_num — direct catalog links aren't reliably constructible from
    // part_num alone, especially for printed variants whose numbering
    // differs across sites.
    $part['bricklink_url'] = $part['bricklink_part_id'] !== null
        ? 'https://www.bricklink.com/v2/catalog/catalogitem.page?P=' . urlencode($part['bricklink_part_id'])
        : 'https://www.bricklink.com/v2/search.page?q=' . urlencode($part['part_num']);
    $part['brickowl_url'] = $part['brickowl_id'] !== null
        ? 'https://www.brickowl.com/catalog/' . urlencode($part['brickowl_id'])
        : 'https://www.brickowl.com/search/catalog?query=' . urlencode($part['part_num']);
    $locale = getLocale();
    $part['translated_name'] = $locale !== 'en' ? getPartTranslation($pdo, $partId, $locale) : null;
    $part['translation_locale'] = $locale;
    $colors = getColorsForPartPicker($pdo, $partId);
    echo json_encode([
        'part' => $part,
        'knownColors' => $colors['known'],
        'otherColors' => $colors['other'],
        'printParent' => getPrintParent($pdo, $partId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// The part-detail modal's manual BrickLink price refresh button
// ("Informationen" tab) — mirrors action=refresh_minifig_bricklink_price
// (AJAX overlay, returns formatted text instead of reloading), but must
// additionally play by stepBricklinkPartPriceSync()'s own throttle rules
// (src/bricklink_prices.php): unlike the set/minifig syncs (a fixed
// 600-second floor made a manual click bypassing it inconsequential), the
// part sync's 10-300s window is shared via an explicit DB mutex + a
// next-allowed-at marker specifically so cron and web-cron can't compound —
// a manual refresh that skipped updating that marker would let repeated
// clicks bypass the whole rate limit. Acquiring the lock and bumping the
// marker here mirrors that function's body exactly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_part_bricklink_price') {
    header('Content-Type: application/json');
    try {
        $refreshPartId = (int) ($_POST['part_id'] ?? 0);
        $refreshColorId = (int) ($_POST['color_id'] ?? 0);
        $partColorForRefresh = ($refreshPartId > 0 && $refreshColorId > 0)
            ? getPartColorForBricklinkRefresh($pdo, $refreshPartId, $refreshColorId)
            : null;
        if ($partColorForRefresh === null) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $locked = (int) $pdo->query("SELECT GET_LOCK('studsphere_bricklink_part_sync', 0)")->fetchColumn();
        if ($locked !== 1) {
            throw new RuntimeException(t('part_bricklink_price_refresh_busy'));
        }
        try {
            refreshBricklinkPriceForPartColor($pdo, $partColorForRefresh);
            $delay = random_int(BRICKLINK_PART_PRICE_MIN_DELAY_SECONDS, BRICKLINK_PART_PRICE_MAX_DELAY_SECONDS);
            setAppSetting('bricklink_part_sync_next_allowed_at', date('Y-m-d H:i:s', time() + $delay));
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('studsphere_bricklink_part_sync')");
        }

        $priceStmt = $pdo->prepare(
            'SELECT bricklink_price_new, bricklink_price_used, bricklink_price_currency, bricklink_price_checked_at
             FROM part_bricklink_prices WHERE part_id = ? AND color_id = ?'
        );
        $priceStmt->execute([$refreshPartId, $refreshColorId]);
        $refreshedPrice = $priceStmt->fetch() ?: [
            'bricklink_price_new' => null, 'bricklink_price_used' => null,
            'bricklink_price_currency' => null, 'bricklink_price_checked_at' => null,
        ];
        $newSummary = formatBricklinkPriceSummary(
            $refreshedPrice['bricklink_price_new'] !== null ? (float) $refreshedPrice['bricklink_price_new'] : null,
            $refreshedPrice['bricklink_price_used'] !== null ? (float) $refreshedPrice['bricklink_price_used'] : null,
            $refreshedPrice['bricklink_price_currency'], $refreshedPrice['bricklink_price_checked_at'], 'new'
        );
        $usedSummary = formatBricklinkPriceSummary(
            $refreshedPrice['bricklink_price_new'] !== null ? (float) $refreshedPrice['bricklink_price_new'] : null,
            $refreshedPrice['bricklink_price_used'] !== null ? (float) $refreshedPrice['bricklink_price_used'] : null,
            $refreshedPrice['bricklink_price_currency'], $refreshedPrice['bricklink_price_checked_at'], 'used'
        );

        echo json_encode([
            'success' => true,
            'newPriceText' => $newSummary['text'],
            'usedPriceText' => $usedSummary['text'],
            'priceTitle' => $newSummary['title'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// The part-detail modal's editable BrickLink/BrickOwl id fields
// ("Informationen" tab). If bricklink_part_id actually changes, the cached
// bricklink_item_id and any already-fetched part_bricklink_prices row are
// cleared too — otherwise a corrected id would silently keep showing a
// price fetched under the old, wrong catalog item until the next 6-month
// sync cycle.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_part_external_ids') {
    header('Content-Type: application/json');
    try {
        $externalIdsPartId = (int) ($_POST['part_id'] ?? 0);
        $newBricklinkPartId = trim((string) ($_POST['bricklink_part_id'] ?? ''));
        $newBrickowlId = trim((string) ($_POST['brickowl_id'] ?? ''));
        if ($externalIdsPartId <= 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $currentStmt = $pdo->prepare('SELECT bricklink_part_id FROM parts WHERE id = ?');
        $currentStmt->execute([$externalIdsPartId]);
        $currentBricklinkPartId = $currentStmt->fetchColumn();
        $bricklinkPartIdChanged = ((string) $currentBricklinkPartId) !== $newBricklinkPartId;

        $pdo->prepare('UPDATE parts SET bricklink_part_id = ?, brickowl_id = ? WHERE id = ?')
            ->execute([$newBricklinkPartId !== '' ? $newBricklinkPartId : null, $newBrickowlId !== '' ? $newBrickowlId : null, $externalIdsPartId]);

        if ($bricklinkPartIdChanged) {
            $pdo->prepare('UPDATE parts SET bricklink_item_id = NULL WHERE id = ?')->execute([$externalIdsPartId]);
            $pdo->prepare('DELETE FROM part_bricklink_prices WHERE part_id = ?')->execute([$externalIdsPartId]);
        }

        echo json_encode([
            'success' => true,
            'bricklinkPartId' => $newBricklinkPartId !== '' ? $newBricklinkPartId : null,
            'brickowlId' => $newBrickowlId !== '' ? $newBrickowlId : null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// renderMinifigDetailModal()'s data source (src/minifig_modal.php) — the
// minifig itself plus its constituent parts, which Rebrickable ships as its
// own "inventory" keyed by fig_num (see getMinifigInventoryId()'s doc
// comment in src/minifigs.php), so getSetPartsList() — otherwise a
// set-parts function — works unchanged here too.
if (isset($_GET['action']) && $_GET['action'] === 'minifig_detail') {
    header('Content-Type: application/json');
    $minifigId = (int) ($_GET['minifig_id'] ?? 0);
    $minifig = getMinifigById($pdo, $minifigId);
    if ($minifig === null) {
        http_response_code(404);
        echo json_encode(['error' => t('minifig_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $minifigInventoryId = getMinifigInventoryId($pdo, $minifig['fig_num']);
    $parts = $minifigInventoryId !== null ? getSetPartsList($pdo, $minifigInventoryId, false, getLocale()) : [];
    $minifig = array_merge($minifig, getMinifigSetStats($pdo, $minifigId));
    // Reuses the same bricklink_id resolution as the Wanted-List XML export
    // (getOrFetchBricklinkMinifigId(), src/owned_sets.php) — a plain
    // BrickLink search by Rebrickable fig_num (like action=part_detail does
    // for parts) doesn't reliably find minifigs, since BrickLink's own
    // minifig numbering scheme doesn't match Rebrickable's. Falls back to
    // the search page only if the id genuinely couldn't be resolved.
    // Rebrickable's own fig_num doubles as its catalog URL directly, no
    // lookup needed.
    $bricklinkMinifigId = getOrFetchBricklinkMinifigId($pdo, $minifigId, $minifig['fig_num']);
    $minifig['bricklink_url'] = $bricklinkMinifigId !== null
        ? 'https://www.bricklink.com/v2/catalog/catalogitem.page?M=' . urlencode($bricklinkMinifigId)
        : 'https://www.bricklink.com/v2/search.page?q=' . urlencode($minifig['fig_num']);
    $minifig['rebrickable_url'] = 'https://rebrickable.com/minifigs/' . urlencode($minifig['fig_num']) . '/';

    // One entry per minifig_storage_items instance (a single physical
    // minifig) the user actually owns (getMinifigStorageItemsForMinifig(),
    // src/minifigs.php) — each instance's own per-part defekt/fehlt status
    // (getMinifigStorageItemPartsWithStatus()), keyed "part_id:color_id" so
    // the modal can overlay it onto the plain nominal $parts list above
    // without a second round-trip when the user switches which instance is
    // selected. Also carries a pre-formatted BrickLink price matching that
    // instance's own condition_type (formatBricklinkPriceSummary(),
    // src/bricklink_prices.php — same helper owned_set_detail uses), so the
    // client never has to reimplement that formatting/currency logic.
    $storageInstances = [];
    foreach (getMinifigStorageItemsForMinifig($pdo, $minifigId) as $instance) {
        $partsStatus = [];
        foreach (getMinifigStorageItemPartsWithStatus($pdo, $instance['id'], $minifig['fig_num'], getLocale()) as $part) {
            $partsStatus[$part['part_id'] . ':' . $part['color_id']] = [
                'nominal' => $part['nominal_quantity'],
                'actual' => $part['actual_quantity'],
                'damaged' => $part['damaged_quantity'],
            ];
        }
        $priceSummary = formatBricklinkPriceSummary(
            $minifig['bricklink_price_new'],
            $minifig['bricklink_price_used'],
            $minifig['bricklink_price_currency'],
            $minifig['bricklink_price_checked_at'],
            $instance['condition_type']
        );
        $storageInstances[] = [
            'id' => $instance['id'],
            'locationId' => $instance['location_id'],
            'locationName' => $instance['location_name'],
            'conditionType' => $instance['condition_type'],
            'partsStatus' => $partsStatus,
            'priceText' => $priceSummary['text'],
            'priceTitle' => $priceSummary['title'],
        ];
    }

    echo json_encode(['minifig' => $minifig, 'parts' => $parts, 'storageInstances' => $storageInstances], JSON_UNESCAPED_UNICODE);
    exit;
}

// The minifig-detail modal's per-part defekt/fehlt editor (src/minifig_modal.php)
// — single-key save, mirrors save_owned_set_inventory's "just iterate
// whatever keys are posted" shape via applyMinifigStorageItemPartInventory(),
// just always exactly one key here since the modal edits one part tile at a
// time, not a combined form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_minifig_storage_item_part') {
    header('Content-Type: application/json');
    try {
        $minifigStorageItemId = (int) ($_POST['minifig_storage_item_id'] ?? 0);
        $partId = (int) ($_POST['part_id'] ?? 0);
        $colorId = (int) ($_POST['color_id'] ?? 0);
        $ownedQuantity = (int) ($_POST['quantity'] ?? 0);
        $damagedQuantity = (int) ($_POST['damaged_quantity'] ?? 0);

        $storageItem = getMinifigStorageItemById($pdo, $minifigStorageItemId);
        if ($storageItem === null || $partId <= 0 || $colorId <= 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }
        $minifigForSave = getMinifigById($pdo, $storageItem['minifig_id']);
        if ($minifigForSave === null) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $key = $partId . ':' . $colorId;
        applyMinifigStorageItemPartInventory($pdo, $minifigStorageItemId, $minifigForSave['fig_num'], [$key => $ownedQuantity], [$key => $damagedQuantity]);
        $stats = refreshAppStatsCache($pdo);
        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// The minifig-detail modal's manual BrickLink price refresh button — mirrors
// action=refresh_bricklink_price for sets, but returns a formatted
// text/title pair instead of triggering a full page reload: the minifig
// modal is an AJAX overlay, and a reload would just close it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_minifig_bricklink_price') {
    header('Content-Type: application/json');
    try {
        $refreshMinifigId = (int) ($_POST['minifig_id'] ?? 0);
        $conditionTypeForPrice = ($_POST['condition_type'] ?? '') === 'new' ? 'new' : 'used';
        $minifigForRefresh = $refreshMinifigId > 0 ? getMinifigById($pdo, $refreshMinifigId) : null;
        if ($minifigForRefresh === null) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        refreshBricklinkPriceForMinifig($pdo, $minifigForRefresh);
        $refreshedMinifig = getMinifigById($pdo, $refreshMinifigId);
        $priceSummary = formatBricklinkPriceSummary(
            $refreshedMinifig['bricklink_price_new'],
            $refreshedMinifig['bricklink_price_used'],
            $refreshedMinifig['bricklink_price_currency'],
            $refreshedMinifig['bricklink_price_checked_at'],
            $conditionTypeForPrice
        );

        echo json_encode(['success' => true, 'priceText' => $priceSummary['text'], 'priceTitle' => $priceSummary['title']], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// owned_minifig_detail (src/routes/pages.php) — mirrors
// upload_owned_set_photo/delete_owned_set_photo (src/owned_sets.php's
// counterparts) against minifig_storage_item_photos instead.
const MINIFIG_PHOTO_ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_minifig_photo') {
    header('Content-Type: application/json');
    try {
        $photoInstanceId = (int) ($_POST['minifig_storage_item_id'] ?? 0);
        if ($photoInstanceId <= 0 || getOwnedMinifigInstanceById($pdo, $photoInstanceId) === null) {
            throw new RuntimeException(t('owned_set_photo_invalid'));
        }

        $photoCaption = trim((string) ($_POST['caption'] ?? ''));
        $photoCaption = $photoCaption !== '' ? mb_substr($photoCaption, 0, 255) : null;

        if (!isset($_FILES['photo_file'])) {
            throw new RuntimeException(t('owned_set_photo_upload_failed'));
        }
        $photoFile = $_FILES['photo_file'];
        if ($photoFile['error'] === UPLOAD_ERR_INI_SIZE || $photoFile['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException(t('owned_set_photo_too_large', ['max' => (string) ini_get('upload_max_filesize')]));
        }
        if ($photoFile['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($photoFile['tmp_name'])) {
            throw new RuntimeException(t('owned_set_photo_upload_failed'));
        }

        $photoFinfo = finfo_open(FILEINFO_MIME_TYPE);
        $photoMime = $photoFinfo !== false ? finfo_file($photoFinfo, $photoFile['tmp_name']) : false;
        if ($photoFinfo !== false) {
            finfo_close($photoFinfo);
        }
        if (!in_array($photoMime, MINIFIG_PHOTO_ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException(t('owned_set_photo_invalid_type'));
        }

        $photoOriginalFilename = basename((string) $photoFile['name']);
        $photoFilename = generateOwnedMinifigPhotoFilename($photoOriginalFilename);
        $photoTargetPath = getOwnedMinifigPhotosStorageDir($photoInstanceId) . '/' . $photoFilename;
        if (!move_uploaded_file($photoFile['tmp_name'], $photoTargetPath)) {
            throw new RuntimeException(t('owned_set_photo_upload_failed'));
        }
        $photoFileSize = filesize($photoTargetPath);
        $photoRelativePath = getOwnedMinifigPhotoRelativePath($photoInstanceId, $photoFilename);

        $photoId = addOwnedMinifigPhoto($pdo, $photoInstanceId, $photoCaption, $photoOriginalFilename, $photoRelativePath, $photoFileSize !== false ? $photoFileSize : (int) $photoFile['size'], (int) $_SESSION['user_id']);

        echo json_encode([
            'success' => true,
            'photo' => ['id' => $photoId, 'url' => $photoRelativePath, 'caption' => $photoCaption],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_minifig_photo') {
    header('Content-Type: application/json');
    try {
        $deletePhotoId = (int) ($_POST['photo_id'] ?? 0);
        $deletedPhoto = $deletePhotoId > 0 ? deleteOwnedMinifigPhoto($pdo, $deletePhotoId) : null;
        if ($deletedPhoto === null) {
            throw new RuntimeException(t('owned_set_photo_invalid'));
        }
        $deletedPhotoAbsolutePath = __DIR__ . '/' . $deletedPhoto['stored_path'];
        if (is_file($deletedPhotoAbsolutePath)) {
            @unlink($deletedPhotoAbsolutePath);
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// owned_minifig_detail's edit/sell/remove forms self-submit (no explicit
// action="" attribute, plain POST to the current URL) — same pattern as
// owned_set_detail's own edit/sell/remove forms: on success each redirects
// explicitly, on failure this message is left set and execution falls
// through to routes/pages.php's own GET-style render of the same page.
$ownedMinifigDetailMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_minifig_notes') {
    $notesInstanceId = (int) ($_POST['instance_id'] ?? 0);
    try {
        $newNotes = trim((string) ($_POST['notes'] ?? ''));
        saveOwnedMinifigNotes($pdo, $notesInstanceId, $newNotes !== '' ? $newNotes : null);
        header('Location: ?page=owned_minifig_detail&id=' . $notesInstanceId);
        exit;
    } catch (Throwable $e) {
        $ownedMinifigDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sell_minifig_storage_item') {
    $sellInstanceId = (int) ($_POST['instance_id'] ?? 0);
    try {
        $sellMinifigPriceRaw = trim((string) ($_POST['price'] ?? ''));
        $sellMinifigPrice = $sellMinifigPriceRaw !== '' ? (float) str_replace(',', '.', $sellMinifigPriceRaw) : null;
        $sellMinifigDateRaw = trim((string) ($_POST['sold_at'] ?? ''));
        $sellMinifigDate = $sellMinifigDateRaw !== '' ? $sellMinifigDateRaw : null;
        $sellMinifigPlatform = trim((string) ($_POST['platform'] ?? ''));
        $sellMinifigNotes = trim((string) ($_POST['notes'] ?? ''));
        sellOwnedMinifigInstance(
            $pdo,
            $sellInstanceId,
            $sellMinifigPrice,
            $sellMinifigDate,
            $sellMinifigPlatform !== '' ? $sellMinifigPlatform : null,
            $sellMinifigNotes !== '' ? $sellMinifigNotes : null,
            (int) $_SESSION['user_id']
        );
        refreshAppStatsCache($pdo);
        header('Location: ?page=my_minifigs_all');
        exit;
    } catch (Throwable $e) {
        $ownedMinifigDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_minifig_storage_item') {
    $removeInstanceId = (int) ($_POST['instance_id'] ?? 0);
    try {
        removeOwnedMinifigInstance($pdo, $removeInstanceId);
        refreshAppStatsCache($pdo);
        header('Location: ?page=my_minifigs_all');
        exit;
    } catch (Throwable $e) {
        $ownedMinifigDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

// owned_minifig_detail's BrickLink-XML pill (renderOwnedMinifigBricklinkModal(),
// src/owned_minifigs.php) — mirrors owned_set_bricklink_parts_missing/
// owned_set_bricklink_xml_check, minus the whole-missing-minifig manual-id
// branch (never applicable here, see buildOwnedMinifigBricklinkXml()'s doc
// comment) — 'ready' is therefore always true.
if (isset($_GET['action']) && $_GET['action'] === 'owned_minifig_bricklink_parts_missing') {
    header('Content-Type: application/json');
    $missingPartsInstance = getOwnedMinifigInstanceById($pdo, (int) ($_GET['instance_id'] ?? 0));
    if ($missingPartsInstance === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'partNums' => getOwnedMinifigBricklinkPartNums($pdo, $missingPartsInstance),
        'batchSize' => REBRICKABLE_PART_BATCH_SIZE,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'owned_minifig_bricklink_xml_check') {
    header('Content-Type: application/json');
    $xmlCheckInstance = getOwnedMinifigInstanceById($pdo, (int) ($_GET['instance_id'] ?? 0));
    if ($xmlCheckInstance === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $minifigXmlExport = buildOwnedMinifigBricklinkXml($pdo, $xmlCheckInstance);
    echo json_encode(['success' => true, 'xml' => $minifigXmlExport['xml']], JSON_UNESCAPED_UNICODE);
    exit;
}

// The minifig modal's "appears in N sets" link (src/minifig_modal.php) —
// mirrors action=part_sets.
if (isset($_GET['action']) && $_GET['action'] === 'minifig_sets') {
    header('Content-Type: application/json');
    $minifigId = (int) ($_GET['minifig_id'] ?? 0);
    echo json_encode(['sets' => getMinifigSets($pdo, $minifigId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_part_translation') {
    header('Content-Type: application/json');
    try {
        $partId = (int) ($_POST['part_id'] ?? 0);
        $locale = getLocale();
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($partId <= 0 || $name === '') {
            throw new RuntimeException(t('translation_invalid_input'));
        }
        if ($locale === 'en') {
            throw new RuntimeException(t('translation_locale_is_source'));
        }
        savePartTranslation($pdo, $partId, $locale, $name, (int) $_SESSION['user_id']);
        echo json_encode(['success' => true, 'name' => $name], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_set_retired_year') {
    header('Content-Type: application/json');
    try {
        $setId = (int) ($_POST['set_id'] ?? 0);
        $yearRaw = trim((string) ($_POST['year_retired'] ?? ''));
        if ($setId <= 0) {
            throw new RuntimeException(t('set_detail_retired_year_invalid'));
        }
        if ($yearRaw === '') {
            $year = null;
        } elseif (ctype_digit($yearRaw) && (int) $yearRaw >= 1900 && (int) $yearRaw <= 2100) {
            $year = (int) $yearRaw;
        } else {
            throw new RuntimeException(t('set_detail_retired_year_invalid'));
        }
        updateSetRetiredYear($pdo, $setId, $year);
        echo json_encode(['success' => true, 'yearRetired' => $year], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_set_instruction') {
    header('Content-Type: application/json');
    try {
        $setId = (int) ($_POST['set_id'] ?? 0);
        if ($setId <= 0 || getSetById($pdo, $setId) === null) {
            throw new RuntimeException(t('set_detail_instructions_invalid_set'));
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        if (mb_strlen($label) > INSTRUCTION_MAX_LABEL_LENGTH) {
            $label = mb_substr($label, 0, INSTRUCTION_MAX_LABEL_LENGTH);
        }
        $label = $label !== '' ? $label : null;

        if (!isset($_FILES['instruction_file'])) {
            throw new RuntimeException(t('set_detail_instructions_upload_failed'));
        }
        $file = $_FILES['instruction_file'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException(t('set_detail_instructions_too_large', ['max' => (string) ini_get('upload_max_filesize')]));
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException(t('set_detail_instructions_upload_failed'));
        }

        // Extension is never trusted (the stored filename is always random
        // + ".pdf", see generateInstructionFilename()) — sniffing the real
        // MIME type here is purely to reject non-PDF uploads with a clear
        // error rather than silently storing garbage.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if ($mime !== 'application/pdf') {
            throw new RuntimeException(t('set_detail_instructions_invalid_type'));
        }

        $originalFilename = basename((string) $file['name']);
        $filename = generateInstructionFilename();
        $targetPath = getInstructionsStorageDir($setId) . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException(t('set_detail_instructions_upload_failed'));
        }
        $fileSize = filesize($targetPath);
        $relativePath = getInstructionRelativePath($setId, $filename);

        $thumbnailRelativePath = null;
        $thumbnailFilename = generateInstructionThumbnailFilename();
        $thumbnailTargetPath = getInstructionsStorageDir($setId) . '/' . $thumbnailFilename;
        if (tryRenderInstructionThumbnail($targetPath, $thumbnailTargetPath)) {
            $thumbnailRelativePath = getInstructionRelativePath($setId, $thumbnailFilename);
        }

        $instruction = addSetInstruction($pdo, $setId, $label, $originalFilename, $relativePath, $thumbnailRelativePath, $fileSize !== false ? $fileSize : (int) $file['size'], (int) $_SESSION['user_id']);

        echo json_encode([
            'success' => true,
            'instruction' => [
                'id' => $instruction['id'],
                'label' => $instruction['label'],
                'originalFilename' => $instruction['original_filename'],
                'url' => $instruction['stored_path'],
                'thumbnailUrl' => $instruction['thumbnail_path'],
                'fileSize' => formatFileSize($instruction['file_size']),
                'uploadedAt' => formatDate($instruction['uploaded_at']),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_set_instruction') {
    header('Content-Type: application/json');
    try {
        $instructionId = (int) ($_POST['instruction_id'] ?? 0);
        $instruction = $instructionId > 0 ? deleteSetInstruction($pdo, $instructionId) : null;
        if ($instruction === null) {
            throw new RuntimeException(t('set_detail_instructions_delete_failed'));
        }
        $absolutePath = __DIR__ . '/' . $instruction['stored_path'];
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        if (!empty($instruction['thumbnail_path'])) {
            $thumbnailAbsolutePath = __DIR__ . '/' . $instruction['thumbnail_path'];
            if (is_file($thumbnailAbsolutePath)) {
                @unlink($thumbnailAbsolutePath);
            }
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

const OWNED_SET_ALLOWED_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_owned_set_photo') {
    header('Content-Type: application/json');
    try {
        $ownedSetId = (int) ($_POST['owned_set_id'] ?? 0);
        if ($ownedSetId <= 0 || getOwnedSetById($pdo, $ownedSetId) === null) {
            throw new RuntimeException(t('owned_set_photo_invalid'));
        }

        $caption = trim((string) ($_POST['caption'] ?? ''));
        $caption = $caption !== '' ? mb_substr($caption, 0, 255) : null;

        if (!isset($_FILES['photo_file'])) {
            throw new RuntimeException(t('owned_set_photo_upload_failed'));
        }
        $file = $_FILES['photo_file'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException(t('owned_set_photo_too_large', ['max' => (string) ini_get('upload_max_filesize')]));
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException(t('owned_set_photo_upload_failed'));
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if (!in_array($mime, OWNED_SET_ALLOWED_PHOTO_MIME_TYPES, true)) {
            throw new RuntimeException(t('owned_set_photo_invalid_type'));
        }

        $originalFilename = basename((string) $file['name']);
        $filename = generateOwnedSetPhotoFilename($originalFilename);
        $targetPath = getOwnedSetPhotosStorageDir($ownedSetId) . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException(t('owned_set_photo_upload_failed'));
        }
        $fileSize = filesize($targetPath);
        $relativePath = getOwnedSetPhotoRelativePath($ownedSetId, $filename);

        $photoId = addOwnedSetPhoto($pdo, $ownedSetId, $caption, $originalFilename, $relativePath, $fileSize !== false ? $fileSize : (int) $file['size'], (int) $_SESSION['user_id']);

        echo json_encode([
            'success' => true,
            'photo' => ['id' => $photoId, 'url' => $relativePath, 'caption' => $caption],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_owned_set_photo') {
    header('Content-Type: application/json');
    try {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $photo = $photoId > 0 ? deleteOwnedSetPhoto($pdo, $photoId) : null;
        if ($photo === null) {
            throw new RuntimeException(t('owned_set_photo_invalid'));
        }
        $absolutePath = __DIR__ . '/' . $photo['stored_path'];
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$ownedSetDetailMessage = '';

// First step of the BrickLink XML flow (see renderOwnedSetBricklinkModal()'s
// sync-progress modal): tells the browser which part_nums still need a
// BrickLink id before it starts ticking through batches. Cheap — no API
// calls, just the same DB check applyBricklinkPartIdBatch() would do anyway.
if (isset($_GET['action']) && $_GET['action'] === 'owned_set_bricklink_parts_missing') {
    header('Content-Type: application/json');
    $missingPartsOwnedSet = getOwnedSetById($pdo, (int) ($_GET['owned_set_id'] ?? 0));
    if ($missingPartsOwnedSet === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'partNums' => getOwnedSetBricklinkPartNums($pdo, $missingPartsOwnedSet),
        'batchSize' => REBRICKABLE_PART_BATCH_SIZE,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// One tick = one Rebrickable API call for exactly the part_nums the browser
// sends (capped at REBRICKABLE_PART_BATCH_SIZE) — the browser's sync-progress
// modal drives this in a loop, pacing itself to roughly 1 request/sec (see
// that modal's own script), so this endpoint never needs to sleep() or
// budget its own time the way a single synchronous request would have to.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'owned_set_bricklink_part_sync_tick') {
    header('Content-Type: application/json');
    $tickPartNums = array_filter(array_map('trim', explode(',', (string) ($_POST['part_nums'] ?? ''))), fn ($p) => $p !== '');
    $tickPartNums = array_slice(array_values($tickPartNums), 0, REBRICKABLE_PART_BATCH_SIZE);
    if (empty($tickPartNums)) {
        echo json_encode(['success' => true, 'updated' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'updated' => applyBricklinkPartIdBatch($pdo, $tickPartNums)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Builds the BrickLink XML and hands it back as JSON (not a file download —
// the result modal in renderOwnedSetBricklinkModal() lets the user copy the
// text directly into a BrickLink Wanted List, or download it client-side
// from that same text via a Blob, so there's only one source of truth for
// the content shown/copied/downloaded). Also doubles as the "is everything
// resolvable" check before showing that modal: a whole-missing minifig with
// no resolvable BrickLink id needs the manual-entry modal first — and
// running buildOwnedSetBricklinkXml() here is what actually triggers/caches
// the moykubik.ru lookup (-> getOrFetchBricklinkMinifigId()), so anything
// resolvable is already cached by the time a retry happens.
if (isset($_GET['action']) && $_GET['action'] === 'owned_set_bricklink_xml_check') {
    header('Content-Type: application/json');
    $checkOwnedSet = getOwnedSetById($pdo, (int) ($_GET['owned_set_id'] ?? 0));
    if ($checkOwnedSet === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $checkExport = buildOwnedSetBricklinkXml($pdo, $checkOwnedSet);
    echo json_encode([
        'success' => true,
        'ready' => empty($checkExport['needsManualId']),
        'needsManualId' => $checkExport['needsManualId'],
        'xml' => $checkExport['xml'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// A plain GET link (the action bar's PDF pill, src/routes/pages.php), not a
// fetch()+JSON endpoint like the two above — the browser's native download
// handling for a Content-Disposition: attachment response is all that's
// needed here, no client-side JS. buildOwnedSetPdfReport() (src/pdf_report.php)
// does all the work; errors are reported as JSON instead of a broken PDF
// since no PDF bytes have been sent yet at that point.
if (isset($_GET['action']) && $_GET['action'] === 'owned_set_pdf_report') {
    $pdfReportOwnedSet = getOwnedSetById($pdo, (int) ($_GET['owned_set_id'] ?? 0));
    if ($pdfReportOwnedSet === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdfReportBytes = buildOwnedSetPdfReport($pdo, $pdfReportOwnedSet);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/pdf');
    // 'inline', not 'attachment' — paired with the trigger link's
    // target="_blank" (src/routes/pages.php) so the browser's own PDF
    // viewer opens the report in a new tab instead of forcing a download.
    header('Content-Disposition: inline; filename="' . $pdfReportOwnedSet['rebrickable_set_num'] . '_Bericht.pdf"');
    header('Content-Length: ' . strlen($pdfReportBytes));
    echo $pdfReportBytes;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_minifig_bricklink_id') {
    header('Content-Type: application/json');
    try {
        $manualMinifigId = (int) ($_POST['minifig_id'] ?? 0);
        $parsedBricklinkId = parseBricklinkMinifigIdInput((string) ($_POST['bricklink_id_input'] ?? ''));
        if ($manualMinifigId <= 0 || $parsedBricklinkId === null) {
            throw new RuntimeException(t('owned_set_bricklink_manual_id_invalid'));
        }
        $pdo->prepare('UPDATE minifigs SET bricklink_id = ? WHERE id = ?')->execute([$parsedBricklinkId, $manualMinifigId]);
        echo json_encode(['success' => true, 'bricklinkId' => $parsedBricklinkId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Catalog-only inventory preview for the add-to-collection wizard's review
 * steps — used before any owned_sets row exists (the wizard now defers
 * add_owned_set to its final "Speichern" click, see
 * renderAddOwnedSetWizardModal()). Replaces the old owned-set-scoped
 * action=owned_set_missing_parts, which required a row to already exist.
 */
if (isset($_GET['action']) && $_GET['action'] === 'set_inventory_preview') {
    header('Content-Type: application/json');
    $previewSetId = (int) ($_GET['set_id'] ?? 0);
    $previewSet = getSetById($pdo, $previewSetId);
    if ($previewSet === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $previewInventoryIdRaw = trim((string) ($_GET['inventory_id'] ?? ''));
    $previewInventoryId = $previewInventoryIdRaw !== '' ? (int) $previewInventoryIdRaw : getSetInventoryId($pdo, $previewSet['rebrickable_set_num']);
    if ($previewInventoryId === null) {
        echo json_encode(['success' => true, 'parts' => [], 'spares' => [], 'stickers' => [], 'minifigs' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'parts' => getSetPartsPreview($pdo, $previewInventoryId, getLocale()),
        'spares' => getSetSparePartsPreview($pdo, $previewInventoryId, getLocale()),
        'stickers' => getSetStickerPartsPreview($pdo, $previewInventoryId, getLocale()),
        'minifigs' => getSetMinifigsPreview($pdo, $previewInventoryId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @see the set_inventory_preview handler above — one minifig's constituent parts. */
if (isset($_GET['action']) && $_GET['action'] === 'minifig_parts_preview') {
    header('Content-Type: application/json');
    $previewFigNum = trim((string) ($_GET['fig_num'] ?? ''));
    $previewNominalCount = (int) ($_GET['nominal_count'] ?? 0);
    if ($previewFigNum === '' || $previewNominalCount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'parts' => getMinifigPartsPreview($pdo, $previewFigNum, $previewNominalCount, getLocale()),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Fresh stats+summary payload for the quantity-modal save handlers below —
 * lets each of them patch the sidebar summary table and the top status bar
 * in place instead of requiring a reload (see
 * renderOwnedSetQuantityModalScript()'s applyStats()/applySummary()).
 */
function buildOwnedSetLiveUpdatePayload(PDO $pdo, array $ownedSet): array
{
    $completeness = getOwnedSetCompleteness($pdo, $ownedSet);
    $summary = getOwnedSetInventorySummary($pdo, $ownedSet, getLocale());
    return [
        'stats' => computeAppStats($pdo),
        'summary' => [
            'total' => ['actual' => $completeness['actual'], 'nominal' => $completeness['nominal'], 'percent' => $completeness['percent']],
            'exclusive' => $summary['exclusive'],
            'rare' => $summary['rare'],
            'stickers' => $summary['stickers'],
            'minifigs' => $summary['minifigs'],
        ],
    ];
}

/**
 * Looks up a minifig's fig_num + nominal count within one owned instance —
 * every minifig POST/GET action needs both (getOwnedSetMinifigPartsWithStatus()
 * needs fig_num to resolve the minifig's own Rebrickable inventory, see
 * getMinifigInventoryId(), and needs the nominal count to scale that
 * inventory to however many of this minifig the set actually has — see
 * that function's doc comment), and $minifigId alone doesn't carry either.
 *
 * @return array{fig_num:string, nominal_quantity:int}|null
 */
function findOwnedSetMinifig(PDO $pdo, array $ownedSet, int $minifigId): ?array
{
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
        if ($fig['minifig_id'] === $minifigId) {
            return ['fig_num' => $fig['fig_num'], 'nominal_quantity' => (int) $fig['nominal_quantity']];
        }
    }
    return null;
}

if (isset($_GET['action']) && $_GET['action'] === 'owned_set_minifig_parts') {
    header('Content-Type: application/json');
    $minifigPartsOwnedSet = getOwnedSetById($pdo, (int) ($_GET['owned_set_id'] ?? 0));
    $minifigId = (int) ($_GET['minifig_id'] ?? 0);
    $minifigInfo = $minifigPartsOwnedSet !== null ? findOwnedSetMinifig($pdo, $minifigPartsOwnedSet, $minifigId) : null;
    if ($minifigPartsOwnedSet === null || $minifigInfo === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('owned_set_invalid_set')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'parts' => getOwnedSetMinifigPartsWithStatus($pdo, $minifigPartsOwnedSet, $minifigId, $minifigInfo['fig_num'], $minifigInfo['nominal_quantity'], getLocale()),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_owned_set_inventory') {
    header('Content-Type: application/json');
    try {
        $inventoryOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
        $inventoryOwnedSet = getOwnedSetById($pdo, $inventoryOwnedSetId);
        if ($inventoryOwnedSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        $userId = (int) $_SESSION['user_id'];
        applyOwnedSetInventory($pdo, $inventoryOwnedSet, (array) ($_POST['owned'] ?? []), (array) ($_POST['damaged'] ?? []), $userId);
        applyOwnedSetSpareInventory($pdo, $inventoryOwnedSet, (array) ($_POST['spare_owned'] ?? []), (array) ($_POST['spare_damaged'] ?? []));
        applyOwnedSetStickerInventory($pdo, $inventoryOwnedSet, (array) ($_POST['sticker_owned'] ?? []), (array) ($_POST['sticker_damaged'] ?? []), $userId);
        applyOwnedSetMinifigInventory($pdo, $inventoryOwnedSet, (array) ($_POST['minifig_owned'] ?? []), (array) ($_POST['minifig_damaged'] ?? []));
        refreshAppStatsCache($pdo);
        echo json_encode(['success' => true] + buildOwnedSetLiveUpdatePayload($pdo, $inventoryOwnedSet), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_owned_set_minifig_parts') {
    header('Content-Type: application/json');
    try {
        $minifigPartsSaveOwnedSet = getOwnedSetById($pdo, (int) ($_POST['owned_set_id'] ?? 0));
        if ($minifigPartsSaveOwnedSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        $minifigPartsSaveMinifigId = (int) ($_POST['minifig_id'] ?? 0);
        $minifigPartsSaveInfo = findOwnedSetMinifig($pdo, $minifigPartsSaveOwnedSet, $minifigPartsSaveMinifigId);
        if ($minifigPartsSaveInfo === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        applyOwnedSetMinifigPartInventory($pdo, $minifigPartsSaveOwnedSet, $minifigPartsSaveMinifigId, $minifigPartsSaveInfo['fig_num'], $minifigPartsSaveInfo['nominal_quantity'], (array) ($_POST['part_owned'] ?? []), (array) ($_POST['part_damaged'] ?? []));
        refreshAppStatsCache($pdo);
        echo json_encode(['success' => true] + buildOwnedSetLiveUpdatePayload($pdo, $minifigPartsSaveOwnedSet), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_owned_set') {
    header('Content-Type: application/json');
    try {
        $openOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
        $openOwnedSet = getOwnedSetById($pdo, $openOwnedSetId);
        if ($openOwnedSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        openOwnedSet($pdo, $openOwnedSet);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// The owned_set_detail page's manual "refresh price" button — bypasses
// stepBricklinkPriceSync()'s 30-day/throttle gate since this is one
// deliberate user click, not the opportunistic background sync. Also bumps
// the same last-run marker that gate reads, so the automatic sync doesn't
// immediately re-fetch the same set right after.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_bricklink_price') {
    header('Content-Type: application/json');
    try {
        $bricklinkRefreshSetId = (int) ($_POST['set_id'] ?? 0);
        $bricklinkRefreshSet = getSetById($pdo, $bricklinkRefreshSetId);
        if ($bricklinkRefreshSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        setAppSetting('bricklink_sync_last_run', date('Y-m-d H:i:s'));
        refreshBricklinkPriceForSet($pdo, $bricklinkRefreshSet);
        $refreshedSet = getSetById($pdo, $bricklinkRefreshSetId);
        echo json_encode([
            'success' => true,
            'priceNew' => $refreshedSet['bricklink_price_new'],
            'priceUsed' => $refreshedSet['bricklink_price_used'],
            'currency' => $refreshedSet['bricklink_price_currency'],
            'checkedAt' => formatDate($refreshedSet['bricklink_price_checked_at'], true),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// "Bauanleitungen" (src/instruction_manuals.php) — the add-manual mini-form's
// set search. No existing lightweight set-search AJAX endpoint to reuse (the
// owned-set wizard always already has its set_id from set_detail.php's own
// "add to collection" button, never searches by name/number itself), so this
// is a thin wrapper around the same searchSets() the full catalog browser
// uses, capped to a small page for a popover.
if (isset($_GET['action']) && $_GET['action'] === 'search_sets_for_instructions') {
    header('Content-Type: application/json');
    $instructionsSetQuery = trim((string) ($_GET['q'] ?? ''));
    $instructionsSetResults = $instructionsSetQuery !== ''
        ? searchSets($pdo, $instructionsSetQuery, [], 1, 20)['items']
        : [];
    echo json_encode(['items' => $instructionsSetResults], JSON_UNESCAPED_UNICODE);
    exit;
}

// Adds one physical instruction-manual instance. Best-effort, synchronous
// BrickLink price refresh for both catalog entries (the Set itself and its
// separate Instructions catalog item) if either has never been checked —
// mirrors add_minifig_stock's own unconditional refreshBricklinkPriceForMinifig()
// call, per the same prior "adding something behaves like a manual refresh
// right away" direction. Never blocks the add on failure.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_instruction_manual') {
    header('Content-Type: application/json');
    try {
        $newManualLocationId = (int) ($_POST['location_id'] ?? 0);
        $newManualSetId = (int) ($_POST['set_id'] ?? 0);
        $newManualConditionGrade = (string) ($_POST['condition_grade'] ?? '');
        $newManualNotesRaw = trim((string) ($_POST['notes'] ?? ''));
        $newManualNotes = $newManualNotesRaw !== '' ? $newManualNotesRaw : null;

        $newManualLocation = $newManualLocationId > 0 ? getStorageLocation($newManualLocationId) : null;
        if ($newManualLocation === null || !isLocationInInstructionsSubtree($pdo, $newManualLocationId)) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }
        $newManualSet = $newManualSetId > 0 ? getSetById($pdo, $newManualSetId) : null;
        if ($newManualSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        if (!in_array($newManualConditionGrade, INSTRUCTION_MANUAL_CONDITION_GRADES, true)) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $newManualId = addInstructionManual($newManualLocationId, $newManualSetId, $newManualConditionGrade, $newManualNotes);

        try {
            if ($newManualSet['bricklink_item_id'] === null && $newManualSet['bricklink_price_checked_at'] === null) {
                refreshBricklinkPriceForSet($pdo, $newManualSet);
            }
            if ($newManualSet['bricklink_instructions_item_id'] === null && $newManualSet['bricklink_instructions_price_checked_at'] === null) {
                refreshBricklinkPriceForSetInstructions($pdo, $newManualSet);
            }
        } catch (Throwable $e) {
            // Never blocks the add.
        }

        echo json_encode([
            'success' => true,
            'id' => $newManualId,
            'message' => t('instruction_manual_add_success'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('instruction_manual_add_failed', ['message' => $e->getMessage()])], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_instruction_manual') {
    header('Content-Type: application/json');
    try {
        $updateManualId = (int) ($_POST['instance_id'] ?? 0);
        $updateManualConditionGrade = (string) ($_POST['condition_grade'] ?? '');
        $updateManualNotesRaw = trim((string) ($_POST['notes'] ?? ''));
        $updateManualNotes = $updateManualNotesRaw !== '' ? $updateManualNotesRaw : null;

        if ($updateManualId <= 0 || getInstructionManualById($pdo, $updateManualId) === null) {
            throw new RuntimeException(t('instruction_manual_not_found'));
        }
        if (!in_array($updateManualConditionGrade, INSTRUCTION_MANUAL_CONDITION_GRADES, true)) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        updateInstructionManual($updateManualId, $updateManualConditionGrade, $updateManualNotes);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Rejects a target outside the instructions subtree — a manual filed there
// would become invisible, since action=location_content's response branches
// entirely on isLocationInInstructionsSubtree().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_instruction_manual') {
    header('Content-Type: application/json');
    try {
        $moveManualId = (int) ($_POST['instance_id'] ?? 0);
        $moveManualNewLocationId = (int) ($_POST['new_location_id'] ?? 0);

        if ($moveManualId <= 0 || getInstructionManualById($pdo, $moveManualId) === null) {
            throw new RuntimeException(t('instruction_manual_not_found'));
        }
        if ($moveManualNewLocationId <= 0 || !isLocationInInstructionsSubtree($pdo, $moveManualNewLocationId)) {
            throw new RuntimeException(t('instruction_manual_move_outside_subtree'));
        }

        moveInstructionManual($moveManualId, $moveManualNewLocationId);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_instruction_manual') {
    header('Content-Type: application/json');
    try {
        $deleteManualId = (int) ($_POST['instance_id'] ?? 0);
        if ($deleteManualId <= 0 || getInstructionManualById($pdo, $deleteManualId) === null) {
            throw new RuntimeException(t('instruction_manual_not_found'));
        }

        deleteInstructionManual($deleteManualId);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Full detail-modal payload: the manual itself, a link to the catalog set,
// the loose-parts breakdown for that set, and both BrickLink price blocks
// (Set + Instructions catalog entries, priced completely independently).
if (isset($_GET['action']) && $_GET['action'] === 'instruction_manual_detail') {
    header('Content-Type: application/json');
    $manualDetailId = (int) ($_GET['instance_id'] ?? 0);
    $manualDetail = $manualDetailId > 0 ? getInstructionManualById($pdo, $manualDetailId) : null;
    if ($manualDetail === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('instruction_manual_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $manualDetailSet = getSetById($pdo, $manualDetail['set_id']);
    $manualDetailInventoryId = getSetInventoryId($pdo, $manualDetail['set_num']);
    $manualDetailParts = $manualDetailInventoryId !== null
        ? getInstructionManualPartsBreakdown($pdo, $manualDetailInventoryId, getLocale())
        : [];
    $manualDetailSummary = $manualDetailInventoryId !== null
        ? getSetInventorySummary($pdo, $manualDetailInventoryId, getLocale())
        : null;

    echo json_encode([
        'success' => true,
        'manual' => $manualDetail,
        'set' => $manualDetailSet,
        'parts' => $manualDetailParts,
        'summary' => $manualDetailSummary,
        'bricklinkSetUrl' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . urlencode($manualDetail['set_num']),
        'bricklinkInstructionsUrl' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?I=' . urlencode($manualDetail['set_num']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// The detail modal's manual "refresh price" button for the Instructions
// catalog entry specifically — bypasses stepBricklinkInstructionsPriceSync()'s
// throttle the same way refresh_bricklink_price already does for the Set
// entry, and bumps the same last-run marker so the automatic sync doesn't
// immediately re-fetch it again right after.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_bricklink_instructions_price') {
    header('Content-Type: application/json');
    try {
        $instructionsRefreshSetId = (int) ($_POST['set_id'] ?? 0);
        $instructionsRefreshSet = getSetById($pdo, $instructionsRefreshSetId);
        if ($instructionsRefreshSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        setAppSetting('bricklink_instructions_sync_last_run', date('Y-m-d H:i:s'));
        refreshBricklinkPriceForSetInstructions($pdo, $instructionsRefreshSet);
        $refreshedInstructionsSet = getSetById($pdo, $instructionsRefreshSetId);
        echo json_encode([
            'success' => true,
            'priceNew' => $refreshedInstructionsSet['bricklink_instructions_price_new'],
            'priceUsed' => $refreshedInstructionsSet['bricklink_instructions_price_used'],
            'currency' => $refreshedInstructionsSet['bricklink_instructions_price_currency'],
            'checkedAt' => formatDate($refreshedInstructionsSet['bricklink_instructions_price_checked_at'], true),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_owned_set_damaged_missing_settings') {
    header('Content-Type: application/json');
    try {
        $filterOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
        if (getOwnedSetById($pdo, $filterOwnedSetId) === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        $showSpares = ($_POST['show_spares'] ?? '') === '1';
        $showStickers = ($_POST['show_stickers'] ?? '') === '1';
        $stmt = $pdo->prepare('UPDATE owned_sets SET damaged_missing_show_spares = ?, damaged_missing_show_stickers = ? WHERE id = ?');
        $stmt->execute([$showSpares ? 1 : 0, $showStickers ? 1 : 0, $filterOwnedSetId]);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_owned_set_details') {
    $editOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
    try {
        $markAsUsed = ($_POST['owned-set-edit-condition'] ?? '') === 'used';
        $editNotes = trim((string) ($_POST['notes'] ?? ''));
        $editInstructionsNotes = trim((string) ($_POST['instructions_notes'] ?? ''));
        $editBoxNotes = trim((string) ($_POST['box_notes'] ?? ''));
        $editBoxCompleteNotes = trim((string) ($_POST['box_complete_notes'] ?? ''));
        $editStickersNotes = trim((string) ($_POST['stickers_notes'] ?? ''));
        updateOwnedSetDetails(
            $pdo,
            $editOwnedSetId,
            $markAsUsed,
            ($_POST['has_instructions'] ?? '') === '1',
            ($_POST['has_box'] ?? '') === '1',
            ($_POST['has_box_complete'] ?? '') === '1',
            ($_POST['stickers_applied'] ?? '') === '1',
            $editNotes !== '' ? $editNotes : null,
            $editInstructionsNotes !== '' ? $editInstructionsNotes : null,
            $editBoxNotes !== '' ? $editBoxNotes : null,
            $editBoxCompleteNotes !== '' ? $editBoxCompleteNotes : null,
            $editStickersNotes !== '' ? $editStickersNotes : null
        );
        refreshAppStatsCache($pdo);
        header('Location: ?page=owned_set_detail&id=' . $editOwnedSetId);
        exit;
    } catch (Throwable $e) {
        $ownedSetDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_owned_set') {
    $moveOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
    try {
        $moveOwnedSet = getOwnedSetById($pdo, $moveOwnedSetId);
        if ($moveOwnedSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        $moveParentLocationRaw = trim((string) ($_POST['parent_location_id'] ?? ''));
        if ($moveParentLocationRaw === '') {
            throw new RuntimeException(t('owned_set_wizard_location_required'));
        }
        moveStorageLocation($moveOwnedSet['location_id'], (int) $moveParentLocationRaw);
        header('Location: ?page=owned_set_detail&id=' . $moveOwnedSetId);
        exit;
    } catch (Throwable $e) {
        $ownedSetDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sell_owned_set') {
    $sellOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
    try {
        $sellPriceRaw = trim((string) ($_POST['price'] ?? ''));
        $sellPrice = $sellPriceRaw !== '' ? (float) str_replace(',', '.', $sellPriceRaw) : null;
        $sellDateRaw = trim((string) ($_POST['sold_at'] ?? ''));
        $sellDate = $sellDateRaw !== '' ? $sellDateRaw : null;
        $sellPlatform = trim((string) ($_POST['platform'] ?? ''));
        $sellNotes = trim((string) ($_POST['notes'] ?? ''));
        $sellSet = getOwnedSetById($pdo, $sellOwnedSetId);
        if ($sellSet === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        $sellSetId = $sellSet['set_id'];
        sellOwnedSet(
            $pdo,
            $sellOwnedSetId,
            $sellPrice,
            $sellDate,
            $sellPlatform !== '' ? $sellPlatform : null,
            $sellNotes !== '' ? $sellNotes : null,
            (int) $_SESSION['user_id']
        );
        refreshAppStatsCache($pdo);
        $soldSet = getSetById($pdo, $sellSetId);
        $redirectUrl = resolveOwnedSetRemovalRedirect(getOwnedSetThemeTree($pdo), $soldSet['theme_id'] ?? null);
        header('Location: ' . $redirectUrl);
        exit;
    } catch (Throwable $e) {
        $ownedSetDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_owned_set') {
    $removeOwnedSetId = (int) ($_POST['owned_set_id'] ?? 0);
    $removeOwnedSetSetId = (int) ($_POST['set_id'] ?? 0);
    try {
        removeOwnedSet($pdo, $removeOwnedSetId);
        refreshAppStatsCache($pdo);
        $removedSet = getSetById($pdo, $removeOwnedSetSetId);
        $redirectUrl = resolveOwnedSetRemovalRedirect(getOwnedSetThemeTree($pdo), $removedSet['theme_id'] ?? null);
        header('Location: ' . $redirectUrl);
        exit;
    } catch (Throwable $e) {
        $ownedSetDetailMessage = t('owned_set_save_failed', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_part_color_image') {
    header('Content-Type: application/json');
    try {
        $partId = (int) ($_POST['part_id'] ?? 0);
        $rebrickableColorId = (int) ($_POST['color_id'] ?? 0);
        if ($partId <= 0 || $rebrickableColorId <= 0) {
            throw new RuntimeException(t('part_color_image_invalid_input'));
        }
        $partNumStmt = $pdo->prepare('SELECT part_num FROM parts WHERE id = ?');
        $partNumStmt->execute([$partId]);
        $partNum = $partNumStmt->fetchColumn();
        if ($partNum === false) {
            throw new RuntimeException(t('part_not_found'));
        }
        $imagePath = fetchPartColorImage($pdo, $partId, (string) $partNum, $rebrickableColorId);
        echo json_encode(['success' => true, 'imagePath' => $imagePath], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'part_sets') {
    header('Content-Type: application/json');
    $partId = (int) ($_GET['part_id'] ?? 0);
    echo json_encode(['sets' => getPartSets($pdo, $partId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'part_stock') {
    header('Content-Type: application/json');
    $partId = (int) ($_GET['part_id'] ?? 0);
    echo json_encode(['stock' => getPartStock($partId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    header('Content-Type: application/json');
    try {
        $partId = (int) ($_POST['part_id'] ?? 0);
        $colorId = (int) ($_POST['color_id'] ?? 0);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $conditionType = ($_POST['condition_type'] ?? '') === 'new' ? 'new' : 'used';
        $quantity = (int) ($_POST['quantity'] ?? 0);

        if ($partId <= 0 || $colorId <= 0 || $locationId <= 0 || $quantity <= 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $resultingQuantity = addStorageStock($locationId, $partId, $colorId, $conditionType, $quantity, (int) $_SESSION['user_id']);
        $stats = refreshAppStatsCache($pdo);

        echo json_encode([
            'success' => true,
            'resultingQuantity' => $resultingQuantity,
            'locationPath' => getStorageLocationPath($locationId),
            'message' => t('add_stock_success', ['quantity' => (string) $quantity, 'total' => (string) $resultingQuantity]),
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('add_stock_failed', ['message' => $e->getMessage()])], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Minifig counterpart to add_stock — the minifig detail modal's "add as
// loose minifig" form (src/minifig_modal.php). $quantity means "how many
// new, individually-tracked instances to create right now" (a convenience
// for the common all-identical case — see addMinifigStock()'s doc comment,
// src/storage.php); it is not a stored field. If a part_owned/part_damaged
// breakdown was submitted, the same described status is applied to every
// newly created instance (one form describes one state; differing states
// across several copies mean separate add actions, corrected afterward via
// the modal's per-tile editor if needed).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_minifig_stock') {
    header('Content-Type: application/json');
    try {
        $minifigId = (int) ($_POST['minifig_id'] ?? 0);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $conditionType = ($_POST['condition_type'] ?? '') === 'new' ? 'new' : 'used';
        $quantity = (int) ($_POST['quantity'] ?? 0);

        if ($minifigId <= 0 || $locationId <= 0 || $quantity <= 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $newInstanceIds = addMinifigStock($locationId, $minifigId, $conditionType, $quantity);

        $minifigForParts = getMinifigById($pdo, $minifigId);
        if ($minifigForParts !== null && (!empty($_POST['part_owned']) || !empty($_POST['part_damaged']))) {
            foreach ($newInstanceIds as $newInstanceId) {
                applyMinifigStorageItemPartInventory(
                    $pdo,
                    $newInstanceId,
                    $minifigForParts['fig_num'],
                    (array) ($_POST['part_owned'] ?? []),
                    (array) ($_POST['part_damaged'] ?? [])
                );
            }
        }

        // Best-effort, synchronous — mirrors how add_owned_set fetches a
        // set's BrickLink price immediately rather than waiting for the
        // opportunistic sync (per explicit prior user direction: adding
        // something should behave like a manual refresh right away). Once
        // per minifig catalog entry, not per instance — the price lives on
        // minifigs, not on each individual storage row.
        if ($minifigForParts !== null) {
            try {
                refreshBricklinkPriceForMinifig($pdo, $minifigForParts);
            } catch (Throwable $e) {
                // Never blocks the add.
            }
        }

        $stats = refreshAppStatsCache($pdo);

        echo json_encode([
            'success' => true,
            'createdCount' => count($newInstanceIds),
            'message' => t('minifig_stock_added', ['quantity' => (string) $quantity]),
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('add_stock_failed', ['message' => $e->getMessage()])], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// "Bauen" modal (renderBuildMinifigModal(), src/build.php) — mirrors
// action=minifig_detail's bare-object response shape (no success/true
// wrapper on the happy path, the JS side just checks for a minifig_id key).
if (isset($_GET['action']) && $_GET['action'] === 'build_minifig_detail') {
    header('Content-Type: application/json');
    $buildDetailMinifigId = (int) ($_GET['minifig_id'] ?? 0);
    $buildDetail = $buildDetailMinifigId > 0 ? getBuildableMinifigDetail($pdo, $buildDetailMinifigId, getLocale()) : null;
    if ($buildDetail === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('minifig_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $buildDetail['price_text'] = $buildDetail['bricklink_price_used'] !== null
        ? formatNumber($buildDetail['bricklink_price_used'], 2) . ' ' . bricklinkCurrencySymbol($buildDetail['bricklink_price_currency'])
        : null;
    echo json_encode($buildDetail, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build_minifig') {
    header('Content-Type: application/json');
    try {
        $buildMinifigId = (int) ($_POST['minifig_id'] ?? 0);
        $buildQuantity = (int) ($_POST['quantity'] ?? 0);
        $buildConditionType = ($_POST['condition_type'] ?? '') === 'new' ? 'new' : 'used';
        $buildDestinationLocationId = (int) ($_POST['destination_location_id'] ?? 0);

        if ($buildMinifigId <= 0 || $buildQuantity <= 0 || $buildDestinationLocationId <= 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }

        $buildResult = buildMinifigFromStock($pdo, $buildMinifigId, $buildQuantity, $buildConditionType, $buildDestinationLocationId);
        $stats = refreshAppStatsCache($pdo);

        echo json_encode([
            'success' => true,
            'createdCount' => count($buildResult['createdInstanceIds']),
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Dashboard widget management — add/remove are plain form POSTs (no
// action="" on the <form>, so they submit back to the bare dashboard URL and
// the request just falls through to the normal dashboard render further
// down with the updated widget list); save_dashboard_layout is the one
// fetch()-driven action, from the drag-and-drop script in
// renderDashboardWidgets() (src/dashboard.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_dashboard_widget') {
    $newWidgetType = (string) ($_POST['widget_type'] ?? '');
    $newWidgetZone = (string) ($_POST['zone'] ?? '');
    try {
        addDashboardWidget($pdo, (int) $_SESSION['user_id'], $newWidgetType, $newWidgetZone);
    } catch (Throwable $e) {
        // Only reachable via a tampered request (the <select> only ever
        // offers valid, not-yet-placed types) — nothing sensible to show the
        // user, just don't add anything.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_dashboard_widget') {
    removeDashboardWidget($pdo, (int) $_SESSION['user_id'], (int) ($_POST['widget_id'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_dashboard_layout') {
    header('Content-Type: application/json');
    saveDashboardLayout($pdo, (int) $_SESSION['user_id'], (array) ($_POST['layout'] ?? []));
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// The owned sets behind one clicked bar of the collection-stats chart (see
// renderDashboardWidgetCollectionStats() in src/dashboard.php) — 'group'
// selects which of computeOwnedSetsByYear()/computeOwnedSetsByTheme()'s bars
// 'value' refers to.
if (isset($_GET['action']) && $_GET['action'] === 'dashboard_sets_by_group') {
    header('Content-Type: application/json');
    $group = (string) ($_GET['group'] ?? '');
    $value = (int) ($_GET['value'] ?? 0);

    if ($group === 'year') {
        echo json_encode(['sets' => getOwnedSetsByYear($pdo, $value)], JSON_UNESCAPED_UNICODE);
    } elseif ($group === 'theme') {
        echo json_encode(['sets' => getOwnedSetsByTheme($pdo, $value)], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(['sets' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

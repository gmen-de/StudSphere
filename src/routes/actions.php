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

    try {
        if ($ownedSetSetId <= 0 || getSetById($pdo, $ownedSetSetId) === null) {
            throw new RuntimeException(t('owned_set_invalid_set'));
        }
        if ($parentLocationId === null) {
            throw new RuntimeException(t('owned_set_wizard_location_required'));
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
            $stickersNotes
        );
        refreshAppStatsCache($pdo);
        echo json_encode(['success' => true, 'ownedSetId' => $newOwnedSetId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename_location') {
    $locationId = (int) ($_POST['location_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $locationMessage = t('location_name_required');
    } else {
        try {
            renameStorageLocation($locationId, $name);
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

// The location Explorer's right pane (src/routes/pages.php's ?page=locations,
// see renderLocationExplorer() there) — everything stored anywhere under the
// clicked location, recursively, grouped for display.
if (isset($_GET['action']) && $_GET['action'] === 'location_content') {
    header('Content-Type: application/json');
    $locationId = (int) ($_GET['location_id'] ?? 0);
    if (getStorageLocation($locationId) === null) {
        http_response_code(404);
        echo json_encode(['error' => t('location_detail_not_found')], JSON_UNESCAPED_UNICODE);
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

    $categories = [];
    foreach ($content['partsByCategory'] as $categoryName => $parts) {
        foreach ($parts as &$part) {
            $part['part_name'] = $translations[$part['part_id']] ?? $part['part_name'];
            // No getPartThumbnails() fallback here on purpose: that helper
            // finds *any* image for the part number across the whole
            // catalog regardless of color (see its own doc comment), which
            // for a color-specific grid like this one means showing a
            // plausible-looking but potentially entirely wrong-colored
            // image — confirmed on real data (part 2420 in Dark Gray, no
            // cached color image yet, showing up with a blue one instead).
            // No image at all (client falls back to a generic brick icon)
            // is honest about not knowing; a wrong color isn't.
            $part['thumbnail'] = $part['ldraw_thumbnail'];
            unset($part['ldraw_thumbnail'], $part['rebrickable_color_id']);
        }
        unset($part);
        $categories[] = [
            'name' => $categoryName !== '' ? $categoryName : t('location_content_uncategorized'),
            'parts' => $parts,
        ];
    }

    echo json_encode([
        'categories' => $categories,
        'minifigs' => $content['minifigs'],
        'ldraw' => $ldrawStatus,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// The location Explorer's per-card "edit" modal (quantity + optional new
// location, see updateStorageItem() in src/storage.php) — reachable by
// clicking any part card in ?page=locations.
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

        if ($locationId <= 0 || $partId <= 0 || $colorId <= 0 || $quantity < 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }
        if ($newLocationId !== null && $newLocationId !== $locationId && locationHasNonOwnedSetChildren($newLocationId)) {
            throw new RuntimeException(t('add_stock_location_not_leaf'));
        }

        updateStorageItem($locationId, $partId, $colorId, $conditionType, $quantity, $newLocationId, (int) $_SESSION['user_id']);
        $stats = refreshAppStatsCache($pdo);

        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_minifig_storage_item') {
    header('Content-Type: application/json');
    try {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $minifigId = (int) ($_POST['minifig_id'] ?? 0);
        $conditionType = ($_POST['condition_type'] ?? '') === 'new' ? 'new' : 'used';
        $quantity = (int) ($_POST['quantity'] ?? -1);
        $newLocationId = isset($_POST['new_location_id']) && $_POST['new_location_id'] !== ''
            ? (int) $_POST['new_location_id'] : null;

        if ($locationId <= 0 || $minifigId <= 0 || $quantity < 0) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }
        if ($newLocationId !== null && $newLocationId !== $locationId && locationHasNonOwnedSetChildren($newLocationId)) {
            throw new RuntimeException(t('add_stock_location_not_leaf'));
        }

        updateMinifigStorageItem($locationId, $minifigId, $conditionType, $quantity, $newLocationId);
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
// {kind:'minifig', locationId, minifigId, conditionType}, built client-side
// from the same data attributes the single-card edit modal uses.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_move_storage_items') {
    header('Content-Type: application/json');
    try {
        $targetLocationId = (int) ($_POST['target_location_id'] ?? 0);
        $items = json_decode((string) ($_POST['items'] ?? '[]'), true);

        if ($targetLocationId <= 0 || !is_array($items) || empty($items)) {
            throw new RuntimeException(t('add_stock_invalid_input'));
        }
        if (locationHasNonOwnedSetChildren($targetLocationId)) {
            throw new RuntimeException(t('add_stock_location_not_leaf'));
        }

        $userId = (int) $_SESSION['user_id'];
        $moved = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fromLocationId = (int) ($item['locationId'] ?? 0);
            if ($fromLocationId <= 0) {
                continue;
            }
            $conditionType = ($item['conditionType'] ?? '') === 'new' ? 'new' : 'used';
            if (($item['kind'] ?? '') === 'minifig') {
                $minifigId = (int) ($item['minifigId'] ?? 0);
                if ($minifigId <= 0) {
                    continue;
                }
                moveMinifigStorageItem($fromLocationId, $targetLocationId, $minifigId, $conditionType);
            } else {
                $partId = (int) ($item['partId'] ?? 0);
                $colorId = (int) ($item['colorId'] ?? 0);
                if ($partId <= 0 || $colorId <= 0) {
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
    $part = getPartDetail($pdo, $partId);
    if ($part === null) {
        http_response_code(404);
        echo json_encode(['error' => t('part_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Best-effort catalog search links built from part_num — we don't import
    // BrickLink/BrickOwl's own external-id mappings (that needs their APIs),
    // so a direct catalog-item link isn't reliably constructible, especially
    // for printed variants whose numbering differs across sites.
    $part['bricklink_url'] = 'https://www.bricklink.com/v2/search.page?q=' . urlencode($part['part_num']);
    $part['brickowl_url'] = 'https://www.brickowl.com/search/catalog?query=' . urlencode($part['part_num']);
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

        $instruction = addSetInstruction($pdo, $setId, $label, $originalFilename, $relativePath, $fileSize !== false ? $fileSize : (int) $file['size'], (int) $_SESSION['user_id']);

        echo json_encode([
            'success' => true,
            'instruction' => [
                'id' => $instruction['id'],
                'label' => $instruction['label'],
                'originalFilename' => $instruction['original_filename'],
                'url' => $instruction['stored_path'],
                'fileSize' => formatFileSize($instruction['file_size']),
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

if (isset($_GET['action']) && $_GET['action'] === 'part_stock_summary') {
    header('Content-Type: application/json');
    $partId = (int) ($_GET['part_id'] ?? 0);
    echo json_encode(['summary' => getPartStockSummary($partId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'part_stock_detail') {
    header('Content-Type: application/json');
    $partId = (int) ($_GET['part_id'] ?? 0);
    $colorId = isset($_GET['color_id']) && $_GET['color_id'] !== '' ? (int) $_GET['color_id'] : null;
    echo json_encode(['detail' => getPartStockDetail($partId, $colorId)], JSON_UNESCAPED_UNICODE);
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
        // A room (or any non-leaf level) alone isn't a valid storage spot —
        // the cascading picker is only meant to bottom out at an actual leaf.
        // Owned-set instance children don't count here (see
        // locationHasNonOwnedSetChildren()'s doc comment) — a location that
        // holds a boxed set can still take loose parts alongside it.
        if (locationHasNonOwnedSetChildren($locationId)) {
            throw new RuntimeException(t('add_stock_location_not_leaf'));
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

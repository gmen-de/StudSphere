<?php

declare(strict_types=1);

/**
 * The Pickliste PWA's (/pick/) own AJAX endpoints — separate from the main
 * app's src/routes/actions.php by design (see /pick/index.php's doc
 * comment): this is a self-contained mini-app with its own mobile-first
 * screens, sharing only the domain logic in src/pick_lists.php, not any
 * markup/routing conventions with the desktop app. Required by
 * /pick/index.php after $pdo/session/auth are already set up; every handler
 * below assumes $pdo and $_SESSION['user_id'] exist.
 *
 * Every branch below is an isolated `if (...) { ...; exit; }` block, same
 * shape as src/routes/actions.php, echoing JSON and exiting immediately so
 * later blocks never accidentally run.
 */

$pickUserId = (int) $_SESSION['user_id'];

function pickJsonError(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pick_list') {
    header('Content-Type: application/json');
    $sourceType = ($_POST['source_type'] ?? '') === 'minifig' ? 'minifig' : 'set';
    $catalogId = (int) ($_POST['catalog_id'] ?? 0);
    $description = trim((string) ($_POST['description'] ?? ''));
    $ownedSetIdRaw = trim((string) ($_POST['owned_set_id'] ?? ''));
    $ownedSetId = $ownedSetIdRaw !== '' ? (int) $ownedSetIdRaw : null;

    if ($catalogId <= 0 || $description === '') {
        pickJsonError(t('pick_error_invalid_request'));
    }

    try {
        $missingOnly = [];
        if ($ownedSetId !== null) {
            $ownedSet = getOwnedSetById($pdo, $ownedSetId);
            if ($ownedSet === null) {
                pickJsonError(t('pick_error_invalid_request'), 404);
            }
            $sourceType = 'set';
            $catalogId = (int) $ownedSet['set_id'];
            $missingParts = [];
            foreach (getOwnedSetPartsWithStatus($pdo, $ownedSet) as $item) {
                $wanted = max(0, $item['nominal_quantity'] - $item['actual_quantity']) + $item['damaged_quantity'];
                if ($wanted > 0) {
                    $missingParts[$item['part_id'] . ':' . $item['color_id']] = $wanted;
                }
            }
            $missingMinifigs = [];
            foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
                $wanted = max(0, $fig['nominal_quantity'] - $fig['actual_quantity']) + $fig['damaged_quantity'];
                if ($wanted > 0) {
                    $missingMinifigs[$fig['minifig_id']] = $wanted;
                }
            }
            $missingOnly = ['parts' => $missingParts, 'minifigs' => $missingMinifigs];
        }

        $pickListId = createPickList($pdo, $pickUserId, $sourceType, $catalogId, $description, $ownedSetId, $missingOnly);
        if ($pickListId === null) {
            pickJsonError(t('pick_error_no_inventory'));
        }
        echo json_encode(['success' => true, 'pickListId' => $pickListId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pick_item') {
    header('Content-Type: application/json');
    $pickListId = (int) ($_POST['pick_list_id'] ?? 0);
    $pickListItemId = (int) ($_POST['pick_list_item_id'] ?? 0);
    $sourceLocationRaw = trim((string) ($_POST['source_location_id'] ?? ''));
    $sourceLocationId = $sourceLocationRaw !== '' ? (int) $sourceLocationRaw : null;
    $quantity = (int) ($_POST['quantity'] ?? 0);

    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null || (int) $pickList['user_id'] !== $pickUserId) {
        pickJsonError(t('pick_error_not_found'), 404);
    }

    try {
        $result = pickItem($pdo, $pickListId, $pickListItemId, $sourceLocationId, $quantity, $pickUserId);
        echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'pick_steps') {
    header('Content-Type: application/json');
    $pickListItemId = (int) ($_GET['pick_list_item_id'] ?? 0);
    $item = getPickListItem($pdo, $pickListItemId);
    if ($item === null) {
        pickJsonError(t('pick_error_not_found'), 404);
    }
    $pickList = getPickList($pdo, (int) $item['pick_list_id']);
    if ($pickList === null || (int) $pickList['user_id'] !== $pickUserId) {
        pickJsonError(t('pick_error_not_found'), 404);
    }
    echo json_encode(['success' => true] + getPickStepsForItem($pdo, $item), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'put_away_suggestions') {
    header('Content-Type: application/json');
    $pickListId = (int) ($_GET['pick_list_id'] ?? 0);
    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null || (int) $pickList['user_id'] !== $pickUserId) {
        pickJsonError(t('pick_error_not_found'), 404);
    }
    echo json_encode(['success' => true, 'suggestions' => getPutAwaySuggestions($pdo, $pickListId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'put_away_item') {
    header('Content-Type: application/json');
    $pickListId = (int) ($_POST['pick_list_id'] ?? 0);
    $partId = (int) ($_POST['part_id'] ?? 0);
    $colorId = (int) ($_POST['color_id'] ?? 0);
    $conditionType = ($_POST['condition_type'] ?? 'used') === 'new' ? 'new' : 'used';
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $destinationLocationId = (int) ($_POST['destination_location_id'] ?? 0);

    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null || (int) $pickList['user_id'] !== $pickUserId) {
        pickJsonError(t('pick_error_not_found'), 404);
    }

    try {
        putAwayItem($pdo, $pickListId, $partId, $colorId, $conditionType, $quantity, $destinationLocationId, $pickUserId);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'flag_stocktake') {
    header('Content-Type: application/json');
    $pickListId = (int) ($_POST['pick_list_id'] ?? 0);
    $pickListItemIdRaw = trim((string) ($_POST['pick_list_item_id'] ?? ''));
    $pickListItemId = $pickListItemIdRaw !== '' ? (int) $pickListItemIdRaw : null;
    $locationId = (int) ($_POST['location_id'] ?? 0);
    $partId = (int) ($_POST['part_id'] ?? 0);
    $colorIdRaw = trim((string) ($_POST['color_id'] ?? ''));
    $colorId = $colorIdRaw !== '' ? (int) $colorIdRaw : null;
    $note = trim((string) ($_POST['note'] ?? ''));
    $note = $note !== '' ? $note : null;

    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null || (int) $pickList['user_id'] !== $pickUserId || $locationId <= 0 || $partId <= 0) {
        pickJsonError(t('pick_error_invalid_request'));
    }

    $stmt = $pdo->prepare(
        'INSERT INTO pick_list_stocktake_flags (pick_list_id, pick_list_item_id, location_id, part_id, color_id, note, flagged_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$pickListId, $pickListItemId, $locationId, $partId, $colorId, $note, $pickUserId]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'pick_ldraw_angle_progress') {
    header('Content-Type: application/json');
    $partId = (int) ($_GET['part_id'] ?? 0);
    $rebrickableColorId = (int) ($_GET['rebrickable_color_id'] ?? 0);
    if ($partId <= 0) {
        pickJsonError(t('pick_error_invalid_request'));
    }
    if (!ldrawContextualRenderingReady()) {
        echo json_encode(['success' => true, 'status' => 'unavailable', 'images' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true] + getLdrawFourAngleProgress($pdo, $partId, $rebrickableColorId), JSON_UNESCAPED_UNICODE);
    exit;
}

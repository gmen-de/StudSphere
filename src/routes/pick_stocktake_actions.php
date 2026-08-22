<?php

declare(strict_types=1);

/**
 * The /pick/ app's own Inventur endpoints — deliberately a duplicate of the
 * matching branches in src/routes/actions.php (start_stocktake_for_owned_set,
 * start_stocktake_for_location, stocktake_next_item, stocktake_item_confirm,
 * stocktake_complete, stocktake_cancel), same reasoning already established
 * for ldraw_pick_list_render_tick in src/routes/pick_actions.php: this entry
 * point can't require_once the main app's routing file, only its domain
 * logic (src/stocktakes.php, already required by /pick/index.php via
 * src/stocktake_pages.php). Required by /pick/index.php after
 * $pdo/session/auth are already set up; every handler below assumes $pdo and
 * $_SESSION['user_id'] exist. Uses the same pickJsonError() helper
 * src/routes/pick_actions.php already defines.
 *
 */

$stocktakeUserId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_stocktake_for_owned_set') {
    header('Content-Type: application/json');
    try {
        $stocktakeOwnedSet = getOwnedSetById($pdo, (int) ($_POST['owned_set_id'] ?? 0));
        if ($stocktakeOwnedSet === null) {
            pickJsonError(t('owned_set_invalid_set'), 404);
        }
        $stocktakeId = startStocktakeForOwnedSet($pdo, $stocktakeUserId, $stocktakeOwnedSet);
        echo json_encode(['success' => true, 'stocktakeId' => $stocktakeId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_stocktake_for_location') {
    header('Content-Type: application/json');
    try {
        $stocktakeLocationId = (int) ($_POST['location_id'] ?? 0);
        $stocktakeRecursive = ($_POST['recursive'] ?? '0') === '1';
        $stocktakeId = startStocktakeForLocation($pdo, $stocktakeUserId, $stocktakeLocationId, $stocktakeRecursive);
        echo json_encode(['success' => true, 'stocktakeId' => $stocktakeId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stocktake_item_confirm') {
    header('Content-Type: application/json');
    try {
        $stocktakeId = (int) ($_POST['stocktake_id'] ?? 0);
        $stocktake = getStocktake($pdo, $stocktakeId);
        if ($stocktake === null || (int) $stocktake['user_id'] !== $stocktakeUserId) {
            pickJsonError(t('stocktake_not_active_error'), 404);
        }
        $stocktakeItemId = (int) ($_POST['stocktake_item_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? -1);
        if ($quantity < 0) {
            pickJsonError(t('stocktake_item_not_found_error'));
        }
        $result = confirmStocktakeItem($pdo, $stocktakeId, $stocktakeItemId, $quantity, $stocktakeUserId);
        echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stocktake_complete') {
    header('Content-Type: application/json');
    try {
        $stocktakeId = (int) ($_POST['stocktake_id'] ?? 0);
        $stocktake = getStocktake($pdo, $stocktakeId);
        if ($stocktake === null || (int) $stocktake['user_id'] !== $stocktakeUserId) {
            pickJsonError(t('stocktake_not_active_error'), 404);
        }
        completeStocktake($pdo, $stocktakeId);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stocktake_cancel') {
    header('Content-Type: application/json');
    try {
        $stocktakeId = (int) ($_POST['stocktake_id'] ?? 0);
        $stocktake = getStocktake($pdo, $stocktakeId);
        if ($stocktake === null || (int) $stocktake['user_id'] !== $stocktakeUserId) {
            pickJsonError(t('stocktake_not_active_error'), 404);
        }
        cancelStocktake($pdo, $stocktakeId, $stocktakeUserId);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pickJsonError($e->getMessage());
    }
    exit;
}

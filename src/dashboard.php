<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/owned_sets.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/icons.php';

/**
 * Canonical widget types the dashboard knows how to place/render — the
 * single place to add a new one (a labelKey here, a case in
 * renderDashboardWidgetContent() below). Deliberately no per-widget config
 * yet (e.g. "how many rows"); add that if/when a widget actually needs it.
 */
function getDashboardWidgetDefinitions(): array
{
    return [
        'collection_stats' => ['labelKey' => 'dashboard_widget_collection_stats'],
        'recent_sets' => ['labelKey' => 'dashboard_widget_recent_sets'],
        'incomplete_sets' => ['labelKey' => 'dashboard_widget_incomplete_sets'],
        'last_sync' => ['labelKey' => 'dashboard_widget_last_sync'],
        'recent_activity' => ['labelKey' => 'dashboard_widget_recent_activity'],
    ];
}

/**
 * Seeded once per user, the first time their dashboard is ever loaded (see
 * getUserDashboardWidgets()) — the dashboard starts otherwise empty, just
 * the collection-stats timeline on top; the user adds anything else
 * themselves via edit mode.
 */
const DASHBOARD_DEFAULT_LAYOUT = [
    ['widget_type' => 'collection_stats', 'zone' => 'top', 'position' => 0],
];

/**
 * @return array<int, array{id:int, widget_type:string, zone:string, position:int}>
 */
function getUserDashboardWidgets(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, widget_type, zone, position FROM dashboard_widgets WHERE user_id = ? ORDER BY zone, position');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        seedDefaultDashboardWidgets($pdo, $userId);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    }

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['position'] = (int) $row['position'];
    }
    unset($row);

    return $rows;
}

function seedDefaultDashboardWidgets(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('INSERT INTO dashboard_widgets (user_id, widget_type, zone, position) VALUES (?, ?, ?, ?)');
    foreach (DASHBOARD_DEFAULT_LAYOUT as $widget) {
        $stmt->execute([$userId, $widget['widget_type'], $widget['zone'], $widget['position']]);
    }
}

/**
 * @param array<int, array{id:int, widget_type:string, zone:string, position:int}> $widgets
 * @return array{top: array, left: array, right: array}
 */
function groupDashboardWidgetsByZone(array $widgets): array
{
    $zones = ['top' => [], 'left' => [], 'right' => []];
    foreach ($widgets as $widget) {
        $zones[$widget['zone']][] = $widget;
    }
    return $zones;
}

/**
 * Only ever inserts a widget type a user doesn't already have placed
 * somewhere — the "add widget" dropdown per zone only ever offers types not
 * already on the dashboard, but this re-checks server-side too since that's
 * the actual guarantee, not just the UI hiding the option.
 */
function addDashboardWidget(PDO $pdo, int $userId, string $widgetType, string $zone): void
{
    if (!array_key_exists($widgetType, getDashboardWidgetDefinitions()) || !in_array($zone, ['top', 'left', 'right'], true)) {
        throw new InvalidArgumentException('Unbekannter Widget-Typ oder Zone.');
    }

    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM dashboard_widgets WHERE user_id = ? AND widget_type = ?');
    $existsStmt->execute([$userId, $widgetType]);
    if ((int) $existsStmt->fetchColumn() > 0) {
        return;
    }

    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM dashboard_widgets WHERE user_id = ? AND zone = ?');
    $posStmt->execute([$userId, $zone]);
    $nextPosition = (int) $posStmt->fetchColumn();

    $pdo->prepare('INSERT INTO dashboard_widgets (user_id, widget_type, zone, position) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $widgetType, $zone, $nextPosition]);
}

function removeDashboardWidget(PDO $pdo, int $userId, int $widgetId): void
{
    $pdo->prepare('DELETE FROM dashboard_widgets WHERE id = ? AND user_id = ?')->execute([$widgetId, $userId]);
}

/**
 * Bulk-persists a full drag-and-drop reorder in one go — the browser sends
 * the complete post-drop DOM order for all three zones (see the dashboard
 * page's own script), not an incremental move, so this just re-numbers
 * position within each zone from that order. Scoped to $userId in the
 * UPDATE's WHERE clause, not just trusted from the payload, so one user's
 * layout POST can never touch another user's widget rows.
 *
 * @param array<string, array<int, int>> $layout zone => ordered widget ids
 */
function saveDashboardLayout(PDO $pdo, int $userId, array $layout): void
{
    $updateStmt = $pdo->prepare('UPDATE dashboard_widgets SET zone = ?, position = ? WHERE id = ? AND user_id = ?');
    foreach (['top', 'left', 'right'] as $zone) {
        $ids = array_map('intval', $layout[$zone] ?? []);
        foreach ($ids as $position => $id) {
            $updateStmt->execute([$zone, $position, $id, $userId]);
        }
    }
}

function renderDashboardWidgetContent(PDO $pdo, string $widgetType): string
{
    switch ($widgetType) {
        case 'collection_stats':
            return renderDashboardWidgetCollectionStats($pdo);
        case 'recent_sets':
            return renderDashboardWidgetRecentSets($pdo);
        case 'incomplete_sets':
            return renderDashboardWidgetIncompleteSets($pdo);
        case 'last_sync':
            return renderDashboardWidgetLastSync();
        case 'recent_activity':
            return renderDashboardWidgetRecentActivity($pdo);
        default:
            return '';
    }
}

/**
 * Every year from the oldest to the newest set actually in the collection,
 * including zero-count years in between (a real gap is information too, not
 * something to silently skip) — @return array<int, int> year => count of
 * owned-set instances released that year.
 */
function computeOwnedSetsByYear(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT s.year, COUNT(*) AS count
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE s.year IS NOT NULL
         GROUP BY s.year'
    );
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['year']] = (int) $row['count'];
    }
    if (empty($counts)) {
        return [];
    }

    $byYear = [];
    for ($year = min(array_keys($counts)); $year <= max(array_keys($counts)); $year++) {
        $byYear[$year] = $counts[$year] ?? 0;
    }
    return $byYear;
}

/**
 * One row per theme actually used by an owned set — deliberately flat, one
 * bar per leaf theme (not rolled up through parent_theme_id; see
 * getOwnedSetThemeTree() in src/sets.php for the full hierarchy view used
 * elsewhere), mirroring computeOwnedSetsByYear()'s one-bar-per-year shape.
 * Sorted by count descending (ties alphabetical) since, unlike years, themes
 * have no natural order to sweep across.
 *
 * s.theme = CAST(th.theme_id AS CHAR), not the other way around, to keep
 * using idx_sets_theme — see getSetThemeTree()'s doc comment in src/sets.php
 * for why casting the indexed VARCHAR column itself would defeat it.
 *
 * @return array<int, array{theme_id:int, name:string, count:int}>
 */
function computeOwnedSetsByTheme(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT th.theme_id, th.name, COUNT(*) AS count
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         INNER JOIN themes th ON s.theme = CAST(th.theme_id AS CHAR)
         GROUP BY th.theme_id, th.name
         ORDER BY count DESC, th.name ASC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['theme_id'] = (int) $row['theme_id'];
        $row['count'] = (int) $row['count'];
    }
    unset($row);
    return $rows;
}

/**
 * The owned sets behind one bar of the collection-stats chart — fetched on
 * demand when a bar is clicked (action=dashboard_sets_by_group in
 * src/routes/actions.php), not preloaded, since most bars are never clicked
 * in a given visit.
 *
 * @return array<int, array{id:int, rebrickable_set_num:string, name:string, thumbnail:?string}>
 */
function getOwnedSetsByYear(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE s.year = ?
         ORDER BY s.name'
    );
    $stmt->execute([$year]);
    return $stmt->fetchAll();
}

/** @return array<int, array{id:int, rebrickable_set_num:string, name:string, thumbnail:?string}> */
function getOwnedSetsByTheme(PDO $pdo, int $themeId): array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE s.theme = ?
         ORDER BY s.name'
    );
    $stmt->execute([(string) $themeId]);
    return $stmt->fetchAll();
}

/**
 * Two interchangeable bar charts (year / theme) sharing one toggle — not the
 * six status-bar numbers anymore, those are already always visible in the
 * status bar on every page. Both charts are rendered up front (cheap at
 * personal-collection scale) and switched client-side, so toggling needs no
 * round trip. Clicking a bar with sets behind it opens #dashboard-sets-modal
 * (see renderDashboardWidgets()) with that bar's sets.
 */
function renderDashboardWidgetCollectionStats(PDO $pdo): string
{
    $byYear = computeOwnedSetsByYear($pdo);
    $byTheme = computeOwnedSetsByTheme($pdo);
    if (empty($byYear) && empty($byTheme)) {
        return '<p class="hint">' . htmlspecialchars(t('dashboard_widget_recent_sets_empty')) . '</p>';
    }

    $html = '<div class="dashboard-stats-toggle">';
    $html .= '<button type="button" class="dashboard-stats-toggle-btn active" data-chart="year">' . htmlspecialchars(t('dashboard_stats_toggle_year')) . '</button>';
    $html .= '<button type="button" class="dashboard-stats-toggle-btn" data-chart="theme">' . htmlspecialchars(t('dashboard_stats_toggle_theme')) . '</button>';
    $html .= '</div>';

    $html .= '<div class="dashboard-chart-scroll" data-chart="year">' . renderDashboardChartColumns($byYear, 'year') . '</div>';
    $html .= '<div class="dashboard-chart-scroll" data-chart="theme" hidden>' . renderDashboardChartColumns($byTheme, 'theme') . '</div>';

    return $html;
}

/**
 * Standing bars in columns, side by side — but built as 3 parallel rows
 * (counts / bars / labels) sharing one flex container per row, not as N
 * independent per-column flex stacks. That's what actually fixes the
 * original bug: the bar row is a fixed height and bottom-aligned, so every
 * bar sits on the same baseline regardless of anything below it; the label
 * row is a separate shared row, top-aligned, sized to whatever its tallest
 * label needs — a long theme name just wraps and grows that row taller for
 * *every* column at once, it can no longer push its own bar out of line with
 * its neighbors the way an independent per-column stack did (see the removed
 * renderDashboardChartRows()' doc comment for that story, and the one before
 * it — rotated labels below independently-bottom-aligned columns — for how
 * this bug first showed up).
 *
 * @param array $rows computeOwnedSetsByYear()'s year=>count map when
 *   $group==='year', otherwise computeOwnedSetsByTheme()'s row list
 */
function renderDashboardChartColumns(array $rows, string $group): string
{
    if (empty($rows)) {
        return '<p class="hint">' . htmlspecialchars(t('dashboard_widget_recent_sets_empty')) . '</p>';
    }

    $bars = $group === 'year'
        ? array_map(
            fn (int $year, int $count): array => ['value' => $year, 'label' => (string) $year, 'count' => $count],
            array_keys($rows),
            array_values($rows)
        )
        : array_map(
            fn (array $row): array => ['value' => $row['theme_id'], 'label' => $row['name'], 'count' => $row['count']],
            $rows
        );

    $maxCount = max(array_column($bars, 'count'));

    $countsRow = '<div class="dashboard-vbar-row dashboard-vbar-row-counts">';
    $barsRow = '<div class="dashboard-vbar-row dashboard-vbar-row-bars">';
    $labelsRow = '<div class="dashboard-vbar-row dashboard-vbar-row-labels">';

    foreach ($bars as $bar) {
        $clickable = $bar['count'] > 0;
        $heightPercent = $maxCount > 0 ? (int) round(($bar['count'] / $maxCount) * 100) : 0;
        $clickAttrs = $clickable
            ? ' role="button" tabindex="0"'
                . ' data-group="' . htmlspecialchars($group) . '"'
                . ' data-value="' . (int) $bar['value'] . '"'
                . ' data-label="' . htmlspecialchars($bar['label']) . '"'
            : '';
        $titleAttr = ' title="' . htmlspecialchars($bar['label'] . ': ' . $bar['count']) . '"';

        $countsRow .= '<span class="dashboard-vbar-count">' . ($bar['count'] > 0 ? formatNumber($bar['count']) : '') . '</span>';

        $barsRow .= '<div class="dashboard-vbar-cell' . ($clickable ? ' dashboard-vbar-cell-clickable' : '') . '"' . $clickAttrs . $titleAttr . '>';
        $barsRow .= '<div class="dashboard-vbar-track"><div class="dashboard-vbar-bar" style="height:' . $heightPercent . '%"></div></div>';
        $barsRow .= '</div>';

        $labelsRow .= '<span class="dashboard-vbar-label' . ($clickable ? ' dashboard-vbar-cell-clickable' : '') . '"' . $clickAttrs . $titleAttr . '>' . htmlspecialchars($bar['label']) . '</span>';
    }

    $countsRow .= '</div>';
    $barsRow .= '</div>';
    $labelsRow .= '</div>';

    return '<div class="dashboard-vbar-chart">' . $countsRow . $barsRow . $labelsRow . '</div>';
}

function renderDashboardWidgetSetList(array $sets, string $emptyKey, ?callable $badge = null): string
{
    if (empty($sets)) {
        return '<p class="hint">' . htmlspecialchars(t($emptyKey)) . '</p>';
    }
    $html = '<ul class="dashboard-set-list">';
    foreach ($sets as $set) {
        $html .= '<li><a href="?page=owned_set_detail&id=' . (int) $set['id'] . '">';
        if ($set['thumbnail'] !== null) {
            $html .= '<img src="' . htmlspecialchars($set['thumbnail']) . '" alt="" class="dashboard-set-thumb">';
        }
        $html .= '<span class="dashboard-set-name">' . htmlspecialchars($set['name']) . '</span>';
        $html .= '<small>' . ($badge !== null ? $badge($set) : htmlspecialchars($set['rebrickable_set_num'])) . '</small>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

/** The 5 most recently added owned-set instances, shared across the whole install (same scope as "Meine Sets"). */
function renderDashboardWidgetRecentSets(PDO $pdo): string
{
    $sets = array_slice(getAllOwnedSets($pdo), 0, 5);
    return renderDashboardWidgetSetList($sets, 'dashboard_widget_recent_sets_empty');
}

/**
 * Owned sets below 100% complete, least-complete first — computes
 * getOwnedSetCompleteness() for every owned set on each render (no cache),
 * fine at personal-collection scale; would need caching if this ever needs
 * to scale to thousands of owned sets.
 */
function renderDashboardWidgetIncompleteSets(PDO $pdo): string
{
    $incomplete = [];
    foreach (getAllOwnedSets($pdo) as $set) {
        $completeness = getOwnedSetCompleteness($pdo, $set);
        if ($completeness['percent'] < 100.0) {
            $set['percent'] = $completeness['percent'];
            $incomplete[] = $set;
        }
    }
    usort($incomplete, fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);
    $incomplete = array_slice($incomplete, 0, 5);

    return renderDashboardWidgetSetList(
        $incomplete,
        'dashboard_widget_incomplete_sets_empty',
        fn (array $set): string => formatNumber($set['percent'], 1) . '%'
    );
}

function renderDashboardWidgetLastSync(): string
{
    $lastSync = getAppSetting('last_update_all');
    $html = '<p>' . htmlspecialchars($lastSync !== null ? formatDate($lastSync, true) : t('dashboard_widget_last_sync_never')) . '</p>';
    $html .= '<a href="?page=settings">' . htmlspecialchars(t('dashboard_widget_last_sync_action')) . '</a>';
    return $html;
}

const DASHBOARD_ACTIVITY_LIMIT = 8;

/**
 * Merges the two places this app already writes a "who did what, when" audit
 * trail — storage_movements (every addStorageStock()/setStorageItemQuantity()
 * call, i.e. loose-part stock in/out/corrections) and owned_sets.added_by
 * (a set joining the collection) — into one recency-ordered feed. Neither
 * table had a reader before this widget; storage_movements in particular has
 * been written on every stock change since it was introduced but never
 * actually shown anywhere.
 *
 * Each source is queried for its own $limit most recent rows independently
 * (the two tables don't share a comparable shape, so a single UNIONed query
 * isn't a good fit), then merged in PHP and trimmed — fine at
 * personal-collection scale, same reasoning as this file's other widgets.
 *
 * quantity_change's sign (not storage_movements.movement_type) decides
 * in-vs-out wording: 'correction' rows can move quantity either direction,
 * so the type column alone doesn't say which happened.
 *
 * @return array<int, array{type:string, created_at:string, user:?string}>
 */
function getRecentActivity(PDO $pdo, int $limit = DASHBOARD_ACTIVITY_LIMIT): array
{
    $stockStmt = $pdo->query(
        'SELECT sm.created_at, sm.quantity_change,
                u.username, u.full_name,
                p.part_num, p.name AS part_name,
                c.name AS color_name,
                sl.name AS location_name
         FROM storage_movements sm
         LEFT JOIN users u ON u.id = sm.user_id
         LEFT JOIN parts p ON p.id = sm.part_id
         LEFT JOIN colors c ON c.id = sm.color_id
         LEFT JOIN storage_locations sl ON sl.id = sm.location_id
         ORDER BY sm.created_at DESC
         LIMIT ' . (int) $limit
    );
    $activity = array_map(
        fn (array $row): array => [
            'type' => 'stock',
            'created_at' => $row['created_at'],
            'user' => $row['full_name'] ?: $row['username'],
            'quantity_change' => (int) $row['quantity_change'],
            'part_name' => $row['part_name'],
            'part_num' => $row['part_num'],
            'color_name' => $row['color_name'],
            'location_name' => $row['location_name'],
        ],
        $stockStmt->fetchAll()
    );

    $setStmt = $pdo->query(
        'SELECT os.created_at, u.username, u.full_name, s.name AS set_name, s.rebrickable_set_num
         FROM owned_sets os
         LEFT JOIN users u ON u.id = os.added_by
         INNER JOIN sets s ON s.id = os.set_id
         ORDER BY os.created_at DESC
         LIMIT ' . (int) $limit
    );
    $activity = array_merge($activity, array_map(
        fn (array $row): array => [
            'type' => 'set_added',
            'created_at' => $row['created_at'],
            'user' => $row['full_name'] ?: $row['username'],
            'set_name' => $row['set_name'],
            'set_num' => $row['rebrickable_set_num'],
        ],
        $setStmt->fetchAll()
    ));

    usort($activity, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

    return array_slice($activity, 0, $limit);
}

function renderDashboardWidgetRecentActivity(PDO $pdo): string
{
    $activity = getRecentActivity($pdo);
    if (empty($activity)) {
        return '<p class="hint">' . htmlspecialchars(t('dashboard_widget_recent_activity_empty')) . '</p>';
    }

    $html = '<ul class="dashboard-activity-list">';
    foreach ($activity as $entry) {
        $user = $entry['user'] ?? t('dashboard_activity_unknown_user');

        if ($entry['type'] === 'set_added') {
            $dotClass = 'dashboard-activity-dot-set';
            $text = t('dashboard_activity_set_added', [
                'user' => $user,
                'set' => $entry['set_name'] . ' (' . $entry['set_num'] . ')',
            ]);
        } else {
            $part = $entry['part_name'] . ' (' . $entry['part_num'] . ')' . ($entry['color_name'] !== null ? ' · ' . $entry['color_name'] : '');
            $location = $entry['location_name'] ?? t('dashboard_activity_unknown_location');

            if ($entry['quantity_change'] > 0) {
                $dotClass = 'dashboard-activity-dot-in';
                $text = t('dashboard_activity_stock_in', [
                    'user' => $user,
                    'quantity' => (string) $entry['quantity_change'],
                    'part' => $part,
                    'location' => $location,
                ]);
            } elseif ($entry['quantity_change'] < 0) {
                $dotClass = 'dashboard-activity-dot-out';
                $text = t('dashboard_activity_stock_out', [
                    'user' => $user,
                    'quantity' => (string) abs($entry['quantity_change']),
                    'part' => $part,
                    'location' => $location,
                ]);
            } else {
                $dotClass = 'dashboard-activity-dot-out';
                $text = t('dashboard_activity_stock_correction', [
                    'user' => $user,
                    'part' => $part,
                    'location' => $location,
                ]);
            }
        }

        $html .= '<li class="dashboard-activity-item">';
        $html .= '<span class="dashboard-activity-dot ' . $dotClass . '" aria-hidden="true"></span>';
        $html .= '<span class="dashboard-activity-text">' . htmlspecialchars($text) . '</span>';
        $html .= '<span class="dashboard-activity-time">' . htmlspecialchars(formatDate($entry['created_at'], true)) . '</span>';
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function renderDashboardWidgetCard(PDO $pdo, array $widget): string
{
    $definitions = getDashboardWidgetDefinitions();
    $label = isset($definitions[$widget['widget_type']]) ? t($definitions[$widget['widget_type']]['labelKey']) : $widget['widget_type'];

    $html = '<div class="dashboard-widget" draggable="false" data-widget-id="' . (int) $widget['id'] . '">';
    $html .= '<div class="dashboard-widget-header"><span class="dashboard-widget-title">' . htmlspecialchars($label) . '</span>';
    $html .= '<form method="post" class="dashboard-widget-remove-form">';
    $html .= '<input type="hidden" name="action" value="remove_dashboard_widget">';
    $html .= '<input type="hidden" name="widget_id" value="' . (int) $widget['id'] . '">';
    $html .= '<button type="submit" class="dashboard-widget-remove" title="' . htmlspecialchars(t('dashboard_widget_remove_label')) . '" aria-label="' . htmlspecialchars(t('dashboard_widget_remove_label')) . '">' . getActionIcon('delete') . '</button>';
    $html .= '</form></div>';
    $html .= '<div class="dashboard-widget-body">' . renderDashboardWidgetContent($pdo, $widget['widget_type']) . '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * @param array<int, array{id:int, widget_type:string, zone:string, position:int}> $widgets
 * @param array<int, string> $placedTypes every widget_type this user already has, anywhere
 */
function renderDashboardZone(PDO $pdo, string $zone, array $widgets, array $placedTypes, string $axis): string
{
    $html = '<div class="dashboard-zone dashboard-zone-' . htmlspecialchars($zone) . '" data-zone="' . htmlspecialchars($zone) . '" data-axis="' . htmlspecialchars($axis) . '">';
    foreach ($widgets as $widget) {
        $html .= renderDashboardWidgetCard($pdo, $widget);
    }

    // Inside the zone itself (not a sibling after it) — same width/column as
    // the widgets above it, so which zone a given "+" belongs to is never
    // ambiguous, even when a zone is otherwise empty.
    $available = array_diff_key(getDashboardWidgetDefinitions(), array_flip($placedTypes));
    if (!empty($available)) {
        $html .= '<div class="dashboard-widget-add">';
        $html .= '<button type="button" class="dashboard-widget-add-toggle" title="' . htmlspecialchars(t('dashboard_widget_add_label')) . '" aria-label="' . htmlspecialchars(t('dashboard_widget_add_label')) . '" aria-expanded="false">' . getActionIcon('add') . '</button>';
        $html .= '<div class="dashboard-widget-add-menu" hidden>';
        foreach ($available as $type => $def) {
            $html .= '<form method="post" class="dashboard-widget-add-form">';
            $html .= '<input type="hidden" name="action" value="add_dashboard_widget">';
            $html .= '<input type="hidden" name="zone" value="' . htmlspecialchars($zone) . '">';
            $html .= '<input type="hidden" name="widget_type" value="' . htmlspecialchars($type) . '">';
            $html .= '<button type="submit" class="dashboard-widget-add-menu-item">' . htmlspecialchars(t($def['labelKey'])) . '</button>';
            $html .= '</form>';
        }
        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * The dashboard's 3 zones (one horizontal strip on top, two vertical columns
 * below) plus the vanilla-JS HTML5 drag-and-drop that lets widgets be
 * reordered within or moved between all three. On every drop, the script
 * sends the complete post-drop DOM order for all zones to
 * action=save_dashboard_layout (see saveDashboardLayout()) — simpler and
 * more robust than tracking incremental moves server-side.
 *
 * View mode (the default on every load — this never persists, unlike the
 * layout itself) hides the remove buttons, the "add a widget" controls, and
 * collapses any empty zone to nothing, so a dashboard with little or nothing
 * placed still looks intentional rather than full of empty boxes and
 * controls. The "Bearbeiten" button toggles a class on the grid that reveals
 * all of that (CSS, see .dashboard-edit-mode in style.css) and is also what
 * gates whether widgets are actually draggable — dragging is a deliberate
 * edit-mode action, not something that should happen by accident while just
 * viewing the dashboard.
 */
function renderDashboardWidgets(PDO $pdo, int $userId): string
{
    $widgets = getUserDashboardWidgets($pdo, $userId);
    $zones = groupDashboardWidgetsByZone($widgets);
    $placedTypes = array_column($widgets, 'widget_type');

    $html = '<button type="button" id="dashboard-edit-toggle" class="dashboard-edit-toggle">' . getActionIcon('edit') . '<span>' . htmlspecialchars(t('dashboard_edit_button')) . '</span></button>';
    $html .= '<div class="dashboard-grid" id="dashboard-grid">';
    $html .= renderDashboardZone($pdo, 'top', $zones['top'], $placedTypes, 'x');
    $html .= '<div class="dashboard-columns">';
    $html .= '<div class="dashboard-column">' . renderDashboardZone($pdo, 'left', $zones['left'], $placedTypes, 'y') . '</div>';
    $html .= '<div class="dashboard-column">' . renderDashboardZone($pdo, 'right', $zones['right'], $placedTypes, 'y') . '</div>';
    $html .= '</div>';
    $html .= '</div>';

    // Page-level, not per-widget — collection_stats can only ever be placed
    // once (addDashboardWidget() rejects a duplicate widget_type), but this
    // lives here rather than inside renderDashboardWidgetCollectionStats()
    // since it's shared UI chrome (a modal), the same pattern every other
    // modal in this app (e.g. part-detail-modal) follows.
    $html .= '<div class="modal-overlay" id="dashboard-sets-modal" style="display:none;">';
    $html .= '<div class="modal-box"><button type="button" class="modal-close" id="dashboard-sets-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2 id="dashboard-sets-modal-title"></h2>';
    $html .= '<div id="dashboard-sets-modal-content"></div>';
    $html .= '</div></div>';

    $editLabelsJson = json_encode([
        'edit' => t('dashboard_edit_button'),
        'done' => t('dashboard_edit_done_button'),
        'setsModalHeading' => t('dashboard_sets_modal_heading'),
        'setsModalEmpty' => t('dashboard_sets_modal_empty'),
        'errorRetry' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $editLabelsJson;
  var grid = document.getElementById('dashboard-grid');
  var toggle = document.getElementById('dashboard-edit-toggle');
  var zones = document.querySelectorAll('.dashboard-zone');
  var dragged = null;
  var editMode = false;

  function widgetsIn(zone) {
    return Array.prototype.filter.call(zone.children, function(el) {
      return el.classList.contains('dashboard-widget');
    });
  }

  function setEditMode(on) {
    editMode = on;
    grid.classList.toggle('dashboard-edit-mode', on);
    toggle.querySelector('span').textContent = on ? texts.done : texts.edit;
    document.querySelectorAll('.dashboard-widget').forEach(function(widget) {
      widget.draggable = on;
    });
  }

  if (toggle && grid) {
    toggle.addEventListener('click', function() {
      setEditMode(!editMode);
    });
  }

  function closeAllAddMenus(except) {
    document.querySelectorAll('.dashboard-widget-add-menu').forEach(function(menu) {
      if (menu !== except) {
        menu.hidden = true;
        menu.previousElementSibling.setAttribute('aria-expanded', 'false');
      }
    });
  }

  document.querySelectorAll('.dashboard-widget-add-toggle').forEach(function(addToggle) {
    var menu = addToggle.nextElementSibling;
    addToggle.addEventListener('click', function(e) {
      e.stopPropagation();
      var willOpen = menu.hidden;
      closeAllAddMenus(willOpen ? menu : null);
      menu.hidden = !willOpen;
      addToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  document.addEventListener('click', function() {
    closeAllAddMenus(null);
  });

  zones.forEach(function(zone) {
    zone.addEventListener('dragover', function(e) {
      e.preventDefault();
      if (!dragged) {
        return;
      }
      var axis = zone.dataset.axis;
      var siblings = widgetsIn(zone).filter(function(el) { return el !== dragged; });
      var after = null;
      for (var i = 0; i < siblings.length; i++) {
        var rect = siblings[i].getBoundingClientRect();
        var mid = axis === 'x' ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
        var pos = axis === 'x' ? e.clientX : e.clientY;
        if (pos < mid) {
          after = siblings[i];
          break;
        }
      }
      if (after) {
        zone.insertBefore(dragged, after);
      } else {
        // Never past the "+ add" control, if this zone has one — it must
        // always stay the last element so it keeps reading as "add to the
        // end of this zone" rather than jumping mid-list.
        var addControl = zone.querySelector('.dashboard-widget-add');
        zone.insertBefore(dragged, addControl || null);
      }
    });
    zone.addEventListener('drop', function(e) {
      e.preventDefault();
      saveLayout();
    });
  });

  document.querySelectorAll('.dashboard-widget').forEach(function(widget) {
    widget.addEventListener('dragstart', function(e) {
      dragged = widget;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', widget.dataset.widgetId);
      widget.classList.add('dashboard-widget-dragging');
    });
    widget.addEventListener('dragend', function() {
      widget.classList.remove('dashboard-widget-dragging');
      dragged = null;
    });
  });

  function saveLayout() {
    var formData = new FormData();
    formData.set('action', 'save_dashboard_layout');
    zones.forEach(function(zone) {
      var zoneName = zone.dataset.zone;
      widgetsIn(zone).forEach(function(widget) {
        formData.append('layout[' + zoneName + '][]', widget.dataset.widgetId);
      });
    });
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' });
  }

  document.querySelectorAll('.dashboard-stats-toggle-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var chart = btn.dataset.chart;
      document.querySelectorAll('.dashboard-stats-toggle-btn').forEach(function(b) {
        b.classList.toggle('active', b === btn);
      });
      // div[data-chart], not the bare attribute selector — the toggle
      // buttons themselves also carry data-chart, and would otherwise match
      // and hide each other.
      document.querySelectorAll('div[data-chart]').forEach(function(el) {
        el.hidden = el.dataset.chart !== chart;
      });
    });
  });

  var setsModal = document.getElementById('dashboard-sets-modal');
  var setsModalTitle = document.getElementById('dashboard-sets-modal-title');
  var setsModalContent = document.getElementById('dashboard-sets-modal-content');
  var setsModalClose = document.getElementById('dashboard-sets-modal-close');

  function closeSetsModal() {
    setsModal.style.display = 'none';
    setsModalContent.innerHTML = '';
  }

  if (setsModal && setsModalTitle && setsModalContent && setsModalClose) {
    setsModalClose.addEventListener('click', closeSetsModal);

    function openSetsModal(group, value, label) {
      setsModalTitle.textContent = texts.setsModalHeading.replace('{label}', label);
      setsModalContent.innerHTML = '';
      setsModal.style.display = 'flex';

      fetch('?action=dashboard_sets_by_group&group=' + encodeURIComponent(group) + '&value=' + encodeURIComponent(value), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          var sets = data.sets || [];
          if (sets.length === 0) {
            setsModalContent.innerHTML = '<p class="hint"></p>';
            setsModalContent.firstChild.textContent = texts.setsModalEmpty;
            return;
          }
          var list = document.createElement('ul');
          list.className = 'dashboard-set-list';
          sets.forEach(function(set) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = '?page=owned_set_detail&id=' + set.id;
            if (set.thumbnail) {
              var img = document.createElement('img');
              img.src = set.thumbnail;
              img.alt = '';
              img.className = 'dashboard-set-thumb';
              a.appendChild(img);
            }
            var name = document.createElement('span');
            name.className = 'dashboard-set-name';
            name.textContent = set.name;
            a.appendChild(name);
            var small = document.createElement('small');
            small.textContent = set.rebrickable_set_num;
            a.appendChild(small);
            li.appendChild(a);
            list.appendChild(li);
          });
          setsModalContent.appendChild(list);
        })
        .catch(function() {
          setsModalContent.innerHTML = '<p class="hint"></p>';
          setsModalContent.firstChild.textContent = texts.errorRetry;
        });
    }

    document.querySelectorAll('.dashboard-vbar-cell-clickable').forEach(function(cell) {
      cell.addEventListener('click', function() {
        openSetsModal(cell.dataset.group, cell.dataset.value, cell.dataset.label);
      });
      cell.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openSetsModal(cell.dataset.group, cell.dataset.value, cell.dataset.label);
        }
      });
    });
  }
})();
</script>
SCRIPT;

    return $html;
}

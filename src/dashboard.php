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
 * A bar per year (not the six status-bar numbers anymore — those are
 * already always visible in the status bar on every page, no need to
 * repeat them here) — wrapped in its own horizontally scrolling container
 * since a decades-spanning collection makes this genuinely wide, one bar
 * per year rather than shrinking bars to fit.
 */
function renderDashboardWidgetCollectionStats(PDO $pdo): string
{
    $byYear = computeOwnedSetsByYear($pdo);
    if (empty($byYear)) {
        return '<p class="hint">' . htmlspecialchars(t('dashboard_widget_recent_sets_empty')) . '</p>';
    }

    $maxCount = max($byYear);
    $html = '<div class="dashboard-chart-scroll"><div class="dashboard-chart">';
    foreach ($byYear as $year => $count) {
        $heightPercent = $maxCount > 0 ? (int) round(($count / $maxCount) * 100) : 0;
        $html .= '<div class="dashboard-chart-bar-col" title="' . $year . ': ' . $count . '">';
        $html .= '<span class="dashboard-chart-count">' . ($count > 0 ? formatNumber($count) : '') . '</span>';
        $html .= '<div class="dashboard-chart-bar-track"><div class="dashboard-chart-bar" style="height:' . $heightPercent . '%"></div></div>';
        $html .= '<span class="dashboard-chart-year">' . $year . '</span>';
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
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

    $editLabelsJson = json_encode([
        'edit' => t('dashboard_edit_button'),
        'done' => t('dashboard_edit_done_button'),
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
})();
</script>
SCRIPT;

    return $html;
}

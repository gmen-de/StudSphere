<?php

declare(strict_types=1);

function getNavIcon(string $key): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 15a7.5 7.5 0 0 1 15 0"/><path d="M12 15l3.5-4.5"/><circle cx="12" cy="15" r="1.2" fill="currentColor" stroke="none"/></svg>',
        'build' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7"/><path d="M6 10v9h12v-9"/><path d="M10 19v-5h4v5"/></svg>',
        'sets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8l8-4 8 4"/><rect x="4" y="8" width="16" height="12" rx="1"/></svg>',
        'bricks' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="7" rx="1.5"/><circle cx="9" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="15" cy="9" r="1.6" fill="currentColor" stroke="none"/></svg>',
        'minifigs' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5.5" r="2.5"/><path d="M8 10h8v5H8z"/><path d="M9 15v5"/><path d="M15 15v5"/><path d="M6 11l2 2"/><path d="M18 11l-2 2"/></svg>',
        'my_sets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8l8-4 8 4"/><rect x="4" y="8" width="16" height="12" rx="1"/><circle cx="18.5" cy="6" r="2.4" fill="currentColor" stroke="none"/></svg>',
        'my_bricks' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="7" rx="1.5"/><circle cx="9" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="15" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="6" r="2.4" fill="currentColor" stroke="none"/></svg>',
        'my_minifigs' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="5.5" r="2.5"/><path d="M7 10h8v5H7z"/><path d="M8 15v5"/><path d="M14 15v5"/><path d="M5 11l2 2"/><path d="M17 11l-2 2"/><circle cx="19" cy="6" r="2.4" fill="currentColor" stroke="none"/></svg>',
        'locations' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="1"/><path d="M4 10h16"/><path d="M4 16h16"/></svg>',
        'collection' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="7" cy="18" r="2" fill="currentColor" stroke="none"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H5v16h4"/><path d="M13 12h8"/><path d="M18 8l4 4-4 4"/></svg>',
    ];

    return $icons[$key] ?? '';
}

/**
 * Compact brick+checkmark / brick+cross glyphs replacing the "Vorhanden"/
 * "Defekt" stepper text labels in the add-to-collection wizard (space
 * saving — see renderAddOwnedSetWizardModal() in src/owned_sets.php) —
 * same brick shape (rect + two studs) as getNavIcon()'s 'bricks' icon, so
 * it still reads as "this is about a brick" even without the label text.
 */
function getPartStatusIcon(string $status): string
{
    $icons = [
        'owned' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="9" width="14" height="7" rx="1.3"/><circle cx="6" cy="7" r="1.3" fill="currentColor" stroke="none"/><circle cx="11" cy="7" r="1.3" fill="currentColor" stroke="none"/><path d="M15.5 16.5l3 3L23 12" stroke-width="2.3"/></svg>',
        'damaged' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="9" width="14" height="7" rx="1.3"/><circle cx="6" cy="7" r="1.3" fill="currentColor" stroke="none"/><circle cx="11" cy="7" r="1.3" fill="currentColor" stroke="none"/><path d="M16.5 12.5l6 6M22.5 12.5l-6 6" stroke-width="2.3"/></svg>',
    ];

    return $icons[$status] ?? '';
}

/**
 * Green-check / red-cross badge replacing a plain "Ja"/"Nein" in the
 * add-to-collection wizard's overview table — colors are hardcoded (not
 * currentColor) since the whole point is a fixed green/red status signal,
 * independent of surrounding text color.
 */
function getBooleanStatusIcon(bool $value): string
{
    if ($value) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" fill="none" stroke="#16a34a" stroke-width="1.8"/><path d="M7.5 12.3l3 3 6-6.6" stroke="#16a34a" stroke-width="2.2"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" fill="none" stroke="#dc2626" stroke-width="1.8"/><path d="M8.5 8.5l7 7M15.5 8.5l-7 7" stroke="#dc2626" stroke-width="2.2"/></svg>';
}

/**
 * Action-row icons (edit/delete/move/sell/BrickLink-export) — owned_set_detail's
 * action bar, replacing the old plain-text "Aus Sammlung entfernen" button.
 * Same 24x24/stroke-1.8/currentColor convention as getNavIcon(). 'bricklink_xml'
 * is deliberately generic (file + code brackets) rather than any BrickLink
 * branding — see the brand-assets decision to stay clear of LEGO/third-party
 * trademarks. 'copy'/'download' are the two buttons on the BrickLink XML result
 * modal (renderOwnedSetBricklinkModal() in src/owned_sets.php). 'pick_list' is
 * the action bar's placeholder "Pickliste" button (list + magnifying glass —
 * checking loose stock against what's missing) — no functionality behind it
 * yet, see that button's own doc comment in src/routes/pages.php. 'add' is
 * the dashboard's per-zone "add a widget" button (src/dashboard.php).
 * 'add_to_collection' is the catalog set_detail page's "Set zur Sammlung
 * hinzufügen" link — getNavIcon()'s 'sets' box with the same top-right dot
 * as 'my_sets' (the action does add it there) but a plus in the box body
 * instead of 'sets'/'my_sets' shared center divider line (removed from both
 * so the plus reads clearly and isn't mistaken for that line).
 * 'add_to_pick_list' is the not-yet-built "put this part on the pick list"
 * action — 'bricks' unchanged, plus placed in the brick body (which has no
 * center divider to remove, unlike 'sets'). No top-right dot: that badge
 * means "already owned" elsewhere (my_sets/my_bricks/my_minifigs), which
 * isn't what queuing a part for picking means. Not wired into any page yet
 * — the pick-list feature itself doesn't exist yet, this is just the icon.
 */
function getActionIcon(string $key): string
{
    $icons = [
        'edit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M18 7v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7"/><path d="M9 7V4.5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 4.5V7"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
        'move' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="M3 12h18"/><path d="M9 6l3-3 3 3"/><path d="M9 18l3 3 3-3"/><path d="M6 9l-3 3 3 3"/><path d="M18 9l3 3-3 3"/></svg>',
        'bricklink_xml' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v5h5"/><path d="M9.5 13l-2 2 2 2"/><path d="M14.5 13l2 2-2 2"/></svg>',
        'sell' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 13.5l-7.2 7.2a1.8 1.8 0 0 1-2.55 0L2.5 12.45V3h9.45l8.55 8.55a1.8 1.8 0 0 1 0 2.55z"/><circle cx="7.5" cy="7.5" r="1.3" fill="currentColor" stroke="none"/></svg>',
        'copy' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="1.5"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4H5.5A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/></svg>',
        'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 19h16"/></svg>',
        'pick_list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="12" height="16" rx="1.5"/><path d="M7 8h6"/><path d="M7 11.5h6"/><path d="M7 15h3.5"/><circle cx="16.5" cy="16.5" r="3"/><path d="M18.8 18.8L21 21"/></svg>',
        'add' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        // Mirror of 'download' (arrow into a tray, flipped upward) — the
        // owned-set photo gallery's drag-and-drop upload tile
        // (renderOwnedSetPhotoGallery(), src/owned_sets.php).
        'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3"/><path d="M7 8l5-5 5 5"/><path d="M4 19h16"/></svg>',
        // The location Explorer's tree expand/collapse arrow (src/routes/pages.php)
        // — CSS rotates it 90° when expanded rather than swapping in a
        // second "chevron-down" icon.
        'chevron_right' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
        // The location Explorer's column-resize handle: a circle with a
        // left arrow and a right arrow separated by a vertical divider.
        'resize_handle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M10.5 8l-3 4 3 4"/><path d="M12 7v10"/><path d="M13.5 8l3 4-3 4"/></svg>',
        // owned_set_detail's manual "refresh BrickLink price" button
        // (src/routes/pages.php) — CSS spins it while a request is in
        // flight.
        'refresh' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11A8 8 0 1 0 18 16"/><path d="M20 5v6h-6"/></svg>',
        // The instructions tab's tile fallback (renderSetInstructionsTab(),
        // src/instructions.php) — a plain document glyph, no PDF branding.
        'pdf' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v5h5"/><path d="M8.5 13h7"/><path d="M8.5 16.5h7"/></svg>',
        'add_to_collection' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8l8-4 8 4"/><rect x="4" y="8" width="16" height="12" rx="1"/><circle cx="18.5" cy="6" r="2.4" fill="currentColor" stroke="none"/><path d="M12 11v6"/><path d="M9 14h6"/></svg>',
        'add_to_pick_list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="7" rx="1.5"/><circle cx="9" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="15" cy="9" r="1.6" fill="currentColor" stroke="none"/><path d="M12 12.5v4"/><path d="M9 14.5h6"/></svg>',
        // "Bauanleitungen" (src/instruction_manuals.php) — an open booklet,
        // distinct from 'pdf' (a plain flat document, used for uploaded
        // instruction PDF scans on the catalog set page) and 'pick_list' (a
        // clipboard). Used both for the instructions_root tree row and every
        // manual tile.
        'instructions' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5c2.2-1.3 5-1.3 7 0v14c-2-1.3-4.8-1.3-7 0z"/><path d="M20 5.5c-2.2-1.3-5-1.3-7 0v14c2-1.3 4.8-1.3 7 0z"/><path d="M11 5.5v14"/></svg>',
        // Inventur (src/stocktakes.php) — a clipboard with a checkmark,
        // distinct from 'pick_list' (clipboard with dots).
        'stocktake' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="17" rx="1.5"/><path d="M9 3.5h6a1 1 0 0 1 1 1V6H8V4.5a1 1 0 0 1 1-1z"/><path d="M8 12.5l2.3 2.3L16 9.5"/></svg>',
    ];

    return $icons[$key] ?? '';
}

function getFlagIcon(string $locale): string
{
    $flags = [
        'de' => '<svg viewBox="0 0 30 18"><rect width="30" height="18" fill="#FFCE00"/><rect width="30" height="12" fill="#DD0000"/><rect width="30" height="6" fill="#000000"/></svg>',
        'en' => '<svg viewBox="0 0 30 18"><rect width="30" height="18" fill="#00247D"/><path d="M0 0L30 18M30 0L0 18" stroke="#ffffff" stroke-width="4"/><path d="M0 0L30 18M30 0L0 18" stroke="#CF142B" stroke-width="2"/><path d="M15 0V18M0 9H30" stroke="#ffffff" stroke-width="6"/><path d="M15 0V18M0 9H30" stroke="#CF142B" stroke-width="3.6"/></svg>',
    ];

    return $flags[$locale] ?? '';
}

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/owned_sets.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/i18n.php';

/**
 * Resolves a project-relative image path (local_image_path/thumbnail/
 * stored_path — every such field in this app is relative to the project
 * root, see e.g. getOwnedSetPhotoRelativePath()'s doc comment) to an
 * absolute filesystem path for mPDF's <img> tags. Never hands mPDF a bare
 * URL: getSetPartsList()'s 'thumbnail' field can fall back to a raw
 * remote_thumbnail URL when no local copy has been downloaded yet (see
 * src/images.php) — fetching that over HTTP mid-PDF-generation would add an
 * unpredictable delay this shared-hosting-targeted feature deliberately
 * avoids (see project_hosting_constraint memory). Missing/remote/
 * nonexistent images just resolve to null, rendered as an empty cell.
 */
function resolvePdfReportImagePath(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }
    if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
        return null;
    }
    $absolute = dirname(__DIR__) . '/' . $relativePath;
    return is_file($absolute) ? $absolute : null;
}

function pdfReportImageTag(?string $relativePath, string $maxSize = '26px'): string
{
    $absolute = resolvePdfReportImagePath($relativePath);
    if ($absolute === null) {
        return '';
    }
    return '<img src="' . htmlspecialchars($absolute) . '" style="max-width:' . $maxSize . ';max-height:' . $maxSize . ';">';
}

/**
 * Table rows shared by the Bauteile/Ersatzteile/Stickerbögen sections —
 * same nominal/actual/damaged/thumbnail shape returned by
 * getOwnedSetPartsWithStatus()/getOwnedSetSparePartsWithStatus()/
 * getOwnedSetStickerPartsWithStatus() (src/owned_sets.php). Status priority
 * (missing > damaged > complete) mirrors ownedSetInventoryTileStatusClass().
 */
function pdfReportPartsTableRows(array $items): string
{
    $rows = '';
    foreach ($items as $item) {
        $missing = $item['nominal_quantity'] - $item['actual_quantity'];
        $status = $missing > 0 ? 'missing' : ($item['damaged_quantity'] > 0 ? 'damaged' : 'complete');
        $rows .= '<tr>';
        $rows .= '<td class="pdf-report-thumb-cell">' . pdfReportImageTag($item['thumbnail']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($item['part_num']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($item['name']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($item['color_name'] ?? '') . '</td>';
        $rows .= '<td class="pdf-report-num-cell">' . htmlspecialchars(formatNumber($item['actual_quantity'])) . ' / ' . htmlspecialchars(formatNumber($item['nominal_quantity'])) . '</td>';
        $rows .= '<td class="pdf-report-status-cell pdf-report-status-' . $status . '">' . htmlspecialchars(t('owned_status_' . $status)) . '</td>';
        $rows .= '</tr>';
    }
    return $rows;
}

/**
 * @param array<int, array{minifig_id:int, fig_num:string, name:string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}> $minifigs
 */
function pdfReportMinifigsTableRows(array $minifigs): string
{
    $rows = '';
    foreach ($minifigs as $fig) {
        $missing = $fig['nominal_quantity'] - $fig['actual_quantity'];
        $status = $missing > 0 ? 'missing' : ($fig['damaged_quantity'] > 0 ? 'damaged' : 'complete');
        $rows .= '<tr>';
        $rows .= '<td class="pdf-report-thumb-cell">' . pdfReportImageTag($fig['thumbnail']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($fig['fig_num']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($fig['name']) . '</td>';
        $rows .= '<td class="pdf-report-num-cell">' . htmlspecialchars(formatNumber($fig['actual_quantity'])) . ' / ' . htmlspecialchars(formatNumber($fig['nominal_quantity'])) . '</td>';
        $rows .= '<td class="pdf-report-status-cell pdf-report-status-' . $status . '">' . htmlspecialchars(t('owned_status_' . $status)) . '</td>';
        $rows .= '</tr>';
    }
    return $rows;
}

/**
 * @param array<int, array{category:string, thumbnail:?string, name:string, damaged:int, missing:int}> $rows
 */
function pdfReportDamagedMissingTableRows(array $rows): string
{
    $html = '';
    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td class="pdf-report-thumb-cell">' . pdfReportImageTag($row['thumbnail']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['category']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
        $html .= '<td class="pdf-report-num-cell">' . htmlspecialchars(formatNumber($row['damaged'])) . '</td>';
        $html .= '<td class="pdf-report-num-cell">' . htmlspecialchars(formatNumber($row['missing'])) . '</td>';
        $html .= '</tr>';
    }
    return $html;
}

/**
 * Two-column photo grid (a <table>, not CSS flex/grid — mPDF's layout
 * engine handles table pagination far more reliably than flex/grid across
 * page breaks). One row per photo pair, padded with an empty cell if the
 * gallery has an odd count.
 *
 * @param array<int, array{caption:?string, stored_path:string}> $photos
 */
function pdfReportPhotoGrid(array $photos): string
{
    if (empty($photos)) {
        return '';
    }
    $html = '<table class="pdf-report-photo-grid"><tr>';
    foreach ($photos as $i => $photo) {
        if ($i > 0 && $i % 2 === 0) {
            $html .= '</tr><tr>';
        }
        $absolute = resolvePdfReportImagePath($photo['stored_path']);
        $html .= '<td class="pdf-report-photo-cell">';
        if ($absolute !== null) {
            $html .= '<img src="' . htmlspecialchars($absolute) . '" style="width:100%;">';
        }
        if (!empty($photo['caption'])) {
            $html .= '<div class="pdf-report-photo-caption">' . htmlspecialchars($photo['caption']) . '</div>';
        }
        $html .= '</td>';
    }
    if (count($photos) % 2 === 1) {
        $html .= '<td></td>';
    }
    $html .= '</tr></table>';
    return $html;
}

/**
 * A heading + table wrapper, skipped entirely (returns '') when $items is
 * empty — e.g. Ersatzteile/Stickerbögen only appear in the report when the
 * set actually has any, same as the web tab strip only shows real
 * categories.
 */
function pdfReportTableSection(string $heading, string $rowsHtml): string
{
    if ($rowsHtml === '') {
        return '';
    }
    $html = '<h2>' . htmlspecialchars($heading) . '</h2>';
    $html .= '<table class="pdf-report-table"><thead><tr>';
    $html .= '<th>' . htmlspecialchars(t('pdf_report_col_image')) . '</th>';
    $html .= '<th>' . htmlspecialchars(t('pdf_report_col_number')) . '</th>';
    $html .= '<th>' . htmlspecialchars(t('pdf_report_col_name')) . '</th>';
    $html .= '<th>' . htmlspecialchars(t('pdf_report_col_color')) . '</th>';
    $html .= '<th>' . htmlspecialchars(t('pdf_report_col_quantity')) . '</th>';
    $html .= '<th>' . htmlspecialchars(t('pdf_report_col_status')) . '</th>';
    $html .= '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>';
    return $html;
}

/**
 * Builds a complete "everything about this owned set instance" PDF —
 * general info, full Bauteile/Ersatzteile/Stickerbögen/Minifiguren
 * inventory (not just what's wrong, unlike the BrickLink XML export), a
 * Beschädigt/Fehlend recap, and the photo gallery. Every data point comes
 * from the same getter functions the web detail page
 * (?page=owned_set_detail, src/routes/pages.php) already uses — no new
 * queries. Returns the raw PDF bytes (mpdf/mpdf, pure PHP — no exec()/
 * Imagick, so it works even on shared hosts that forbid both, see
 * project_hosting_constraint memory).
 */
function buildOwnedSetPdfReport(PDO $pdo, array $ownedSet): string
{
    $catalogSet = getSetById($pdo, $ownedSet['set_id']);
    $completeness = getOwnedSetCompleteness($pdo, $ownedSet);
    $summary = getOwnedSetInventorySummary($pdo, $ownedSet, getLocale());

    $locationPath = getStorageLocationAncestors($ownedSet['location_id']);
    array_pop($locationPath);
    $locationLabel = implode(' » ', array_column($locationPath, 'name'));

    $instanceNumber = 1;
    foreach (getOwnedSetsForSet($pdo, $ownedSet['set_id']) as $i => $sibling) {
        if ($sibling['id'] === $ownedSet['id']) {
            $instanceNumber = $i + 1;
            break;
        }
    }

    $bricklinkPrice = $catalogSet !== null
        ? formatBricklinkPriceSummary(
            $catalogSet['bricklink_price_new'],
            $catalogSet['bricklink_price_used'],
            $catalogSet['bricklink_price_currency'],
            $catalogSet['bricklink_price_checked_at'],
            $ownedSet['condition_type']
        )
        : null;

    $percent = min(100.0, max(0.0, $completeness['percent']));

    $html = '<style>' . pdfReportStylesheet() . '</style>';

    $html .= '<div class="pdf-report-header">';
    $coverImage = pdfReportImageTag($ownedSet['thumbnail'], '90px');
    if ($coverImage !== '') {
        $html .= '<div class="pdf-report-header-image">' . $coverImage . '</div>';
    }
    $html .= '<div class="pdf-report-header-text">';
    $html .= '<h1>' . htmlspecialchars($ownedSet['name']) . '</h1>';
    $html .= '<p class="pdf-report-subtitle">' . htmlspecialchars($ownedSet['rebrickable_set_num']) . ' · ' . htmlspecialchars(t('owned_set_instance_label', ['n' => (string) $instanceNumber])) . '</p>';
    $html .= '</div></div>';

    $html .= '<table class="pdf-report-info-table">';
    if ($catalogSet !== null && $catalogSet['year'] !== null) {
        $html .= '<tr><th>' . htmlspecialchars(t('set_detail_field_released')) . '</th><td>' . htmlspecialchars((string) $catalogSet['year']) . '</td></tr>';
    }
    if ($catalogSet !== null && $catalogSet['year_retired'] !== null) {
        $html .= '<tr><th>' . htmlspecialchars(t('set_detail_field_eol')) . '</th><td>' . htmlspecialchars((string) $catalogSet['year_retired']) . '</td></tr>';
    }
    if ($catalogSet !== null && $catalogSet['theme_name'] !== null) {
        $html .= '<tr><th>' . htmlspecialchars(t('set_detail_field_theme')) . '</th><td>' . htmlspecialchars($catalogSet['theme_name']) . '</td></tr>';
    }
    $html .= '<tr><th>' . htmlspecialchars(t('owned_set_field_location')) . '</th><td>' . htmlspecialchars($locationLabel) . '</td></tr>';
    $html .= '<tr><th>' . htmlspecialchars(t('owned_set_field_condition')) . '</th><td>' . htmlspecialchars($ownedSet['condition_type'] === 'new' ? t('owned_set_condition_new') : t('owned_set_condition_used')) . '</td></tr>';
    if ($bricklinkPrice !== null) {
        $html .= '<tr><th>' . htmlspecialchars(t('owned_set_bricklink_price_label')) . '</th><td>' . htmlspecialchars($bricklinkPrice['text']) . '</td></tr>';
    }
    $html .= '</table>';

    $html .= '<div class="pdf-report-completeness">';
    $html .= '<span>' . htmlspecialchars(t('owned_set_completeness_value', ['percent' => formatNumber($percent, 1), 'actual' => formatNumber($completeness['actual']), 'nominal' => formatNumber($completeness['nominal'])])) . '</span>';
    $html .= '<div class="pdf-report-bar-track"><div class="pdf-report-bar-fill" style="width:' . $percent . '%;"></div></div>';
    $html .= '</div>';

    $html .= '<table class="pdf-report-info-table">';
    $html .= '<tr><th>' . htmlspecialchars(t('set_detail_field_exclusive')) . '</th><td>' . htmlspecialchars(t('owned_set_num_parts_actual', ['actual' => formatNumber($summary['exclusive']['actual']), 'nominal' => formatNumber($summary['exclusive']['nominal'])])) . '</td></tr>';
    $html .= '<tr><th>' . htmlspecialchars(t('set_detail_field_rare')) . '</th><td>' . htmlspecialchars(t('owned_set_num_parts_actual', ['actual' => formatNumber($summary['rare']['actual']), 'nominal' => formatNumber($summary['rare']['nominal'])])) . '</td></tr>';
    $html .= '<tr><th>' . htmlspecialchars(t('set_detail_field_stickers')) . '</th><td>' . htmlspecialchars(t('owned_set_num_parts_actual', ['actual' => formatNumber($summary['stickers']['actual']), 'nominal' => formatNumber($summary['stickers']['nominal'])])) . '</td></tr>';
    $html .= '<tr><th>' . htmlspecialchars(t('owned_set_tab_minifigs')) . '</th><td>' . htmlspecialchars(t('owned_set_num_parts_actual', ['actual' => formatNumber($summary['minifigs']['actual']), 'nominal' => formatNumber($summary['minifigs']['nominal'])])) . '</td></tr>';
    $html .= '</table>';

    $html .= pdfReportTableSection(t('owned_set_tab_inventory'), pdfReportPartsTableRows(getOwnedSetPartsWithStatus($pdo, $ownedSet, getLocale())));
    $html .= pdfReportTableSection(t('owned_set_tab_spares'), pdfReportPartsTableRows(getOwnedSetSparePartsWithStatus($pdo, $ownedSet, getLocale())));
    $html .= pdfReportTableSection(t('owned_set_tab_stickers'), pdfReportPartsTableRows(getOwnedSetStickerPartsWithStatus($pdo, $ownedSet, getLocale())));

    $minifigRows = pdfReportMinifigsTableRows(getOwnedSetMinifigsWithStatus($pdo, $ownedSet));
    if ($minifigRows !== '') {
        $html .= '<h2>' . htmlspecialchars(t('owned_set_tab_minifigs')) . '</h2>';
        $html .= '<table class="pdf-report-table"><thead><tr>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_image')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_number')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_name')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_quantity')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_status')) . '</th>';
        $html .= '</tr></thead><tbody>' . $minifigRows . '</tbody></table>';
    }

    $damagedMissingRows = pdfReportDamagedMissingTableRows(getOwnedSetDamagedMissingRows($pdo, $ownedSet, true, true));
    if ($damagedMissingRows !== '') {
        $html .= '<h2>' . htmlspecialchars(t('owned_set_tab_damaged_missing')) . '</h2>';
        $html .= '<table class="pdf-report-table"><thead><tr>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_image')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_category')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_name')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_damaged')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('pdf_report_col_missing')) . '</th>';
        $html .= '</tr></thead><tbody>' . $damagedMissingRows . '</tbody></table>';
    }

    $photoGrid = pdfReportPhotoGrid(getOwnedSetPhotos($pdo, $ownedSet['id']));
    if ($photoGrid !== '') {
        $html .= '<h2>' . htmlspecialchars(t('owned_set_tab_gallery')) . '</h2>';
        $html .= $photoGrid;
    }

    $tempDir = dirname(__DIR__) . '/storage/mpdf_tmp';
    if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
        throw new RuntimeException('Konnte Temp-Verzeichnis für den PDF-Bericht nicht erstellen: ' . $tempDir);
    }

    $mpdf = new \Mpdf\Mpdf([
        'tempDir' => $tempDir,
        'format' => 'A4',
        'margin_top' => 18,
        'margin_bottom' => 18,
        'margin_left' => 15,
        'margin_right' => 15,
    ]);
    $mpdf->SetTitle($ownedSet['rebrickable_set_num'] . ' - ' . $ownedSet['name']);
    $mpdf->SetFooter(htmlspecialchars(t('owned_set_pdf_footer')) . ' {DATE j.m.Y} · {PAGENO}/{nbpg}');
    $mpdf->WriteHTML($html);

    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * mPDF only supports a CSS subset (no flexbox/grid, limited selectors) —
 * everything here is deliberately table/block based to stay inside what
 * mPDF's layout engine reliably paginates across pages.
 */
function pdfReportStylesheet(): string
{
    return <<<CSS
body { font-family: sans-serif; font-size: 10pt; color: #1e293b; }
h1 { font-size: 18pt; margin: 0 0 3pt 0; }
h2 { font-size: 13pt; margin: 14pt 0 6pt 0; color: #1e293b; border-bottom: 1pt solid #cbd5e1; padding-bottom: 3pt; }
.pdf-report-header { margin-bottom: 10pt; }
.pdf-report-header-image { float: left; margin-right: 12pt; }
.pdf-report-subtitle { margin: 0; color: #64748b; }
.pdf-report-info-table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
.pdf-report-info-table th { text-align: left; width: 40%; padding: 3pt 6pt; color: #64748b; font-weight: normal; border-bottom: 0.5pt solid #e2e8f0; }
.pdf-report-info-table td { padding: 3pt 6pt; border-bottom: 0.5pt solid #e2e8f0; }
.pdf-report-completeness { margin: 8pt 0; }
.pdf-report-bar-track { width: 100%; height: 8pt; background: #e2e8f0; border-radius: 4pt; margin-top: 3pt; }
.pdf-report-bar-fill { height: 8pt; background: #16a34a; border-radius: 4pt; }
.pdf-report-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
.pdf-report-table th { text-align: left; background: #f1f5f9; padding: 4pt 6pt; border-bottom: 1pt solid #cbd5e1; }
.pdf-report-table td { padding: 4pt 6pt; border-bottom: 0.5pt solid #e2e8f0; vertical-align: middle; }
.pdf-report-thumb-cell { width: 30pt; }
.pdf-report-num-cell { white-space: nowrap; }
.pdf-report-status-cell { font-weight: bold; }
.pdf-report-status-complete { color: #16a34a; }
.pdf-report-status-damaged { color: #d97706; }
.pdf-report-status-missing { color: #dc2626; }
.pdf-report-photo-grid { width: 100%; border-collapse: collapse; }
.pdf-report-photo-cell { width: 50%; padding: 4pt; vertical-align: top; }
.pdf-report-photo-caption { font-size: 8pt; color: #64748b; margin-top: 2pt; }
CSS;
}

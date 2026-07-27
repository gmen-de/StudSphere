<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const INSTRUCTION_MAX_LABEL_LENGTH = 255;

/**
 * Where an uploaded instruction PDF for a set lives. Deliberately under
 * public/ (like public/images/), not storage/ — this app has no per-user
 * ownership/permission concept (every logged-in user sees everything, see
 * getSetInstructions() callers), so there's nothing a protected/streamed
 * download would gain here, while a direct <a href> avoids PHP having to
 * buffer/stream potentially large PDFs on shared hosting with no control
 * over memory_limit.
 *
 * No filename-based sharding (unlike getImageStorageDir()) — a set rarely
 * has more than a handful of instruction booklets, so one directory per set
 * never grows large enough to need it.
 */
function getInstructionsStorageDir(int $setId): string
{
    $dir = dirname(__DIR__) . '/public/instructions/' . $setId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Verzeichnis für Bauanleitungen nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getInstructionRelativePath(int $setId, string $filename): string
{
    return 'public/instructions/' . $setId . '/' . $filename;
}

/**
 * The on-disk filename is always a random hex string with a fixed .pdf
 * suffix — never derived from the user-supplied original filename. This
 * closes path-traversal concerns outright (no user input reaches the
 * filesystem path) and means a mismatched/spoofed upload can never end up
 * served with an extension that would make a misconfigured host execute it.
 */
function generateInstructionFilename(): string
{
    return bin2hex(random_bytes(16)) . '.pdf';
}

/**
 * @return array<int, array{id:int, set_id:int, label:?string, original_filename:string, stored_path:string, file_size:int, uploaded_at:string}>
 */
function getSetInstructions(PDO $pdo, int $setId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, set_id, label, original_filename, stored_path, file_size, uploaded_at
         FROM set_instructions WHERE set_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$setId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['set_id'] = (int) $row['set_id'];
        $row['file_size'] = (int) $row['file_size'];
    }
    unset($row);
    return $rows;
}

function getSetInstructionById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, set_id, label, original_filename, stored_path, file_size, uploaded_at
         FROM set_instructions WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['set_id'] = (int) $row['set_id'];
    $row['file_size'] = (int) $row['file_size'];
    return $row;
}

/**
 * @return array{id:int, set_id:int, label:?string, original_filename:string, stored_path:string, file_size:int, uploaded_at:string}
 */
function addSetInstruction(PDO $pdo, int $setId, ?string $label, string $originalFilename, string $storedPath, int $fileSize, ?int $uploadedBy): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO set_instructions (set_id, label, original_filename, stored_path, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$setId, $label, $originalFilename, $storedPath, $fileSize, $uploadedBy]);
    $id = (int) $pdo->lastInsertId();

    $instruction = getSetInstructionById($pdo, $id);
    if ($instruction === null) {
        throw new RuntimeException('Bauanleitung konnte nach dem Speichern nicht gefunden werden.');
    }
    return $instruction;
}

/**
 * Deletes the DB row and returns it (so the caller can unlink the on-disk
 * file afterwards) — null if no such instruction exists.
 */
function deleteSetInstruction(PDO $pdo, int $id): ?array
{
    $instruction = getSetInstructionById($pdo, $id);
    if ($instruction === null) {
        return null;
    }
    $stmt = $pdo->prepare('DELETE FROM set_instructions WHERE id = ?');
    $stmt->execute([$id]);
    return $instruction;
}

function formatFileSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    $decimals = $unitIndex === 0 ? 0 : 1;
    return number_format($value, $decimals, ',', '.') . ' ' . $units[$unitIndex];
}

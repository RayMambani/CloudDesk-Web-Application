<?php
// Safe handling of ticket attachments.

/**
 * Validates and stores an uploaded file under uploads/ with a random name.
 * @return array{path:string,name:string}|null  null when no file was sent
 * @throws RuntimeException with a user-facing message when the file is rejected
 */
function storeAttachment(array $file, string $ticketId): ?array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException('The file could not be uploaded (error ' . (int)$file['error'] . ').');
    if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) throw new RuntimeException('The file is too large (max ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB).');

    $original = basename((string)$file['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) throw new RuntimeException('This file type is not allowed.');

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('The uploads folder is not writable.');

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) throw new RuntimeException('The file could not be saved.');

    $safeName = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $original);
    return ['path' => 'uploads/' . $stored, 'name' => mb_substr($safeName, 0, 200)];
}

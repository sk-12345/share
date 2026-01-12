<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user'])) { http_response_code(401); exit; }

$role_id = (int)($_SESSION['user']['role_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);

$is_admin = in_array($role_id, [1,2,3], true);

$album_id = (int)($_GET['album_id'] ?? 0);
if ($album_id <= 0) { http_response_code(400); exit; }

$BASE_REAL = __DIR__ . '/../img/albums/';

// アルバム情報（権限）
$stmt = $pdo->prepare("SELECT title, folder_key, created_by FROM albums WHERE id=? LIMIT 1");
$stmt->execute([$album_id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$a) { http_response_code(404); exit; }

if (!$is_admin && (int)$a['created_by'] !== $user_id) {
  http_response_code(403); exit;
}

// 写真
$stmt = $pdo->prepare("SELECT file_name, original_name FROM album_photos WHERE album_id=? ORDER BY id ASC");
$stmt->execute([$album_id]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), "alb_");
$zipPath = $tmp . ".zip";
@unlink($tmp);

if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
  http_response_code(500); exit;
}

foreach ($photos as $p) {
  $path = $BASE_REAL . $a['folder_key'] . '/' . $p['file_name'];
  if (is_file($path)) {
    $zip->addFile($path, $p['original_name'] ?: $p['file_name']);
  }
}
$zip->close();

$name = ($a['title'] ?: 'album') . ".zip";
$name = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/', '_', $name);

header("Content-Type: application/zip");
header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
header("Content-Length: " . filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
exit;

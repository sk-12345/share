<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user'])) { http_response_code(401); exit; }

$role_id = (int)($_SESSION['user']['role_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);

$is_admin = in_array($role_id, [1,2,3], true);

$photo_ids = $_POST['photo_ids'] ?? [];
if (!is_array($photo_ids) || count($photo_ids) === 0) { http_response_code(400); exit; }

$BASE_REAL = __DIR__ . '/../img/albums/';

// IN句
$place = implode(',', array_fill(0, count($photo_ids), '?'));
$params = array_map('intval', $photo_ids);

$sql = "
  SELECT p.file_name, p.original_name, a.folder_key, a.created_by
  FROM album_photos p
  JOIN albums a ON a.id = p.album_id
  WHERE p.id IN ($place)
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { http_response_code(404); exit; }

// 権限（OWNERかADMINのみ）
foreach ($rows as $r) {
  if (!$is_admin && (int)$r['created_by'] !== $user_id) {
    http_response_code(403); exit;
  }
}

$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), "sel_");
$zipPath = $tmp . ".zip";
@unlink($tmp);

if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
  http_response_code(500); exit;
}

foreach ($rows as $r) {
  $path = $BASE_REAL . $r['folder_key'] . '/' . $r['file_name'];
  if (is_file($path)) {
    $zip->addFile($path, $r['original_name'] ?: $r['file_name']);
  }
}
$zip->close();

header("Content-Type: application/zip");
header('Content-Disposition: attachment; filename="selected_photos.zip"');
header("Content-Length: " . filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
exit;

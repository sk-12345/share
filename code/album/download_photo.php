<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user'])) { http_response_code(401); exit; }

$role_id = (int)($_SESSION['user']['role_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);

// 権限（あなたの基準に合わせて）
$is_admin   = in_array($role_id, [1,2,3,4], true);
$is_general = ($role_id === 4);

// 保存場所（album_api.php と同じにする）
$BASE_REAL = __DIR__ . '/../img/albums/';

$photo_id = (int)($_GET['photo_id'] ?? 0);
if ($photo_id <= 0) { http_response_code(400); exit; }

$stmt = $pdo->prepare("
  SELECT p.file_name, p.original_name, a.folder_key, a.created_by
  FROM album_photos p
  JOIN albums a ON a.id = p.album_id
  WHERE p.id=?
  LIMIT 1
");
$stmt->execute([$photo_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit; }

if (!$is_admin && (int)$row['created_by'] !== $user_id) {
  http_response_code(403); exit;
}

$path = $BASE_REAL . $row['folder_key'] . '/' . $row['file_name'];
if (!is_file($path)) { http_response_code(404); exit; }

$dl = $row['original_name'] ?: $row['file_name'];
header("Content-Type: application/octet-stream");
header('Content-Disposition: attachment; filename="' . rawurlencode($dl) . '"');
header("Content-Length: " . filesize($path));
readfile($path);
exit;

<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user'])) {
  http_response_code(401);
  echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$role_id = (int)($_SESSION['user']['role_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);

// 権限（例）
// 1 SYSTEM, 2 ADMIN, 3 PHOTO, 4 GENERAL
$can_create = in_array($role_id, [1,2,3,4], true);
$is_admin   = in_array($role_id, [1,2,3], true); // ✅ GENERAL(4)は管理扱いにしない
$is_general = ($role_id === 4);

// 保存実体は「C:\xampp\htdocs\share\img\albums」(シンボリックリンク)
$BASE_REAL = __DIR__ . '/../../img/albums/';   // ← ローカルパス（実体はシンボリックリンク）
$BASE_URL  = '/share/img/albums/';             // ← URL（先頭/の絶対パス）

if (!is_dir($BASE_REAL)) mkdir($BASE_REAL, 0777, true);

function safeFolderKey(string $title): string {
  $t = mb_strtolower($title, 'UTF-8');
  $t = preg_replace('/[^a-z0-9\-_]+/u', '-', $t);
  $t = trim($t, '-');
  if ($t === '') $t = 'album';
  return $t;
}

function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) rrmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

function safeDownloadName(string $name): string {
  // Windows/ブラウザでNGな文字を除去
  return preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/', '_', $name);
}

// =======================================================
// GET: 1枚ダウンロード  ?action=download_photo&photo_id=xx
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_photo') {
  $photo_id = (int)($_GET['photo_id'] ?? 0);
  if ($photo_id <= 0) { http_response_code(400); exit; }

  $stmt = $pdo->prepare("
    SELECT p.file_name, p.original_name, a.folder_key, a.created_by
    FROM album_photos p
    JOIN albums a ON a.id = p.album_id
    WHERE p.id = ?
    LIMIT 1
  ");
  $stmt->execute([$photo_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); exit; }

  $isOwner = ((int)$row['created_by'] === $user_id);
  if (!$is_admin && !$isOwner) { http_response_code(403); exit; }

  $path = $BASE_REAL . $row['folder_key'] . '/' . $row['file_name'];
  if (!is_file($path)) { http_response_code(404); exit; }

  header_remove('Content-Type');
  header('Content-Type: application/octet-stream');

  $dl = $row['original_name'] ?: $row['file_name'];
  $dl = safeDownloadName($dl);

  header('Content-Disposition: attachment; filename="' . rawurlencode($dl) . '"');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
}

// =======================================================
// GET: アルバムZIP  ?action=download_album_zip&album_id=xx
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_album_zip') {
  $album_id = (int)($_GET['album_id'] ?? 0);
  if ($album_id <= 0) { http_response_code(400); exit; }

  $stmt = $pdo->prepare("SELECT title, folder_key, created_by FROM albums WHERE id=?");
  $stmt->execute([$album_id]);
  $a = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$a) { http_response_code(404); exit; }

  $isOwner = ((int)$a['created_by'] === $user_id);
  if (!$is_admin && !$isOwner) { http_response_code(403); exit; }

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

  $folder_key = (string)$a['folder_key'];
  foreach ($photos as $p) {
    $path = $BASE_REAL . $folder_key . '/' . $p['file_name'];
    if (is_file($path)) {
      $zip->addFile($path, $p['original_name'] ?: $p['file_name']);
    }
  }
  $zip->close();

  $name = safeDownloadName(($a['title'] ?: 'album') . '.zip');

  header_remove('Content-Type');
  header("Content-Type: application/zip");
  header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
  header("Content-Length: " . filesize($zipPath));
  readfile($zipPath);
  @unlink($zipPath);
  exit;
}

// ----------------------
// POST
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // ---- アルバム作成（複数写真アップ） ----
  if ($action === 'create_album') {
    if (!$can_create) {
      http_response_code(403);
      echo json_encode(['error'=>'no_create_permission'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '' || $description === '') {
      http_response_code(400);
      echo json_encode(['error'=>'title_description_required'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!isset($_FILES['images'])) {
      http_response_code(400);
      echo json_encode(['error'=>'images_required'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $allowed = ['jpg','jpeg','png','gif','webp'];

    $names = $_FILES['images']['name'] ?? [];
    $tmps  = $_FILES['images']['tmp_name'] ?? [];
    $errs  = $_FILES['images']['error'] ?? [];

    if (!is_array($names) || count($names) === 0) {
      http_response_code(400);
      echo json_encode(['error'=>'images_required'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $base = safeFolderKey($title);
      $folder_key = $base . '_' . substr(bin2hex(random_bytes(6)), 0, 12);

      $stmt = $pdo->prepare("INSERT INTO albums (title, description, folder_key, created_by) VALUES (?, ?, ?, ?)");
      $stmt->execute([$title, $description, $folder_key, $user_id]);
      $album_id = (int)$pdo->lastInsertId();

      $albumReal = $BASE_REAL . $folder_key . '/';
      if (!is_dir($albumReal)) mkdir($albumReal, 0777, true);

      $ins = $pdo->prepare("INSERT INTO album_photos (album_id, file_name, original_name) VALUES (?, ?, ?)");

      $savedCount = 0;
      for ($i=0; $i<count($names); $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $orig = (string)$names[$i];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $file = 'photo_' . uniqid('', true) . '.' . $ext;
        $dst = $albumReal . $file;

        if (!move_uploaded_file($tmps[$i], $dst)) continue;

        $ins->execute([$album_id, $file, $orig]);
        $savedCount++;
      }

      if ($savedCount === 0) {
        rrmdir($albumReal);
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error'=>'no_valid_images'], JSON_UNESCAPED_UNICODE);
        exit;
      }

      $pdo->commit();
      echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      http_response_code(500);
      echo json_encode(['error'=>'server_error'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  // ---- アルバム編集 ----
  if ($action === 'update_album') {
    if ($is_general) {
      http_response_code(403);
      echo json_encode(['error'=>'no_edit_permission'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $album_id = (int)($_POST['album_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($album_id <= 0 || $title === '' || $description === '') {
      http_response_code(400);
      echo json_encode(['error'=>'invalid_params'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("SELECT created_by FROM albums WHERE id=?");
    $stmt->execute([$album_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      http_response_code(404);
      echo json_encode(['error'=>'not_found'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!$is_admin && (int)$row['created_by'] !== $user_id) {
      http_response_code(403);
      echo json_encode(['error'=>'not_owner'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("UPDATE albums SET title=?, description=? WHERE id=?");
    $stmt->execute([$title, $description, $album_id]);

    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ---- 既存アルバムに写真追加 ----
  if ($action === 'add_photos') {
    if ($is_general) {
      http_response_code(403);
      echo json_encode(['error'=>'no_add_permission'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $album_id = (int)($_POST['album_id'] ?? 0);
    if ($album_id <= 0) {
      http_response_code(400);
      echo json_encode(['error'=>'invalid_album_id'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("SELECT folder_key, created_by FROM albums WHERE id=?");
    $stmt->execute([$album_id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
      http_response_code(404);
      echo json_encode(['error'=>'not_found'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!$is_admin && (int)$a['created_by'] !== $user_id) {
      http_response_code(403);
      echo json_encode(['error'=>'not_owner'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!isset($_FILES['images'])) {
      http_response_code(400);
      echo json_encode(['error'=>'images_required'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $allowed = ['jpg','jpeg','png','gif','webp'];
    $names = $_FILES['images']['name'] ?? [];
    $tmps  = $_FILES['images']['tmp_name'] ?? [];
    $errs  = $_FILES['images']['error'] ?? [];

    $folder_key = (string)$a['folder_key'];
    $albumReal = $BASE_REAL . $folder_key . '/';
    if (!is_dir($albumReal)) mkdir($albumReal, 0777, true);

    $ins = $pdo->prepare("INSERT INTO album_photos (album_id, file_name, original_name) VALUES (?, ?, ?)");

    $savedCount = 0;
    for ($i=0; $i<count($names); $i++) {
      if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

      $orig = (string)$names[$i];
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowed, true)) continue;

      $file = 'photo_' . uniqid('', true) . '.' . $ext;
      $dst = $albumReal . $file;

      if (!move_uploaded_file($tmps[$i], $dst)) continue;

      $ins->execute([$album_id, $file, $orig]);
      $savedCount++;
    }

    echo json_encode(['ok'=>true, 'saved'=>$savedCount], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ---- 写真1枚削除 ----
  if ($action === 'delete_photo') {
    if ($is_general) {
      http_response_code(403);
      echo json_encode(['error'=>'no_delete_permission'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $photo_id = (int)($_POST['photo_id'] ?? 0);
    if ($photo_id <= 0) {
      http_response_code(400);
      echo json_encode(['error'=>'invalid_photo_id'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("
      SELECT p.file_name, a.folder_key, a.created_by
      FROM album_photos p
      JOIN albums a ON a.id = p.album_id
      WHERE p.id = ?
    ");
    $stmt->execute([$photo_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      http_response_code(404);
      echo json_encode(['error'=>'not_found'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!$is_admin && (int)$row['created_by'] !== $user_id) {
      http_response_code(403);
      echo json_encode(['error'=>'not_owner'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $real = $BASE_REAL . $row['folder_key'] . '/' . $row['file_name'];
    if (is_file($real)) @unlink($real);

    $del = $pdo->prepare("DELETE FROM album_photos WHERE id=?");
    $del->execute([$photo_id]);

    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ---- アルバム削除 ----
  if ($action === 'delete_album') {
    if ($is_general) {
      http_response_code(403);
      echo json_encode(['error'=>'no_delete_permission'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $album_id = (int)($_POST['album_id'] ?? 0);
    if ($album_id <= 0) {
      http_response_code(400);
      echo json_encode(['error'=>'invalid_album_id'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("SELECT folder_key, created_by FROM albums WHERE id=?");
    $stmt->execute([$album_id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
      http_response_code(404);
      echo json_encode(['error'=>'not_found'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!$is_admin && (int)$a['created_by'] !== $user_id) {
      http_response_code(403);
      echo json_encode(['error'=>'not_owner'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $folder_key = (string)$a['folder_key'];
    $albumReal = $BASE_REAL . $folder_key . '/';

    $del = $pdo->prepare("DELETE FROM albums WHERE id=?");
    $del->execute([$album_id]);

    rrmdir($albumReal);

    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ---- 選択した写真をZIPでダウンロード（POST） ----
  if ($action === 'download_selected_zip') {
    header_remove('Content-Type');

    $photoIds = $_POST['photo_ids'] ?? [];
    if (!is_array($photoIds) || count($photoIds) === 0) {
      http_response_code(400);
      exit;
    }

    $in = implode(',', array_fill(0, count($photoIds), '?'));
    $params = array_map('intval', $photoIds);

    $sql = "
      SELECT p.file_name, p.original_name, a.folder_key, a.created_by
      FROM album_photos p
      JOIN albums a ON a.id = p.album_id
      WHERE p.id IN ($in)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { http_response_code(404); exit; }

    foreach ($rows as $r) {
      $isOwner = ((int)$r['created_by'] === $user_id);
      if (!$is_admin && !$isOwner) { http_response_code(403); exit; }
    }

    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), "sel_");
    $zipPath = $tmp . ".zip";
    @unlink($tmp);

    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
      http_response_code(500);
      exit;
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
  }

  http_response_code(400);
  echo json_encode(['error'=>'unknown_action'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ----------------------
// GET：一覧
// ----------------------
$albums = $pdo->query("SELECT * FROM albums ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$ids = array_map(fn($a) => (int)$a['id'], $albums);
$photosByAlbum = [];

if (count($ids) > 0) {
  $in = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("SELECT * FROM album_photos WHERE album_id IN ($in) ORDER BY created_at DESC");
  $stmt->execute($ids);
  $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($photos as $p) {
    $aid = (int)$p['album_id'];
    $photosByAlbum[$aid] ??= [];
    $photosByAlbum[$aid][] = $p;
  }
}

foreach ($albums as &$a) {
  $aid = (int)$a['id'];
  $folder_key = (string)$a['folder_key'];
  $list = $photosByAlbum[$aid] ?? [];

  foreach ($list as &$p) {
    $p['image_url'] = $BASE_URL . $folder_key . '/' . $p['file_name'];
    // ついでにDLリンクを作りたいなら
    $p['download_url'] = "album_api.php?action=download_photo&photo_id=" . (int)$p['id'];
  }

  $a['photos'] = $list;

  $isOwner = ((int)$a['created_by'] === $user_id);
  $a['can_edit'] = (!$is_general) && ($is_admin || $isOwner);
  $a['can_delete'] = (!$is_general) && ($is_admin || $isOwner);
  $a['can_delete_photo'] = $a['can_edit'];

  // アルバムZIPリンク（JSで使える）
  $a['zip_url'] = "album_api.php?action=download_album_zip&album_id=" . (int)$a['id'];
}

echo json_encode([
  'me' => [
    'role_id' => $role_id,
    'can_create' => $can_create,
  ],
  'albums' => $albums
], JSON_UNESCAPED_UNICODE);

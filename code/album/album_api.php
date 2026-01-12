<?php
declare(strict_types=1);

session_start();
require_once '../db.php';

/**
 * ✅ JSONにWarningが混ざるとJSが死ぬので、表示しない
 * （php.iniのdisplay_errorsがOnでもここでは出さない）
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

function jexit(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// ========================
// ログイン
// ========================
if (!isset($_SESSION['user'])) {
  jexit(['error' => 'unauthorized'], 401);
}

$role_id = (int)($_SESSION['user']['role_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);
if ($user_id <= 0) {
  jexit(['error' => 'unauthorized'], 401);
}

// 権限（例）
// 1 SYSTEM, 2 ADMIN, 3 PHOTO, 4 GENERAL
$can_create = in_array($role_id, [1,2,3,4], true);
$is_admin   = in_array($role_id, [1,2,3,4], true);
$is_general = ($role_id === 4);

// ========================
// パス（ここ超重要）
// ========================
$BASE_REAL = __DIR__ . '/../../img/albums/';  // ← C:\xampp\htdocs\share\img\albums\（シンボリックリンク）
$BASE_URL  = '/share/img/albums/';            // ← ブラウザURL

/**
 * ✅ 保存先が使えるかチェック
 * - シンボリックリンクが無い
 * - リンク先NASが落ちている
 * などはここで検出してJSONエラーで返す（Warningを混ぜない）
 */
function ensureStorage(string $baseReal): bool {
  // 親が無いなら作る（通常はshare/imgはあるはず）
  $parent = dirname(rtrim($baseReal, '/\\'));
  if (!is_dir($parent)) {
    @mkdir($parent, 0777, true);
  }

  if (!is_dir($baseReal)) {
    // リンクが無い・壊れてる等
    return false;
  }

  // 書き込みテスト（NAS死んでるとここで失敗することが多い）
  $test = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR . '.write_test_' . bin2hex(random_bytes(4));
  $ok = @file_put_contents($test, 'ok');
  if ($ok === false) return false;
  @unlink($test);
  return true;
}

if (!ensureStorage($BASE_REAL)) {
  jexit([
    'error' => 'storage_unavailable',
    'hint'  => 'C:\\xampp\\htdocs\\share\\img\\albums がシンボリックリンクで、リンク先(\\\\100.108.151.51\\hdd\\share\\img\\albums)にアクセスできるか確認して'
  ], 500);
}

// ========================
// 共通関数
// ========================
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
  if (!$items) return;

  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) rrmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

function detectMime(string $tmp): string {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  return $finfo->file($tmp) ?: 'application/octet-stream';
}

function isAllowedMedia(string $mime, string $ext): bool {
  $ext = strtolower($ext);
  $okImg = in_array($ext, ['jpg','jpeg','png','gif','webp'], true) && str_starts_with($mime, 'image/');
  $okVid = in_array($ext, ['mp4','mov','webm'], true) && str_starts_with($mime, 'video/');
  return $okImg || $okVid;
}

function mediaTypeFromExt(string $filename): string {
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  if (in_array($ext, ['mp4','mov','webm'], true)) return 'video/' . ($ext === 'mov' ? 'quicktime' : $ext);
  if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
  return 'application/octet-stream';
}

// ========================
// GET: 1件ダウンロード
// ========================
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

  $path = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $row['folder_key'] . DIRECTORY_SEPARATOR . $row['file_name'];
  if (!is_file($path)) { http_response_code(404); exit; }

  header_remove('Content-Type');
  header('Content-Type: application/octet-stream');

  $dl = $row['original_name'] ?: $row['file_name'];
  $dl = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/', '_', $dl);

  header('Content-Disposition: attachment; filename="' . rawurlencode($dl) . '"');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
}

// ========================
// GET: アルバムZIP
// ========================
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
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $zip = new ZipArchive();
  $tmp = tempnam(sys_get_temp_dir(), "alb_");
  $zipPath = $tmp . ".zip";
  @unlink($tmp);

  if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { http_response_code(500); exit; }

  $folder_key = (string)$a['folder_key'];
  foreach ($rows as $r) {
    $path = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $folder_key . DIRECTORY_SEPARATOR . $r['file_name'];
    if (is_file($path)) {
      $name = $r['original_name'] ?: $r['file_name'];
      $name = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/', '_', $name);
      $zip->addFile($path, $name);
    }
  }
  $zip->close();

  $name = ($a['title'] ?: 'album') . '.zip';
  $name = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/', '_', $name);

  header_remove('Content-Type');
  header("Content-Type: application/zip");
  header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
  header("Content-Length: " . filesize($zipPath));
  readfile($zipPath);
  @unlink($zipPath);
  exit;
}

// ========================
// POST: action
// ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // files[]（新）と images[]（旧）どっちでも受ける
  $fileKey = isset($_FILES['files']) ? 'files' : (isset($_FILES['images']) ? 'images' : null);

  // ---- アルバム作成（写真/動画 複数） ----
  if ($action === 'create_album') {
    if (!$can_create) jexit(['error' => 'no_create_permission'], 403);

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($title === '' || $description === '') jexit(['error' => 'title_description_required'], 400);
    if ($fileKey === null) jexit(['error' => 'files_required'], 400);

    $names = $_FILES[$fileKey]['name'] ?? [];
    $tmps  = $_FILES[$fileKey]['tmp_name'] ?? [];
    $errs  = $_FILES[$fileKey]['error'] ?? [];
    if (!is_array($names) || count($names) === 0) jexit(['error' => 'files_required'], 400);

    try {
      $pdo->beginTransaction();

      $base = safeFolderKey($title);
      $folder_key = $base . '_' . substr(bin2hex(random_bytes(6)), 0, 12);

      $stmt = $pdo->prepare("INSERT INTO albums (title, description, folder_key, created_by) VALUES (?, ?, ?, ?)");
      $stmt->execute([$title, $description, $folder_key, $user_id]);
      $album_id = (int)$pdo->lastInsertId();

      $albumReal = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $folder_key . DIRECTORY_SEPARATOR;
      if (!is_dir($albumReal)) {
        if (!@mkdir($albumReal, 0777, true)) {
          $pdo->rollBack();
          jexit(['error' => 'mkdir_failed', 'hint' => '保存先に作成できない（NAS/権限/リンク先）'], 500);
        }
      }

      $ins = $pdo->prepare("INSERT INTO album_photos (album_id, file_name, original_name) VALUES (?, ?, ?)");

      $savedCount = 0;
      for ($i = 0; $i < count($names); $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $orig = (string)$names[$i];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $tmp  = (string)$tmps[$i];
        $mime = detectMime($tmp);

        if (!isAllowedMedia($mime, $ext)) continue;

        $file = 'media_' . uniqid('', true) . '.' . $ext;
        $dst  = $albumReal . $file;

        if (!move_uploaded_file($tmp, $dst)) continue;

        $ins->execute([$album_id, $file, $orig]);
        $savedCount++;
      }

      if ($savedCount === 0) {
        rrmdir($albumReal);
        $pdo->rollBack();
        jexit(['error' => 'no_valid_files'], 400);
      }

      $pdo->commit();
      jexit(['ok' => true, 'saved' => $savedCount]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      jexit(['error' => 'server_error'], 500);
    }
  }

  // ---- アルバム編集 ----
  if ($action === 'update_album') {
    if ($is_general) jexit(['error' => 'no_edit_permission'], 403);

    $album_id = (int)($_POST['album_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($album_id <= 0 || $title === '' || $description === '') jexit(['error' => 'invalid_params'], 400);

    $stmt = $pdo->prepare("SELECT created_by FROM albums WHERE id=?");
    $stmt->execute([$album_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jexit(['error' => 'not_found'], 404);

    if (!$is_admin && (int)$row['created_by'] !== $user_id) jexit(['error' => 'not_owner'], 403);

    $stmt = $pdo->prepare("UPDATE albums SET title=?, description=? WHERE id=?");
    $stmt->execute([$title, $description, $album_id]);

    jexit(['ok' => true]);
  }

  // ---- 既存アルバムに追加（写真/動画） ----
  if ($action === 'add_photos') {
    if ($is_general) jexit(['error' => 'no_add_permission'], 403);

    $album_id = (int)($_POST['album_id'] ?? 0);
    if ($album_id <= 0) jexit(['error' => 'invalid_album_id'], 400);
    if ($fileKey === null) jexit(['error' => 'files_required'], 400);

    $stmt = $pdo->prepare("SELECT folder_key, created_by FROM albums WHERE id=?");
    $stmt->execute([$album_id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) jexit(['error' => 'not_found'], 404);
    if (!$is_admin && (int)$a['created_by'] !== $user_id) jexit(['error' => 'not_owner'], 403);

    $names = $_FILES[$fileKey]['name'] ?? [];
    $tmps  = $_FILES[$fileKey]['tmp_name'] ?? [];
    $errs  = $_FILES[$fileKey]['error'] ?? [];

    $folder_key = (string)$a['folder_key'];
    $albumReal = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $folder_key . DIRECTORY_SEPARATOR;
    if (!is_dir($albumReal)) {
      if (!@mkdir($albumReal, 0777, true)) {
        jexit(['error' => 'mkdir_failed'], 500);
      }
    }

    $ins = $pdo->prepare("INSERT INTO album_photos (album_id, file_name, original_name) VALUES (?, ?, ?)");

    $savedCount = 0;
    for ($i = 0; $i < count($names); $i++) {
      if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

      $orig = (string)$names[$i];
      $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      $tmp  = (string)$tmps[$i];
      $mime = detectMime($tmp);

      if (!isAllowedMedia($mime, $ext)) continue;

      $file = 'media_' . uniqid('', true) . '.' . $ext;
      $dst  = $albumReal . $file;

      if (!move_uploaded_file($tmp, $dst)) continue;

      $ins->execute([$album_id, $file, $orig]);
      $savedCount++;
    }

    jexit(['ok' => true, 'saved' => $savedCount]);
  }

  // ---- 写真/動画 1件削除 ----
  if ($action === 'delete_photo') {
    if ($is_general) jexit(['error' => 'no_delete_permission'], 403);

    $photo_id = (int)($_POST['photo_id'] ?? 0);
    if ($photo_id <= 0) jexit(['error' => 'invalid_photo_id'], 400);

    $stmt = $pdo->prepare("
      SELECT p.file_name, a.folder_key, a.created_by
      FROM album_photos p
      JOIN albums a ON a.id = p.album_id
      WHERE p.id = ?
      LIMIT 1
    ");
    $stmt->execute([$photo_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jexit(['error' => 'not_found'], 404);

    if (!$is_admin && (int)$row['created_by'] !== $user_id) jexit(['error' => 'not_owner'], 403);

    $real = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $row['folder_key'] . DIRECTORY_SEPARATOR . $row['file_name'];
    if (is_file($real)) @unlink($real);

    $pdo->prepare("DELETE FROM album_photos WHERE id=?")->execute([$photo_id]);

    jexit(['ok' => true]);
  }

  // ---- アルバム削除（フォルダごと） ----
  if ($action === 'delete_album') {
    if ($is_general) jexit(['error' => 'no_delete_permission'], 403);

    $album_id = (int)($_POST['album_id'] ?? 0);
    if ($album_id <= 0) jexit(['error' => 'invalid_album_id'], 400);

    $stmt = $pdo->prepare("SELECT folder_key, created_by FROM albums WHERE id=?");
    $stmt->execute([$album_id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) jexit(['error' => 'not_found'], 404);
    if (!$is_admin && (int)$a['created_by'] !== $user_id) jexit(['error' => 'not_owner'], 403);

    $folder_key = (string)$a['folder_key'];
    $albumReal  = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $folder_key . DIRECTORY_SEPARATOR;

    // DB削除（CASCADE）
    $pdo->prepare("DELETE FROM albums WHERE id=?")->execute([$album_id]);

    // 物理削除
    rrmdir($albumReal);

    jexit(['ok' => true]);
  }

  // ---- 選択ZIP（POSTでZIP返す） ----
  if ($action === 'download_selected_zip') {
    // ZIPなのでJSONヘッダ外す
    header_remove('Content-Type');

    $photoIds = $_POST['photo_ids'] ?? [];
    if (!is_array($photoIds) || count($photoIds) === 0) { http_response_code(400); exit; }

    $in = implode(',', array_fill(0, count($photoIds), '?'));
    $params = array_map('intval', $photoIds);

    $sql = "
      SELECT p.id, p.file_name, p.original_name, a.folder_key, a.created_by
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

    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { http_response_code(500); exit; }

    foreach ($rows as $r) {
      $path = rtrim($BASE_REAL, '/\\') . DIRECTORY_SEPARATOR . $r['folder_key'] . DIRECTORY_SEPARATOR . $r['file_name'];
      if (is_file($path)) {
        $name = $r['original_name'] ?: $r['file_name'];
        $name = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/', '_', $name);
        $zip->addFile($path, $name);
      }
    }

    $zip->close();

    header("Content-Type: application/zip");
    header('Content-Disposition: attachment; filename="selected_media.zip"');
    header("Content-Length: " . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
  }

  jexit(['error' => 'unknown_action'], 400);
}

// ========================
// GET：一覧（JSON）
// ========================
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
    $url = $BASE_URL . $folder_key . '/' . $p['file_name'];
    $p['media_url'] = $url;
    $p['media_type'] = mediaTypeFromExt((string)$p['file_name']);
    $p['download_url'] = "album_api.php?action=download_photo&photo_id=" . (int)$p['id'];
  }

  $a['photos'] = $list;

  $isOwner = ((int)$a['created_by'] === $user_id);
  $a['can_edit'] = (!$is_general) && ($is_admin || $isOwner);
  $a['can_delete'] = (!$is_general) && ($is_admin || $isOwner);
  $a['can_delete_photo'] = $a['can_edit'];
  $a['zip_url'] = "album_api.php?action=download_album_zip&album_id=" . $aid;
}

jexit([
  'me' => [
    'role_id' => $role_id,
    'can_create' => $can_create,
  ],
  'albums' => $albums
]);

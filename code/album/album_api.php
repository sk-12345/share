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
$can_create = in_array($role_id, [1,2,3], true);
$is_admin  = in_array($role_id, [1,2], true);
$is_general = ($role_id === 4);

$BASE_REAL = __DIR__ . '/../../../public/img/albums/';
$BASE_URL  = '/img/albums/';

if (!is_dir($BASE_REAL)) mkdir($BASE_REAL, 0777, true);

function safeFolderKey(string $title): string {
  // titleをベースにしつつ安全文字だけに（日本語は消えるので、ID等と合わせて使う）
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

    // PHPの複数アップ形式を扱いやすく
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

      // folder_key は「安全な文字 + uniq」で固定化
      $base = safeFolderKey($title);
      $folder_key = $base . '_' . substr(bin2hex(random_bytes(6)), 0, 12);

      $stmt = $pdo->prepare("INSERT INTO albums (title, description, folder_key, created_by) VALUES (?, ?, ?, ?)");
      $stmt->execute([$title, $description, $folder_key, $user_id]);
      $album_id = (int)$pdo->lastInsertId();

      $albumReal = $GLOBALS['BASE_REAL'] . $folder_key . '/';
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
        // 写真が1枚も保存できなかったらアルバムごと取り消し
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

    // 所有者チェック（ADMINは全部OK）
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

    // DB削除（CASCADEで写真も消える）
    $del = $pdo->prepare("DELETE FROM albums WHERE id=?");
    $del->execute([$album_id]);

    // ファイル削除
    rrmdir($albumReal);

    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
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
  }
  $a['photos'] = $list;

  // 編集/削除可（ADMINは全OK、PHOTOは自分のだけ、GENERALなし）
  $isOwner = ((int)$a['created_by'] === $user_id);
  $a['can_edit'] = (!$is_general) && ($is_admin || $isOwner);
  $a['can_delete'] = (!$is_general) && ($is_admin || $isOwner);
  $a['can_delete_photo'] = $a['can_edit'];
}

echo json_encode([
  'me' => [
    'role_id' => $role_id,
    'can_create' => $can_create,
  ],
  'albums' => $albums
], JSON_UNESCAPED_UNICODE);

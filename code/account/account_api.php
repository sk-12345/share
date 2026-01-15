<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=UTF-8');

// ✅ エラーを拾えるように
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ✅ ログイン必須
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$myId   = (int)($_SESSION['user']['id'] ?? 0);
$myRole = (int)($_SESSION['user']['role_id'] ?? 0); // 1=SYSTEM, 2=ADMIN, 3=PHOTO, 4=GENERAL

// ✅ SYSTEM / ADMIN 以外は見れない
if (!in_array($myRole, [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ 権限変更できるか（既存ルール）
function canChangeRole(int $myRole, int $myId, array $targetUser): bool {
    $targetId   = (int)($targetUser['id'] ?? 0);
    $targetRole = (int)($targetUser['role_id'] ?? 0);

    if ($myId === $targetId) return false;

    if ($myRole === 1) return $targetRole !== 1;                 // SYSTEMはSYSTEM以外OK
    if ($myRole === 2) return in_array($targetRole, [3, 4], true); // ADMINはPHOTO/GENERALのみ
    return false;
}

// ✅ 削除できるか（既存ルール）
function canDeleteUser(int $myRole, int $myId, array $targetUser): bool {
    $targetId   = (int)($targetUser['id'] ?? 0);
    $targetRole = (int)($targetUser['role_id'] ?? 0);

    if ($myId === $targetId) return false;

    if ($myRole === 1) return $targetRole !== 1;
    if ($myRole === 2) return in_array($targetRole, [3, 4], true);
    return false;
}

// ✅ パスワード変更できるか（SYSTEMはADMINも変更OK）
function canChangePassword(int $myRole, int $myId, array $targetUser): bool {
    $targetId   = (int)($targetUser['id'] ?? 0);
    $targetRole = (int)($targetUser['role_id'] ?? 0);

    if ($targetId <= 0) return false;

    // 運用上安全：自分自身は変更不可（必要なら true にしてOK）
    if ($myId === $targetId) return false;

    if ($myRole === 1) return true; // SYSTEM：自分以外は全員OK（ADMIN含む）
    if ($myRole === 2) return in_array($targetRole, [3, 4], true); // ADMIN：PHOTO/GENERALのみ
    return false;
}

// =========================
// POST：パスワード変更
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action !== 'change_password') {
        http_response_code(400);
        echo json_encode(['error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $targetId = (int)($_POST['user_id'] ?? 0);
    $newPass  = (string)($_POST['new_password'] ?? '');

    if ($targetId <= 0 || $newPass === '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_params'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($newPass) < 4) {
        http_response_code(400);
        echo json_encode(['error' => 'password_too_short'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 対象取得
    $st = $pdo->prepare("SELECT id, role_id FROM users WHERE id = ?");
    $st->execute([$targetId]);
    $target = $st->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!canChangePassword($myRole, $myId, $target)) {
        http_response_code(403);
        echo json_encode(['error' => 'cannot_change_this_user'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hash = password_hash($newPass, PASSWORD_DEFAULT);

    // ※ usersテーブルのカラム名が違うならここを合わせて！
    $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $up->execute([$hash, $targetId]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================
// GET：一覧取得
// =========================
try {
    // roles
    $rolesStmt = $pdo->query("SELECT id, role_name FROM roles ORDER BY id");
    $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

    // users + roles
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.login_id,
            u.name,
            u.role_id,
            r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        ORDER BY u.id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 自分の権限で「変更先の候補」
    $selectableIds = ($myRole === 1) ? [2, 3, 4] : [3, 4];

    // フロント用フラグ付与
    foreach ($users as &$u) {
        $u['can_change'] = canChangeRole($myRole, $myId, $u);
        $u['can_delete'] = canDeleteUser($myRole, $myId, $u);
        $u['can_change_password'] = canChangePassword($myRole, $myId, $u);
    }
    unset($u);

    echo json_encode([
        'me' => [
            'id' => $myId,
            'role_id' => $myRole,
            'selectable_role_ids' => $selectableIds
        ],
        'roles' => $roles,
        'users' => $users
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'db_error',
        'detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

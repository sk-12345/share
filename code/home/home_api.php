<?php
session_start();

require_once '../db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit();
}

$user = $_SESSION['user'];

$roleId = isset($user['role_id']) ? (int)$user['role_id'] : 0;

/**
 * ✅ rolesテーブルから「id => role_name」の配列を作る
 */
function getRoleMap(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, role_name FROM roles");
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = (string)$row['role_name'];
    }
    return $map;
}

$roleMap  = getRoleMap($pdo);              // ★先に作る

$roleName = $roleMap[$roleId] ?? '不明';
$isAdminOrSystem = in_array($roleId, [1, 2], true);

echo json_encode([
    'user' => [
        'fullname' => $user['fullname'] ?? '',
        'role_id' => $roleId,
        'role_name' => $roleName
    ],
    'flags' => [
        'is_admin_or_system' => $isAdminOrSystem
    ]
], JSON_UNESCAPED_UNICODE);

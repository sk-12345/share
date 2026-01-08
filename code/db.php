<?php
// ★自分の環境に合わせてここだけ書き換える
$db_host = '127.0.0.1';          // そのままでOK
$db_name = 'share';          // つくったデータベース名
$db_user = 'root';               // ユーザー名
$db_pass = 'kmkr3110';  // ←インストール時に決めたパスワード

$dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo 'DB接続エラー: ' . $e->getMessage();
    exit;
}

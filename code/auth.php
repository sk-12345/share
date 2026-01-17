<?php
declare(strict_types=1);

// ★ ここ！！（session_startより前）
session_name('PHOTO_APP_SESSION');

session_start();

// 10分（秒）
$TIMEOUT = 10 * 60;

// 未ログイン
if (!isset($_SESSION['user'])) {
    header("Location: ../login/login.php");
    exit;
}

// 最終操作時刻がある＆10分超え
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $TIMEOUT) {

    // セッション破棄 = ログアウト
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: ../login/login.php?timeout=1");
    exit;
}

// 今の時刻を更新
$_SESSION['last_activity'] = time();

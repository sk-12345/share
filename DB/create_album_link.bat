@echo off
chcp 65001 > nul

echo ================================
echo アルバム保存先 シンボリックリンク作成
echo ================================

REM 管理者権限チェック
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo.
    echo ❌ 管理者権限で実行してください
    echo 右クリック →「管理者として実行」
    pause
    exit /b 1
)

REM 設定値
set LINK_PATH=C:\xampp\htdocs\share\img\albums
set TARGET_PATH=\\100.108.151.51\hdd\share\img\albums

echo.
echo [LINK]   %LINK_PATH%
echo [TARGET] %TARGET_PATH%
echo.

REM 既存フォルダ・リンク確認
if exist "%LINK_PATH%" (
    echo ⚠ 既に存在します
    echo 削除して作り直しますか？ [Y/N]
    choice /c YN /n
    if errorlevel 2 (
        echo 中止しました
        pause
        exit /b 0
    )
    echo 削除中...
    rmdir "%LINK_PATH%" >nul 2>&1
)

REM シンボリックリンク作成
mklink /D "%LINK_PATH%" "%TARGET_PATH%"
if %errorlevel% neq 0 (
    echo.
    echo ❌ シンボリックリンク作成に失敗しました
    pause
    exit /b 1
)

echo.
echo ✅ 作成完了！
echo %LINK_PATH%
echo   → %TARGET_PATH%
echo.
pause

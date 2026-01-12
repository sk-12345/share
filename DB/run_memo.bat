@echo off
chcp 932 >nul
setlocal enabledelayedexpansion

REM ==========================================================
REM  share SQL Runner（今のフォルダ構成専用）
REM
REM  ▼フォルダ構成はこのまま
REM   DB\    ← DB作成系SQLを置く
REM   TABLE\ ← テーブル作成系SQLを置く
REM   VALES\ ← INSERT/UPDATE/DELETEなどデータ系SQLを置く
REM
REM  ▼実行する内容は run_memo.txt で指定
REM ==========================================================

REM =====================【設定ゾーン】ここだけ =====================
REM mysql.exe の場所
set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"

REM DB接続情報
set "DB_HOST=localhost"
set "DB_USER=root"
set "DB_PASS="

REM "C:\xampp\htdocs\share\DB\all.bat"
set "ROOT=%~dp0"

REM "C:\xampp\htdocs\share\DB\all.bat"
set "MEMO_FILE=%ROOT%run_memo.txt"
REM =================================================================

REM ---- mysql.exe チェック
if not exist "%MYSQL_EXE%" (
  echo [ERROR] mysql.exe が見つかりません: %MYSQL_EXE%
  pause
  exit /b 1
)

REM ---- メモファイルチェック
if not exist "%MEMO_FILE%" (
  echo [ERROR] run_memo.txt が見つかりません: %MEMO_FILE%
  pause
  exit /b 1
)

REM ==========================================================
REM  run_memo.txt から MODE / ARG を読み取る
REM ==========================================================
set "MODE="
set "ARG="

for /f "usebackq tokens=1,* delims==" %%A in ("%MEMO_FILE%") do (
  if /i "%%A"=="MODE" set "MODE=%%B"
  if /i "%%A"=="ARG"  set "ARG=%%B"
)

echo MODE=%MODE%
echo ARG=%ARG%
echo.

REM ==========================================================
REM  MODE 別に処理する
REM ==========================================================
if /i "%MODE%"=="all" (
  call :RUN_FOLDER "DB"
  if errorlevel 1 goto :END
  call :RUN_FOLDER "TABLE"
  if errorlevel 1 goto :END
  call :RUN_FOLDER "VALES"
  goto :END
)

if /i "%MODE%"=="folder" (
  call :RUN_FOLDER "%ARG%"
  goto :END
)

if /i "%MODE%"=="file" (
  call :RUN_FILE "%ROOT%%ARG%"
  goto :END
)

if /i "%MODE%"=="path" (
  call :RUN_FILE "%ARG%"
  goto :END
)

if /i "%MODE%"=="pattern" (
  call :RUN_PATTERN "%ROOT%%ARG%"
  goto :END
)

echo [ERROR] MODE が不正です（all/folder/file/pattern/path）
pause
exit /b 1


REM ==========================================================
REM  フォルダ内の *.txt を名前順で全部実行
REM ==========================================================
:RUN_FOLDER
set "FOLDER=%~1"
set "TARGET=%ROOT%%FOLDER%"

if "%FOLDER%"=="" (
  echo [ERROR] folder 指定なのに ARG が空です
  exit /b 1
)

if not exist "%TARGET%" (
  echo [ERROR] フォルダが存在しません: %TARGET%
  exit /b 1
)

echo -------------------------------
echo [FOLDER] %FOLDER%
echo -------------------------------

set "FOUND=0"
for /f "delims=" %%F in ('dir /b /on "%TARGET%\*.txt" 2^>nul') do (
  set "FOUND=1"
  call :RUN_FILE "%TARGET%\%%F"
  if errorlevel 1 exit /b 1
)

if "!FOUND!"=="0" (
  echo [WARN] %FOLDER% に .txt が見つかりませんでした
)

exit /b 0


REM ==========================================================
REM  ワイルドカード実行（例: TABLE\*.txt）
REM ==========================================================
:RUN_PATTERN
set "PATT=%~1"

echo -------------------------------
echo [PATTERN] %PATT%
echo -------------------------------

set "FOUND=0"
for /f "delims=" %%F in ('dir /b /on "%PATT%" 2^>nul') do (
  set "FOUND=1"
  call :RUN_FILE "%%~fF"
  if errorlevel 1 exit /b 1
)

if "!FOUND!"=="0" (
  echo [WARN] パターンに一致するファイルがありません
)

exit /b 0


REM ==========================================================
REM  1ファイル実行（mysql に流し込み）
REM ==========================================================
:RUN_FILE
set "FILE=%~1"

if not exist "%FILE%" (
  echo [ERROR] ファイルが存在しません: %FILE%
  exit /b 1
)

echo [RUN ] %FILE%

if "%DB_PASS%"=="" (
  "%MYSQL_EXE%" -h "%DB_HOST%" -u "%DB_USER%" < "%FILE%"
) else (
  "%MYSQL_EXE%" -h "%DB_HOST%" -u "%DB_USER%" -p%DB_PASS% < "%FILE%"
)

if errorlevel 1 (
  echo [ERROR] SQL実行失敗: %FILE%
  pause
  exit /b 1
)

echo [OK  ] 完了
exit /b 0


:END
echo.
echo [DONE] 実行完了
pause
exit /b 0

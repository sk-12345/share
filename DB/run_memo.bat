@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

REM ==========================================================
REM  Nishijuku SQL Runner
REM
REM   DB\       : DB作成などのSQL（.txt）
REM   TABLE\    : テーブル作成などのSQL（.txt）
REM   VALES\    : INSERT/UPDATE/DELETE などのSQL（.txt）
REM
REM   実行モードは run_memo.txt で指定
REM ==========================================================

REM ===================== 設定 =====================
REM mysql.exe の場所
set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"

REM DB接続情報
set "DB_HOST=localhost"
set "DB_USER=root"
set "DB_PASS="

REM このbatのある場所
set "ROOT=%~dp0"

REM 実行指示ファイル
set "MEMO_FILE=%ROOT%run_memo.txt"
REM =================================================

REM ---- mysql.exe の存在チェック
if not exist "%MYSQL_EXE%" (
  echo [ERROR] mysql.exe が見つかりません: %MYSQL_EXE%
  pause
  exit /b 1
)

REM ---- run_memo.txt の存在チェック
if not exist "%MEMO_FILE%" (
  echo [ERROR] run_memo.txt が見つかりません: %MEMO_FILE%
  pause
  exit /b 1
)

REM ==========================================================
REM  run_memo.txt から MODE / ARG を読み取り
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
REM  MODE に応じて実行
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
REM  フォルダ内の *.txt をファイル名順に全実行
REM ==========================================================
:RUN_FOLDER
set "FOLDER=%~1"
set "TARGET=%ROOT%%FOLDER%"

if "%FOLDER%"=="" (
  echo [ERROR] folder モードなのに ARG が空です
  exit /b 1
)

if not exist "%TARGET%" (
  echo [ERROR] フォルダが見つかりません: %TARGET%
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
  echo [WARN] %FOLDER% に .txt がありません
)

exit /b 0


REM ==========================================================
REM  パターン指定で複数ファイル実行（例: TABLE\CREATE_*.txt）
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
REM  1ファイル実行（mysqlに流し込み）
REM ==========================================================
:RUN_FILE
set "FILE=%~1"

if not exist "%FILE%" (
  echo [ERROR] ファイルが見つかりません: %FILE%
  exit /b 1
)

echo [RUN ] %FILE%

REM ここが文字化け対策の本丸：
REM  - コンソールはUTF-8 (chcp 65001)
REM  - mysqlにデフォルト文字コードを指定
REM  - 可能ならSQLファイル自体もUTF-8で保存
if "%DB_PASS%"=="" (
  "%MYSQL_EXE%" -h "%DB_HOST%" -u "%DB_USER%" --default-character-set=utf8mb4 < "%FILE%"
) else (
  "%MYSQL_EXE%" -h "%DB_HOST%" -u "%DB_USER%" -p%DB_PASS% --default-character-set=utf8mb4 < "%FILE%"
)

if errorlevel 1 (
  echo [ERROR] SQL実行に失敗しました: %FILE%
  pause
  exit /b 1
)

echo [OK  ] 完了
exit /b 0


:END
echo.
echo [DONE] 実行終了
pause
exit /b 0

@echo off
:: ============================================================
::  ERP RAG Microservice - Windows Startup Script
::  Pure Python 3.14 compatible - no C/Rust compiler needed
:: ============================================================

echo.
echo  ====================================================
echo   ERP Helpdesk RAG Service v2.1
echo   Pure Python - No compiler required
echo  ====================================================
echo.

cd /d "%~dp0"

python --version >nul 2>&1
if errorlevel 1 (
    echo  [ERROR] Python not found on PATH.
    echo  Make sure Python is installed and accessible.
    pause
    exit /b 1
)

echo  Installing dependencies (pure Python only)...
python -m pip install -r requirements.txt --quiet
if errorlevel 1 (
    echo  [ERROR] Dependency installation failed.
    pause
    exit /b 1
)
echo  Dependencies OK.
echo.

echo  Starting FastAPI RAG service on http://127.0.0.1:8000 ...
echo  API docs : http://127.0.0.1:8000/docs
echo  Press Ctrl+C to stop.
echo.

python -m uvicorn main:app --host 127.0.0.1 --port 8000 --reload

pause

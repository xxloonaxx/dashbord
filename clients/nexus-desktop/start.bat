@echo off
REM GHOST NODE - VRC Nexus Client ohne EXE starten (braucht Python 3.9+ von python.org)
cd /d "%~dp0"
where pythonw >nul 2>nul && (start "" pythonw ghostnode_nexus.py %*) || (python ghostnode_nexus.py %*)

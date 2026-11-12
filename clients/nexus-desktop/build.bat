@echo off
REM Baut GhostNodeNexus.exe (eine Datei, ohne Konsole) mit PyInstaller.
REM Voraussetzung: Python 3.9+ von python.org (Haken bei "Add to PATH").
cd /d "%~dp0"
python -m pip install --upgrade pyinstaller || goto :fehler
python -m unittest discover -s tests || goto :fehler
python -m PyInstaller --noconfirm --onefile --windowed --name GhostNodeNexus --icon icon.ico ghostnode_nexus.py || goto :fehler
echo.
echo Fertig: dist\GhostNodeNexus.exe
pause
exit /b 0
:fehler
echo.
echo Build fehlgeschlagen.
pause
exit /b 1

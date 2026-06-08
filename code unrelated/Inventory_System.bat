@echo off
title Inventory System Server
echo Starting the Inventory System Server...
echo DO NOT CLOSE THIS WINDOW WHILE USING THE SYSTEM
echo --------------------------------------------------
cd "C:\Users\user\Desktop\Inventory System"
start "" "http://localhost:3000/index.php"
"C:\Users\user\Desktop\Inventory System\php-8.5.7-Win32-vs17-x64\php.exe" -S localhost:3000
pause
@echo off
REM ============================================================
REM  SAGA - Sincronizacion automatica del calendario SENA
REM  Ejecutado por el Programador de tareas de Windows.
REM  Descarga los Google Sheets de las fichas activas e importa
REM  las ocupaciones (idempotente). No requiere intervencion.
REM ============================================================
"C:\xampp\php\php.exe" "C:\xampp\htdocs\SAGA - V2\cron\sincronizar_google.php" --todas >> "C:\xampp\htdocs\SAGA - V2\cron\sync.log" 2>&1

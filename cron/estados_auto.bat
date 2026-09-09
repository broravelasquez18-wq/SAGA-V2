@echo off
REM ============================================================
REM  SAGA - Automatizacion de estados de ocupacion
REM  Ejecutado por el Programador de tareas de Windows.
REM  Marca como 'finalizado' las ocupaciones cuya hora ya paso.
REM  Recomendado: repetir cada 1-2 minutos. No requiere intervencion.
REM ============================================================
"C:\xampp\php\php.exe" "C:\xampp\htdocs\SAGA - V2\cron\actualizar_estados.php" >> "C:\xampp\htdocs\SAGA - V2\cron\estados.log" 2>&1

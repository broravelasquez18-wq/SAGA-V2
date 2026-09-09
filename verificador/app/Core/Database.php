<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Singleton de conexión PDO a MySQL. Siempre en modo excepción y con
 * sentencias preparadas reales (no emuladas), lo que hace obligatorio el uso
 * de placeholders en todo el proyecto.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    public static function conexion(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        Env::cargar();

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '3306');
        $nombre = Env::get('DB_NAME');
        $usuario = Env::get('DB_USER');
        $clave = Env::get('DB_PASS', '');

        if ($nombre === null || $usuario === null) {
            self::fallar('Faltan variables de entorno DB_NAME / DB_USER.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $nombre);

        try {
            self::$pdo = new PDO($dsn, $usuario, $clave, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Error de conexión a la base de datos: ' . $e->getMessage());
            self::fallar('No se pudo conectar a la base de datos.');
        }

        return self::$pdo;
    }

    private static function fallar(string $mensaje): never
    {
        http_response_code(500);
        error_log($mensaje);
        exit('Error de configuración del servidor.');
    }
}

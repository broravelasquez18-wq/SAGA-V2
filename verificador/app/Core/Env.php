<?php

namespace App\Core;

/**
 * Carga variables desde un archivo `.env` (si existe) hacia el entorno del
 * proceso. En producción se pueden setear las variables reales (Dokploy /
 * docker-compose) y este loader simplemente no encuentra el archivo.
 */
final class Env
{
    private static bool $cargado = false;

    public static function cargar(?string $ruta = null): void
    {
        if (self::$cargado) {
            return;
        }
        self::$cargado = true;

        $ruta ??= dirname(__DIR__, 2) . '/.env';
        if (!is_readable($ruta)) {
            return;
        }

        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }

            [$clave, $valor] = explode('=', $linea, 2);
            $clave = trim($clave);
            $valor = trim($valor, " \t\n\r\0\x0B\"'");

            if ($clave !== '' && getenv($clave) === false) {
                putenv("$clave=$valor");
                $_ENV[$clave] = $valor;
            }
        }
    }

    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        $valor = getenv($clave);
        return $valor === false ? $porDefecto : $valor;
    }
}

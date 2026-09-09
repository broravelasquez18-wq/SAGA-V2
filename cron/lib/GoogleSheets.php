<?php
/**
 * GoogleSheets — descarga un documento de Google Sheets como .xlsx.
 *
 * Funciona cuando el documento es accesible sin login:
 *   - "Cualquiera con el enlace puede ver", o
 *   - "Publicar en la web".
 * Si el documento es privado, Google responde con una página HTML de login
 * (no un .xlsx); este helper lo detecta y devuelve un error claro.
 *
 * Para documentos privados con cuenta de servicio, se añadiría el token
 * OAuth en la cabecera Authorization (pendiente de configuración).
 */

class GoogleSheets
{
    /** Extrae el ID del documento desde una URL o lo devuelve tal cual. */
    public static function extraerId(string $urlOId): string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $urlOId, $m)) {
            return $m[1];
        }
        return trim($urlOId);
    }

    public static function urlExport(string $sheetId): string
    {
        return "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=xlsx";
    }

    /**
     * Devuelve una ruta temporal única terminada en .xlsx SIN dejar basura.
     *
     * tempnam() crea físicamente un archivo sin extensión; el patrón antiguo
     * "tempnam(...) . '.xlsx'" dejaba ese archivo huérfano en cada llamada
     * (en Windows, con nombre tipo "sagXXXX.tmp"). Aquí renombramos el archivo
     * recién creado a .xlsx para que PhpSpreadsheet detecte el formato y no se
     * acumulen temporales de 0 bytes.
     */
    public static function rutaTemporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'saga_');
        if ($base === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal.');
        }
        $xlsx = $base . '.xlsx';
        if (@rename($base, $xlsx)) {
            return $xlsx;
        }
        // Si el rename falla, no dejamos el archivo base colgado.
        @unlink($base);
        return $xlsx; // descargarXlsx() lo creará con fopen('wb').
    }

    /**
     * Descarga el Sheet a $destino. Lanza RuntimeException si no es accesible.
     * @param string|null $token  Token OAuth (cuenta de servicio), opcional.
     */
    public static function descargarXlsx(string $sheetId, string $destino, ?string $token = null): void
    {
        $url = self::urlExport($sheetId);

        $ch = curl_init($url);
        $fh = fopen($destino, 'wb');
        if (!$fh) {
            throw new RuntimeException("No se pudo escribir el archivo temporal: $destino");
        }

        $headers = ['Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,   // Google redirige el export
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FILE           => $fh,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SAGA-Sync/1.0',
        ]);

        $ok       = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype    = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $errCurl  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            @unlink($destino);
            throw new RuntimeException("Error de conexión con Google: $errCurl");
        }

        // Si Google devolvió HTML => documento privado / sin permiso.
        $esHtml = stripos($ctype, 'text/html') !== false;
        if ($httpCode !== 200 || $esHtml) {
            @unlink($destino);
            throw new RuntimeException(
                "El Google Sheet no es accesible (HTTP $httpCode). " .
                "Debe estar compartido como 'Cualquiera con el enlace puede ver' " .
                "o 'Publicado en la web'. Si debe seguir privado, hay que usar cuenta de servicio."
            );
        }

        // Sanidad: un .xlsx real empieza con la firma ZIP "PK".
        $firma = file_get_contents($destino, false, null, 0, 2);
        if ($firma !== 'PK') {
            @unlink($destino);
            throw new RuntimeException("La respuesta de Google no es un archivo .xlsx válido.");
        }
    }
}

<?php
/**
 * ParserCalendario — lee el calendario de formación del SENA (formato
 * "PROGRAMADOR SESIONES") desde un .xlsx y devuelve las ocupaciones.
 *
 * Layout:
 *   - Meses en horizontal, bloques de 7 columnas: [HORARIO][Lun..Sab].
 *   - Semanas en vertical: fila de fechas + hasta 8 filas de franjas horarias.
 *   - Cada celda de clase = "INSTRUCTOR - COMPETENCIA".
 *   - "FESTIVO"/vacías se ignoran; "ALISTAMIENTO"/notas van a $otros.
 *
 * Requiere PhpSpreadsheet (ya es dependencia de SAGA).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ParserCalendario
{
    /** Valores que nunca son una clase. */
    private const IGNORAR = [
        '', 'FESTIVO', 'HORARIO', 'NO HAY FORMACION', 'NO HAY FORMACIÓN',
        'PROGRAMADOR SESIONES FORMATIVAS', 'PROGRAMADOR SESION',
    ];

    public array $asignaciones = []; // por hora: fecha, hi, hf, instructor, competencia
    public array $otros = [];         // celdas con texto que no son clase
    public array $meta = [];

    /** Parisea un archivo .xlsx y llena $asignaciones/$otros/$meta. */
    public function parseArchivo(string $ruta): void
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(false); // necesitamos saber si una celda es fecha
        $spreadsheet = $reader->load($ruta);
        $ws = $spreadsheet->getActiveSheet();
        $this->parseHoja($ws);
    }

    private static function norm($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string)$v));
    }

    /**
     * Valor EFECTIVO de una celda: si es una fórmula devuelve el valor
     * cacheado en el archivo (equivalente a data_only de openpyxl), sin
     * recalcular. Si no es fórmula, el valor literal.
     */
    private function valor($cell)
    {
        $v = $cell->getValue();
        if (is_string($v) && isset($v[0]) && $v[0] === '=') {
            return $cell->getOldCalculatedValue();
        }
        return $v;
    }

    private static function enIgnorar(string $texto): bool
    {
        $u = mb_strtoupper($texto);
        foreach (self::IGNORAR as $x) {
            if ($u === mb_strtoupper($x)) return true;
        }
        return false;
    }

    private function parseHoja($ws): void
    {
        $maxRow = $ws->getHighestRow();
        $maxCol = Coordinate::columnIndexFromString($ws->getHighestColumn());

        // ---- Metadatos: ficha / programa (primeras filas) ----
        $ficha = $programa = null;
        for ($r = 1; $r <= 8; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $val = $this->valor($ws->getCell([$c, $r]));
                if (!is_string($val)) continue;
                $t = mb_strtolower(trim($val));
                if (strpos($t, 'ficha') === 0) {
                    $nxt = $this->valor($ws->getCell([$c + 1, $r]));
                    if ($nxt) $ficha = self::norm($nxt);
                } elseif (strpos($t, 'programa') === 0) {
                    $nxt = $this->valor($ws->getCell([$c + 1, $r]));
                    if ($nxt) $programa = self::norm($nxt);
                }
            }
        }

        // ---- Detectar el inicio de cada bloque de mes (columnas "HORARIO") ----
        $bloques = [];
        $c = 1;
        while ($c <= $maxCol) {
            $esHorario = false;
            $hasta = min($maxRow, 40);
            for ($r = 5; $r <= $hasta; $r++) {
                $v = $this->valor($ws->getCell([$c, $r]));
                if (is_string($v) && strtoupper(trim($v)) === 'HORARIO') { $esHorario = true; break; }
            }
            if ($esHorario) { $bloques[] = $c; $c += 7; }
            else            { $c += 1; }
        }

        // ---- Recorrer cada bloque de mes ----
        foreach ($bloques as $c0) {
            $fechasCol = [1=>null,2=>null,3=>null,4=>null,5=>null,6=>null];
            for ($r = 1; $r <= $maxRow; $r++) {
                $etiqueta = $this->valor($ws->getCell([$c0, $r]));

                // ¿Fila de encabezado de semana? (alguna celda de día es fecha)
                $hayFecha = false;
                $posibles = [];
                for ($j = 1; $j <= 6; $j++) {
                    $cell = $ws->getCell([$c0 + $j, $r]);
                    $cv = $this->valor($cell);
                    if (XlsDate::isDateTime($cell) && is_numeric($cv)) {
                        $dto = XlsDate::excelToDateTimeObject($cv);
                        $posibles[$j] = $dto->format('Y-m-d');
                        $hayFecha = true;
                    }
                }
                if ($hayFecha) {
                    for ($j = 1; $j <= 6; $j++) $fechasCol[$j] = $posibles[$j] ?? null;
                    continue;
                }

                // ¿Fila de franja horaria? "12:00 - 13:00"
                if (!is_string($etiqueta) ||
                    !preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $etiqueta, $m)) {
                    continue;
                }
                $hi = sprintf('%02d:%s', (int)$m[1], $m[2]);
                $hf = sprintf('%02d:%s', (int)$m[3], $m[4]);

                for ($j = 1; $j <= 6; $j++) {
                    $fecha = $fechasCol[$j];
                    if (!$fecha) continue;
                    $val = $this->valor($ws->getCell([$c0 + $j, $r]));
                    if (!is_string($val)) continue;
                    $texto = self::norm($val);
                    if (self::enIgnorar($texto)) continue;

                    // Una clase real SIEMPRE trae "INSTRUCTOR - COMPETENCIA".
                    if (strpos($texto, ' - ') === false) {
                        $this->otros[] = ['fecha'=>$fecha, 'hi'=>$hi, 'hf'=>$hf, 'texto'=>$texto];
                        continue;
                    }
                    [$instructor, $competencia] = explode(' - ', $texto, 2);
                    $instructor = self::norm($instructor);
                    $competencia = self::norm($competencia);
                    if (self::enIgnorar($instructor)) continue;

                    $this->asignaciones[] = [
                        'fecha'=>$fecha, 'hi'=>$hi, 'hf'=>$hf,
                        'instructor'=>$instructor, 'competencia'=>$competencia,
                    ];
                }
            }
        }

        $this->meta = [
            'ficha' => $ficha,
            'programa' => $programa,
            'bloques_mes' => count($bloques),
            'asignaciones_hora' => count($this->asignaciones),
            'otros_no_clase' => count($this->otros),
        ];
    }

    /** Une franjas contiguas del mismo día+instructor+competencia en un bloque. */
    public function agrupar(): array
    {
        $asg = $this->asignaciones;
        usort($asg, function ($a, $b) {
            return [$a['fecha'],$a['instructor'],$a['competencia'],$a['hi']]
               <=> [$b['fecha'],$b['instructor'],$b['competencia'],$b['hi']];
        });

        $ocup = [];
        $actual = null;
        foreach ($asg as $a) {
            if ($actual
                && $a['fecha'] === $actual['fecha']
                && $a['instructor'] === $actual['instructor']
                && $a['competencia'] === $actual['competencia']
                && $a['hi'] === $actual['hf']) {           // contigua
                $actual['hf'] = $a['hf'];
            } else {
                if ($actual) $ocup[] = $actual;
                $actual = $a;
            }
        }
        if ($actual) $ocup[] = $actual;
        return $ocup;
    }
}

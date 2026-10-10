<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}

require_once "../config/conexion.php";
require_once "../config/csrf.php";
$con = conexion();

csrf_validate();

$sede_id = isset($_POST['sede_id']) ? intval($_POST['sede_id']) : 0;

if($sede_id <= 0) {
    header("Location: ../views/admin/carga_masiva_celadores.php?sede_id=1&error=sin_sede");
    exit();
}

if(!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    header("Location: ../views/admin/carga_masiva_celadores.php?sede_id=$sede_id&error=sin_archivo");
    exit();
}

$archivo   = $_FILES['archivo'];
$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

if(!in_array($extension, ['xlsx', 'xls', 'csv'])) {
    header("Location: ../views/admin/carga_masiva_celadores.php?sede_id=$sede_id&error=formato_invalido");
    exit();
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    $spreadsheet = IOFactory::load($archivo['tmp_name']);
    $sheet       = $spreadsheet->getActiveSheet();
    $highestRow  = $sheet->getHighestRow();

    if($highestRow < 2) {
        header("Location: ../views/admin/carga_masiva_celadores.php?sede_id=$sede_id&error=archivo_vacio");
        exit();
    }

    // Leer headers
    $headers     = [];
    $col_letters = ['A','B','C','D','E','F','G','H'];
    foreach($col_letters as $col) {
        $val = trim(strtolower($sheet->getCell($col . '1')->getValue()));
        if($val !== '') $headers[$col] = $val;
    }

    // Columnas obligatorias
    $requeridas = ['cedula', 'nombre', 'apellido', 'email', 'contraseña', 'tipo_contrato'];
    foreach($requeridas as $r) {
        if(!in_array($r, $headers)) {
            header("Location: ../views/admin/carga_masiva_celadores.php?sede_id=$sede_id&error=columnas_faltantes");
            exit();
        }
    }

    // Mapear columnas
    $col_cedula       = array_search('cedula',                $headers);
    $col_nombre       = array_search('nombre',                $headers);
    $col_apellido     = array_search('apellido',              $headers);
    $col_email        = array_search('email',                 $headers);
    $col_contrasena   = array_search('contraseña',            $headers);
    $col_tipo         = array_search('tipo_contrato',         $headers);
    $col_fecha_inicio = array_search('fecha_inicio_contrato', $headers);
    $col_fecha_fin    = array_search('fecha_fin_contrato',    $headers);

    $creados  = 0;
    $fallidos = 0;

    for($row = 2; $row <= min($highestRow, 202); $row++) {
        $cedula     = trim($sheet->getCell($col_cedula     . $row)->getValue());
        $nombre     = trim($sheet->getCell($col_nombre     . $row)->getValue());
        $apellido   = trim($sheet->getCell($col_apellido   . $row)->getValue());
        $email      = trim($sheet->getCell($col_email      . $row)->getValue());
        $contrasena = trim($sheet->getCell($col_contrasena . $row)->getValue());
        $tipo       = trim(strtolower($sheet->getCell($col_tipo . $row)->getValue()));
        $fi_raw     = $col_fecha_inicio ? trim($sheet->getCell($col_fecha_inicio . $row)->getFormattedValue()) : '';
        $ff_raw     = $col_fecha_fin    ? trim($sheet->getCell($col_fecha_fin    . $row)->getFormattedValue()) : '';

        // Validar campos obligatorios
        if(empty($cedula) || empty($nombre) || empty($apellido) || empty($email) || empty($contrasena) || empty($tipo)) {
            $fallidos++;
            continue;
        }

        // Validar tipo de contrato
        if(!in_array($tipo, ['planta', 'contratista'])) {
            $fallidos++;
            continue;
        }

        // Validar email
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fallidos++;
            continue;
        }

        // Fechas para contratistas
        $fecha_inicio_contrato = null;
        $fecha_fin_contrato    = null;

        if($tipo == 'contratista') {
            foreach([&$fi_raw, &$ff_raw] as &$val) {
                if(is_numeric($val) && strlen((string)intval($val)) <= 5) {
                    try {
                        $val = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val)->format('Y-m-d');
                    } catch(Exception $e) {
                        $val = '';
                    }
                }
            }
            unset($val);

            if(empty($fi_raw) || empty($ff_raw)) { $fallidos++; continue; }

            $fi_obj = DateTime::createFromFormat('Y-m-d', $fi_raw);
            $ff_obj = DateTime::createFromFormat('Y-m-d', $ff_raw);

            if(!$fi_obj || $fi_obj->format('Y-m-d') !== $fi_raw || !$ff_obj || $ff_obj->format('Y-m-d') !== $ff_raw) {
                $fallidos++;
                continue;
            }
            if($ff_raw <= $fi_raw) { $fallidos++; continue; }

            $fecha_inicio_contrato = $fi_raw;
            $fecha_fin_contrato    = $ff_raw;
        }

        // Verificar duplicados
        $cedula_esc = mysqli_real_escape_string($con, $cedula);
        $email_esc  = mysqli_real_escape_string($con, $email);

        $dup = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula = '$cedula_esc' OR email = '$email_esc' LIMIT 1");
        if(mysqli_num_rows($dup) > 0) { $fallidos++; continue; }

        // Preparar valores
        $nombre_esc   = mysqli_real_escape_string($con, $nombre);
        $apellido_esc = mysqli_real_escape_string($con, $apellido);
        $tipo_esc     = mysqli_real_escape_string($con, $tipo);
        if (strlen($contrasena) > 72 || str_contains($contrasena, "\0")) { $fallidos++; continue; }
        $pass_esc = password_hash($contrasena, PASSWORD_DEFAULT);

        $fi_val = $fecha_inicio_contrato ? "'$fecha_inicio_contrato'" : 'NULL';
        $ff_val = $fecha_fin_contrato    ? "'$fecha_fin_contrato'"    : 'NULL';

        $insert = "INSERT INTO usuarios
                   (cedula, nombre, apellido, email, contraseña, rol, tipo_contrato,
                    fecha_inicio_contrato, fecha_fin_contrato, estado, sede_id)
                   VALUES
                   ('$cedula_esc', '$nombre_esc', '$apellido_esc', '$email_esc', '$pass_esc',
                    'celador', '$tipo_esc', $fi_val, $ff_val, 'activo', $sede_id)";

        if(mysqli_query($con, $insert)) {
            $creados++;
        } else {
            $fallidos++;
            error_log("Error SQL carga celadores fila $row: " . mysqli_error($con));
        }
    }

    mysqli_close($con);
    header("Location: ../views/admin/Celadores_admin.php?sede_id=$sede_id&msg=carga_exitosa&creados=$creados&fallidos=$fallidos");
    exit();

} catch(Exception $e) {
    error_log("Error en carga masiva celadores: " . $e->getMessage());
    header("Location: ../views/admin/carga_masiva_celadores.php?sede_id=$sede_id&error=error_lectura&detalle=" . urlencode($e->getMessage()));
    exit();
}
?>

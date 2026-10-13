<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/llegada.php";
require_once '../vendor/autoload.php';

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$con = conexion();

// ⭐ OBTENER SEDE_ID
$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
if($sede_id <= 0) {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

$sede_query = mysqli_query($con, "SELECT nombre FROM sedes WHERE id = $sede_id");
$sede_nombre = mysqli_fetch_assoc($sede_query)['nombre'];

$formato = in_array($_GET['formato'] ?? '', ['excel','pdf']) ? $_GET['formato'] : 'excel';

// Validar y sanear fechas para evitar SQL injection
$fecha_inicio_raw = $_GET['inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_fin_raw    = $_GET['fin']    ?? date('Y-m-d');
$fecha_inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio_raw) ? $fecha_inicio_raw : date('Y-m-d', strtotime('-30 days'));
$fecha_fin    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin_raw)    ? $fecha_fin_raw    : date('Y-m-d');

// Verificar si existe la tabla historial_ocupacion
$check_table = mysqli_query($con, "SHOW TABLES LIKE 'historial_ocupacion'");

if(mysqli_num_rows($check_table) == 0) {
    echo "<script>alert('La tabla de ocupaciones aún no existe en la base de datos');</script>";
    echo "<script>window.location.href = '../views/admin/reportes_admin.php?sede_id=$sede_id';</script>";
    exit();
}

// ⭐ QUERY FILTRADA POR SEDE
$query = "SELECT ho.*, 
          CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre,
          u.rol AS usuario_rol,
          a.nombre AS ambiente_nombre, 
          p.nombre AS piso_nombre,
          ho.jornada
          FROM historial_ocupacion ho
          LEFT JOIN usuarios u ON ho.usuario_id = u.id
          LEFT JOIN ambientes a ON ho.ambiente_id = a.id
          LEFT JOIN pisos p ON a.piso_id = p.id
          WHERE DATE(ho.fecha_inicio) BETWEEN '$fecha_inicio' AND '$fecha_fin'
          AND p.sede_id = $sede_id
          ORDER BY ho.fecha_inicio DESC";

$result = mysqli_query($con, $query);
$total = mysqli_num_rows($result);

// ⭐ ESTADÍSTICAS FILTRADAS
$total_instructores = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(DISTINCT ho.usuario_id) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio) BETWEEN '$fecha_inicio' AND '$fecha_fin' AND p.sede_id = $sede_id"))['total'];
$total_ambientes = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(DISTINCT ho.ambiente_id) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio) BETWEEN '$fecha_inicio' AND '$fecha_fin' AND p.sede_id = $sede_id"))['total'];

if($formato == 'excel') {
    // ============================================
    // GENERAR EXCEL CON PHPSPREADSHEET
    // ============================================
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Ocupaciones');
    
    // ENCABEZADO
    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', 'REPORTE DE OCUPACIONES - ' . strtoupper($sede_nombre));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F97316');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(30);
    
    $sheet->mergeCells('A2:I2');
    $sheet->setCellValue('A2', 'Sistema SAGA - Generado: ' . date('d/m/Y H:i'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->mergeCells('A3:I3');
    $sheet->setCellValue('A3', 'Período: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)));
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A3')->getFont()->setBold(true);
    
    // ESTADÍSTICAS
    $row = 5;
    $sheet->mergeCells("A$row:J$row");
    $sheet->setCellValue("A$row", 'ESTADÍSTICAS DEL PERÍODO');
    $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F97316');
    $sheet->getStyle("A$row")->getFont()->getColor()->setRGB('FFFFFF');
    
    $row++;
    $sheet->setCellValue("A$row", 'Total Ocupaciones:');
    $sheet->setCellValue("B$row", $total);
    $sheet->setCellValue("D$row", 'Instructores:');
    $sheet->setCellValue("E$row", $total_instructores);
    $sheet->setCellValue("G$row", 'Ambientes:');
    $sheet->setCellValue("H$row", $total_ambientes);
    $sheet->getStyle("A$row:J$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    $sheet->getStyle("A$row:J$row")->getFont()->setBold(true);
    
    // ENCABEZADOS
    $row = 8;
    $headers = ['ID', 'Fecha', 'Jornada', 'Hora Inicio', 'Hora Fin', 'Instructor', 'Ambiente', 'Piso', 'Observaciones', 'Llegada'];
    $col = 'A';
    foreach($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $col++;
    }
    
    $sheet->getStyle("A$row:J$row")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A$row:J$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F97316');
    $sheet->getStyle("A$row:J$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
    // DATOS
    $row++;
    if($total > 0) {
        while($data = mysqli_fetch_assoc($result)) {
            $sheet->setCellValue("A$row", $data['id']);
            $sheet->setCellValue("B$row", date('d/m/Y', strtotime($data['fecha_inicio'])));
            $sheet->setCellValue("C$row", ucfirst($data['jornada']));
            $sheet->setCellValue("D$row", date('H:i', strtotime($data['fecha_inicio'])));
            $sheet->setCellValue("E$row", date('H:i', strtotime($data['fecha_fin'])));
            $sheet->setCellValue("F$row", $data['instructor_nombre']);
            $sheet->setCellValue("G$row", $data['ambiente_nombre']);
            $sheet->setCellValue("H$row", $data['piso_nombre']);
            $sheet->setCellValue("I$row", $data['observaciones'] ?? '-');
            $sheet->setCellValue("J$row", llegada_texto_reporte($data));
            
            $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            
            $row++;
        }
    } else {
        $sheet->mergeCells("A$row:J$row");
        $sheet->setCellValue("A$row", 'No hay ocupaciones en este período para esta sede');
        $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A$row")->getFont()->setItalic(true);
    }
    
    // Ajustar anchos
    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(12);
    $sheet->getColumnDimension('F')->setWidth(30);
    $sheet->getColumnDimension('G')->setWidth(25);
    $sheet->getColumnDimension('H')->setWidth(20);
    $sheet->getColumnDimension('I')->setWidth(30);
    $sheet->getColumnDimension('J')->setWidth(24);
    
    // Generar archivo
    $filename = 'Reporte_Ocupaciones_' . $sede_nombre . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
} else if($formato == 'pdf') {
    // ============================================
    // GENERAR PDF CON TCPDF
    // ============================================
    
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SAGA');
    $pdf->SetTitle('Reporte de Ocupaciones - ' . $sede_nombre);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 12, 'REPORTE DE OCUPACIONES', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, strtoupper($sede_nombre), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Período: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)), 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFillColor(249, 115, 22);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'ESTADÍSTICAS', 0, 1, 'L', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(80, 6, 'Total: ' . $total, 0, 0);
    $pdf->Cell(80, 6, 'Instructores: ' . $total_instructores, 0, 0);
    $pdf->Cell(80, 6, 'Ambientes: ' . $total_ambientes, 0, 1);
    $pdf->Ln(5);
    
    $pdf->SetFillColor(30, 58, 82);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 7);
    
    $pdf->Cell(12, 8, 'ID', 1, 0, 'C', true);
    $pdf->Cell(22, 8, 'Fecha', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Jornada', 1, 0, 'C', true);
    $pdf->Cell(18, 8, 'Inicio', 1, 0, 'C', true);
    $pdf->Cell(18, 8, 'Fin', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Instructor', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Ambiente', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Piso', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Obs.', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Llegada', 1, 1, 'C', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 6);
    
    if($total > 0) {
        mysqli_data_seek($result, 0);
        while($row = mysqli_fetch_assoc($result)) {
            $pdf->Cell(12, 6, $row['id'], 1, 0, 'C');
            $pdf->Cell(22, 6, date('d/m/Y', strtotime($row['fecha_inicio'])), 1, 0, 'C');
            $pdf->Cell(20, 6, ucfirst($row['jornada']), 1, 0, 'C');
            $pdf->Cell(18, 6, date('H:i', strtotime($row['fecha_inicio'])), 1, 0, 'C');
            $pdf->Cell(18, 6, date('H:i', strtotime($row['fecha_fin'])), 1, 0, 'C');
            $pdf->Cell(45, 6, mb_substr($row['instructor_nombre'] ?? '', 0, 26), 1, 0, 'L');
            $pdf->Cell(35, 6, mb_substr($row['ambiente_nombre'] ?? '', 0, 18), 1, 0, 'L');
            $pdf->Cell(30, 6, substr($row['piso_nombre'], 0, 15), 1, 0, 'C');
            $pdf->Cell(30, 6, substr($row['observaciones'] ?? '-', 0, 15), 1, 0, 'C');
            $pdf->Cell(30, 6, llegada_texto_reporte($row), 1, 1, 'C');
        }
    } else {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 10, 'No hay ocupaciones en este período para esta sede', 0, 1, 'C');
    }
    
    $pdf->Output('Reporte_Ocupaciones_' . $sede_nombre . '_' . date('Y-m-d') . '.pdf', 'D');
}

mysqli_close($con);
?>
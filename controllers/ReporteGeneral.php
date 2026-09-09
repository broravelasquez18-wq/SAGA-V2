<?php
session_start();
require_once "../config/conexion.php";
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

$formato = $_GET['formato'] ?? 'excel';

// ⭐ ESTADÍSTICAS FILTRADAS POR SEDE
$total_instructores = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND (sede_id = $sede_id OR (tipo_contrato='contratista' AND sede_id IS NULL))"))['total'];
$instructores_activos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND estado='activo' AND (sede_id = $sede_id OR (tipo_contrato='contratista' AND sede_id IS NULL))"))['total'];
$instructores_inactivos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND estado='inactivo' AND (sede_id = $sede_id OR (tipo_contrato='contratista' AND sede_id IS NULL))"))['total'];
$instructores_planta = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND tipo_contrato='planta' AND sede_id = $sede_id"))['total'];
$instructores_contratista = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND tipo_contrato='contratista' AND sede_id IS NULL"))['total'];

$total_celadores = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='celador' AND sede_id = $sede_id"))['total'];
$celadores_activos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='celador' AND estado='activo' AND sede_id = $sede_id"))['total'];
$celadores_inactivos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='celador' AND estado='inactivo' AND sede_id = $sede_id"))['total'];
$celadores_planta = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='celador' AND tipo_contrato='planta' AND sede_id = $sede_id"))['total'];
$celadores_contratista = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='celador' AND tipo_contrato='contratista' AND sede_id = $sede_id"))['total'];

$fecha_limite = date('Y-m-d', strtotime('+30 days'));
$contratos_vencer = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE tipo_contrato='contratista' AND estado='activo' AND fecha_fin_contrato <= '$fecha_limite' AND fecha_fin_contrato >= CURDATE() AND (sede_id = $sede_id OR (rol='instructor' AND sede_id IS NULL))"))['total'];
$contratos_vencidos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE tipo_contrato='contratista' AND fecha_fin_contrato < CURDATE() AND (sede_id = $sede_id OR (rol='instructor' AND sede_id IS NULL))"))['total'];

$total_personal = $total_instructores + $total_celadores;
$total_activos = $instructores_activos + $celadores_activos;
$total_inactivos = $instructores_inactivos + $celadores_inactivos;

$total_ocupaciones = 0;
$check_table = mysqli_query($con, "SHOW TABLES LIKE 'historial_ocupacion'");
if(mysqli_num_rows($check_table) > 0) {
    $total_ocupaciones = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE p.sede_id = $sede_id"))['total'];
}

if($formato == 'excel') {
    // ============================================
    // GENERAR EXCEL CON PHPSPREADSHEET
    // ============================================
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Reporte General');
    
    // TÍTULO PRINCIPAL
    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A1', 'REPORTE GENERAL - ' . strtoupper($sede_nombre));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(22)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A52');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(35);
    
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A2', 'Sistema de Administración y Gestión de Ambientes');
    $sheet->getStyle('A2')->getFont()->setSize(12)->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->mergeCells('A3:F3');
    $sheet->setCellValue('A3', 'Generado el: ' . date('d/m/Y H:i'));
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // RESUMEN EJECUTIVO
    $row = 5;
    $sheet->mergeCells("A$row:F$row");
    $sheet->setCellValue("A$row", '📊 RESUMEN EJECUTIVO');
    $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3EB489');
    $sheet->getStyle("A$row")->getFont()->getColor()->setRGB('FFFFFF');
    
    $row++;
    $sheet->setCellValue("A$row", 'Total Personal'); $sheet->setCellValue("B$row", $total_personal);
    $sheet->setCellValue("C$row", 'Personal Activo'); $sheet->setCellValue("D$row", $total_activos);
    $sheet->setCellValue("E$row", 'Personal Inactivo'); $sheet->setCellValue("F$row", $total_inactivos);
    $sheet->getStyle("A$row:F$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');
    $sheet->getStyle("A$row:F$row")->getFont()->setBold(true);
    
    $row++;
    $sheet->setCellValue("A$row", 'Por Vencer (30 días)'); $sheet->setCellValue("B$row", $contratos_vencer);
    $sheet->setCellValue("C$row", 'Contratos Vencidos'); $sheet->setCellValue("D$row", $contratos_vencidos);
    $sheet->setCellValue("E$row", 'Total Ocupaciones'); $sheet->setCellValue("F$row", $total_ocupaciones);
    $sheet->getStyle("A$row:F$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');
    $sheet->getStyle("A$row:F$row")->getFont()->setBold(true);
    
    // INSTRUCTORES
    $row += 2;
    $sheet->mergeCells("A$row:F$row");
    $sheet->setCellValue("A$row", '👨‍🏫 INSTRUCTORES');
    $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B82F6');
    $sheet->getStyle("A$row")->getFont()->getColor()->setRGB('FFFFFF');
    
    $row++;
    $sheet->setCellValue("A$row", 'Categoría'); $sheet->setCellValue("B$row", 'Cantidad'); $sheet->setCellValue("C$row", 'Porcentaje');
    $sheet->getStyle("A$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:C$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    
    $row++;
    $sheet->setCellValue("A$row", 'Total Instructores'); 
    $sheet->setCellValue("B$row", $total_instructores);
    $sheet->setCellValue("C$row", '100%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Activos'); 
    $sheet->setCellValue("B$row", $instructores_activos);
    $sheet->setCellValue("C$row", ($total_instructores > 0 ? round(($instructores_activos/$total_instructores)*100, 1) : 0) . '%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Inactivos'); 
    $sheet->setCellValue("B$row", $instructores_inactivos);
    $sheet->setCellValue("C$row", ($total_instructores > 0 ? round(($instructores_inactivos/$total_instructores)*100, 1) : 0) . '%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Planta (de esta sede)'); 
    $sheet->setCellValue("B$row", $instructores_planta);
    $sheet->setCellValue("C$row", ($total_instructores > 0 ? round(($instructores_planta/$total_instructores)*100, 1) : 0) . '%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Contratistas (todas las sedes)'); 
    $sheet->setCellValue("B$row", $instructores_contratista);
    $sheet->setCellValue("C$row", ($total_instructores > 0 ? round(($instructores_contratista/$total_instructores)*100, 1) : 0) . '%');
    
    // CELADORES
    $row += 2;
    $sheet->mergeCells("A$row:F$row");
    $sheet->setCellValue("A$row", '👮 CELADORES');
    $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8B5CF6');
    $sheet->getStyle("A$row")->getFont()->getColor()->setRGB('FFFFFF');
    
    $row++;
    $sheet->setCellValue("A$row", 'Categoría'); $sheet->setCellValue("B$row", 'Cantidad'); $sheet->setCellValue("C$row", 'Porcentaje');
    $sheet->getStyle("A$row:C$row")->getFont()->setBold(true);
    $sheet->getStyle("A$row:C$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    
    $row++;
    $sheet->setCellValue("A$row", 'Total Celadores'); 
    $sheet->setCellValue("B$row", $total_celadores);
    $sheet->setCellValue("C$row", '100%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Activos'); 
    $sheet->setCellValue("B$row", $celadores_activos);
    $sheet->setCellValue("C$row", ($total_celadores > 0 ? round(($celadores_activos/$total_celadores)*100, 1) : 0) . '%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Inactivos'); 
    $sheet->setCellValue("B$row", $celadores_inactivos);
    $sheet->setCellValue("C$row", ($total_celadores > 0 ? round(($celadores_inactivos/$total_celadores)*100, 1) : 0) . '%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Planta'); 
    $sheet->setCellValue("B$row", $celadores_planta);
    $sheet->setCellValue("C$row", ($total_celadores > 0 ? round(($celadores_planta/$total_celadores)*100, 1) : 0) . '%');
    
    $row++;
    $sheet->setCellValue("A$row", 'Contratistas'); 
    $sheet->setCellValue("B$row", $celadores_contratista);
    $sheet->setCellValue("C$row", ($total_celadores > 0 ? round(($celadores_contratista/$total_celadores)*100, 1) : 0) . '%');
    
    // PERSONAL POR VENCER
    if($contratos_vencer > 0) {
        $row += 2;
        $sheet->mergeCells("A$row:F$row");
        $sheet->setCellValue("A$row", '⚠️ PERSONAL CON CONTRATOS POR VENCER (30 DÍAS)');
        $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F97316');
        $sheet->getStyle("A$row")->getFont()->getColor()->setRGB('FFFFFF');
        
        $row++;
        $headers = ['Nombre', 'Rol', 'Cédula', 'Fecha Fin', 'Días'];
        $col = 'A';
        foreach($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $col++;
        }
        $sheet->getStyle("A$row:E$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:E$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        
        $query_vencer = "SELECT * FROM usuarios 
                        WHERE tipo_contrato='contratista' 
                        AND estado='activo' 
                        AND fecha_fin_contrato <= '$fecha_limite' 
                        AND fecha_fin_contrato >= CURDATE()
                        AND (sede_id = $sede_id OR (rol='instructor' AND sede_id IS NULL))
                        ORDER BY fecha_fin_contrato ASC";
        $result_vencer = mysqli_query($con, $query_vencer);
        
        while($data = mysqli_fetch_assoc($result_vencer)) {
            $row++;
            $nombre = trim($data['nombre'] . ' ' . $data['apellido']);
            $fecha_fin = new DateTime($data['fecha_fin_contrato']);
            $hoy = new DateTime();
            $dias = $hoy->diff($fecha_fin)->days;
            
            $color = $dias <= 15 ? 'FED7AA' : 'FEF3C7';
            
            $sheet->setCellValue("A$row", $nombre);
            $sheet->setCellValue("B$row", ucfirst($data['rol']));
            $sheet->setCellValue("C$row", $data['cedula']);
            $sheet->setCellValue("D$row", date('d/m/Y', strtotime($data['fecha_fin_contrato'])));
            $sheet->setCellValue("E$row", $dias . ' días');
            
            $sheet->getStyle("A$row:E$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        }
    }
    
    // Ajustar anchos
    $sheet->getColumnDimension('A')->setWidth(35);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(15);
    
    // Generar
    $filename = 'Reporte_General_' . $sede_nombre . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
} else if($formato == 'pdf') {
    // ============================================
    // GENERAR PDF CON TCPDF
    // ============================================
    
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SAGA');
    $pdf->SetTitle('Reporte General - ' . $sede_nombre);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 12, 'REPORTE GENERAL', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, strtoupper($sede_nombre), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Generado: ' . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->Ln(8);
    
    // Estadísticas
    $pdf->SetFillColor(62, 180, 137);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'RESUMEN EJECUTIVO', 0, 1, 'L', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(219, 234, 254);
    
    $pdf->Cell(90, 8, 'Total Personal:', 1, 0, 'L', true);
    $pdf->Cell(90, 8, $total_personal, 1, 1, 'C', true);
    $pdf->Cell(90, 8, 'Activos:', 1, 0, 'L');
    $pdf->Cell(90, 8, $total_activos, 1, 1, 'C');
    $pdf->Cell(90, 8, 'Inactivos:', 1, 0, 'L', true);
    $pdf->Cell(90, 8, $total_inactivos, 1, 1, 'C', true);
    $pdf->Cell(90, 8, 'Por Vencer:', 1, 0, 'L');
    $pdf->Cell(90, 8, $contratos_vencer, 1, 1, 'C');
    $pdf->Cell(90, 8, 'Ocupaciones:', 1, 0, 'L', true);
    $pdf->Cell(90, 8, $total_ocupaciones, 1, 1, 'C', true);
    
    $pdf->Ln(5);
    
    // Instructores
    $pdf->SetFillColor(59, 130, 246);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'INSTRUCTORES', 0, 1, 'L', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(90, 6, 'Total:', 1, 0, 'L');
    $pdf->Cell(90, 6, $total_instructores, 1, 1, 'C');
    $pdf->Cell(90, 6, 'Activos:', 1, 0, 'L');
    $pdf->Cell(90, 6, $instructores_activos, 1, 1, 'C');
    $pdf->Cell(90, 6, 'Planta (sede):', 1, 0, 'L');
    $pdf->Cell(90, 6, $instructores_planta, 1, 1, 'C');
    $pdf->Cell(90, 6, 'Contratistas (todas):', 1, 0, 'L');
    $pdf->Cell(90, 6, $instructores_contratista, 1, 1, 'C');
    
    $pdf->Ln(3);
    
    // Celadores
    $pdf->SetFillColor(139, 92, 246);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'CELADORES', 0, 1, 'L', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(90, 6, 'Total:', 1, 0, 'L');
    $pdf->Cell(90, 6, $total_celadores, 1, 1, 'C');
    $pdf->Cell(90, 6, 'Activos:', 1, 0, 'L');
    $pdf->Cell(90, 6, $celadores_activos, 1, 1, 'C');
    
    $pdf->Output('Reporte_General_' . $sede_nombre . '_' . date('Y-m-d') . '.pdf', 'D');
}

mysqli_close($con);
?>
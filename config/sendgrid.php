<?php
// ── Credenciales Gmail SMTP (vienen de .env / variables de entorno) ─
require_once __DIR__ . '/env.php';
cargarEnv();

define('MAIL_HOST',     getenv('MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT',     (int) (getenv('MAIL_PORT') ?: 587));
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: 'xxxx');
define('MAIL_FROM',     getenv('MAIL_FROM') ?: (getenv('MAIL_USERNAME') ?: ''));
define('MAIL_FROM_NAME',getenv('MAIL_FROM_NAME') ?: 'SAGA - Gestión de Ambientes');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

/**
 * Envía un email usando PHPMailer con Gmail SMTP.
 * @param string $to       Email destinatario
 * @param string $toName   Nombre destinatario
 * @param string $subject  Asunto
 * @param string $html     Cuerpo HTML
 * @return bool
 */
function enviarEmail(string $to, string $toName, string $subject, string $html): bool {
    if(empty($to) || strpos(MAIL_PASSWORD, 'xxxx') !== false) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

// ── Base HTML ─────────────────────────────────────────────────────

function _emailBase(string $titulo, string $cuerpo): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:20px;background:#f0f4f8;font-family:Arial,Helvetica,sans-serif;'>
<div style='max-width:580px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>

  <div style='background:linear-gradient(135deg,#1e3a5f,#2a5298);padding:28px 32px;text-align:center;'>
    <h1 style='color:#ffffff;margin:0;font-size:28px;letter-spacing:3px;font-weight:900;'>SAGA</h1>
    <p style='color:#94c6e8;margin:4px 0 0;font-size:12px;letter-spacing:1px;'>SISTEMA DE GESTIÓN DE AMBIENTES</p>
  </div>

  <div style='padding:32px;'>
    <h2 style='color:#1e3a5f;margin:0 0 20px;font-size:20px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;'>$titulo</h2>
    $cuerpo
  </div>

  <div style='background:#f8fafc;padding:14px 32px;border-top:1px solid #e2e8f0;text-align:center;'>
    <p style='color:#94a3b8;font-size:11px;margin:0;'>Mensaje automático de SAGA &mdash; Por favor no responda este correo.</p>
  </div>

</div>
</body></html>";
}

// ── Templates ─────────────────────────────────────────────────────

function emailBienvenida(string $nombre, ?string $apellido, string $rol): string {
    $nombreCompleto = trim($nombre . ' ' . ($apellido ?? ''));
    $rolLabel = ucfirst($rol);
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombreCompleto</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Tu cuenta de <strong>$rolLabel</strong> en el sistema SAGA ha sido creada exitosamente. Ya puedes iniciar sesión.</p>
    <div style='background:#f0f9ff;border-left:4px solid #0ea5e9;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 6px;color:#0369a1;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Datos de acceso</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f464;&nbsp; Rol: <strong>$rolLabel</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f511;&nbsp; Contraseña: la que te proporcionó el administrador</p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Si tienes alguna duda, comunícate con el administrador del sistema.</p>";
    return _emailBase("¡Bienvenido/a a SAGA!", $cuerpo);
}

function emailNuevaOcupacion(string $nombre, ?string $apellido, string $ambiente, string $fecha, string $jornada, string $horaInicio, string $horaFin, int $total = 1): string {
    $nombreCompleto = trim($nombre . ' ' . ($apellido ?? ''));
    $fechaFmt  = date('d/m/Y', strtotime($fecha));
    $jornadaLabel = ucfirst($jornada);
    $totalMsg = $total > 1
        ? "<p style='color:#15803d;font-size:13px;margin:14px 0 0;'>&#x2705;&nbsp; Se registraron <strong>$total ocupaciones</strong> en total.</p>"
        : '';
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombreCompleto</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Se ha registrado una nueva ocupación a tu nombre en el sistema SAGA.</p>
    <div style='background:#f0fdf4;border-left:4px solid #22c55e;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#15803d;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Detalle de la ocupación</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fechaFmt</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f305;&nbsp; Jornada: <strong>$jornadaLabel</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x23f0;&nbsp; Horario: <strong>$horaInicio &ndash; $horaFin</strong></p>
    </div>
    $totalMsg
    <p style='color:#64748b;font-size:13px;margin:14px 0 0;'>Puedes ver todas tus ocupaciones en el sistema SAGA.</p>";
    return _emailBase("Nueva ocupación registrada", $cuerpo);
}

function emailOcupacionFinalizada(string $nombre, ?string $apellido, string $ambiente, string $fechaFin): string {
    $nombreCompleto = trim($nombre . ' ' . ($apellido ?? ''));
    $fechaFmt = date('d/m/Y', strtotime($fechaFin));
    $horaFmt  = date('H:i',   strtotime($fechaFin));
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombreCompleto</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Tu ocupación en SAGA ha sido marcada como <strong>finalizada</strong>.</p>
    <div style='background:#fff7ed;border-left:4px solid #f97316;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#c2410c;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Detalle</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x23f0;&nbsp; Finalizada el <strong>$fechaFmt</strong> a las <strong>$horaFmt</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>El ambiente ha quedado disponible para nuevas reservas.</p>";
    return _emailBase("Ocupación finalizada", $cuerpo);
}

function emailOcupacionCancelada(string $nombre, ?string $apellido, string $ambiente, string $fecha, string $jornada): string {
    $nombreCompleto = trim($nombre . ' ' . ($apellido ?? ''));
    $fechaFmt = date('d/m/Y', strtotime($fecha));
    $jornadaLabel = ucfirst($jornada);
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombreCompleto</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Tu ocupación en SAGA ha sido <strong>cancelada</strong>.</p>
    <div style='background:#fef2f2;border-left:4px solid #ef4444;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#b91c1c;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Ocupación cancelada</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fechaFmt</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f305;&nbsp; Jornada: <strong>$jornadaLabel</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Si tienes dudas, comunícate con el administrador del sistema.</p>";
    return _emailBase("Ocupación cancelada", $cuerpo);
}

function emailRecordatorio(string $nombre, ?string $apellido, string $ambiente, string $fecha, string $jornada, string $horaInicio, string $horaFin): string {
    $nombreCompleto = trim($nombre . ' ' . ($apellido ?? ''));
    $fechaFmt = date('d/m/Y', strtotime($fecha));
    $jornadaLabel = ucfirst($jornada);
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombreCompleto</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Este es un recordatorio: tu ocupación en SAGA <strong>comenzará en los próximos 30 minutos</strong>.</p>
    <div style='background:#faf5ff;border-left:4px solid #a855f7;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#7c3aed;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Tu ocupación</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fechaFmt</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f305;&nbsp; Jornada: <strong>$jornadaLabel</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x23f0;&nbsp; Horario: <strong>$horaInicio &ndash; $horaFin</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>&#x1f4cc;&nbsp; Recuerda finalizar tu ocupación cuando termines.</p>";
    return _emailBase("&#x23f0; Recordatorio de ocupación", $cuerpo);
}

function emailCancelacionVocero(?string $voceroNombre, string $ficha, string $ambiente, string $fecha, string $jornada, ?string $instructorNombre): string {
    $nombre = trim((string)($voceroNombre ?? '')); if ($nombre === '') $nombre = 'Vocero/a';
    $inst   = trim((string)($instructorNombre ?? '')); if ($inst === '') $inst = 'El instructor';
    $fechaFmt = date('d/m/Y', strtotime($fecha));
    $jornadaLabel = ucfirst($jornada);
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombre</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Te informamos que se ha <strong>cancelado una clase</strong> de tu ficha <strong>$ficha</strong>.</p>
    <div style='background:#fef2f2;border-left:4px solid #ef4444;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#b91c1c;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Clase cancelada</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f4d3;&nbsp; Ficha: <strong>$ficha</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f9d1;&#x200d;&#x1f3eb;&nbsp; Instructor: <strong>$inst</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fechaFmt</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f305;&nbsp; Jornada: <strong>$jornadaLabel</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Como vocero/a de la ficha, te avisamos para que informes a tus compañeros. Ante cualquier duda, comunícate con el administrador.</p>";
    return _emailBase("Clase cancelada &middot; Ficha $ficha", $cuerpo);
}

// ── Flujo del vocero: solicitud de ocupación / finalización ───────

/** Aviso al celador/admin: un vocero solicita ocupar el ambiente de su ficha. */
function emailSolicitudOcupacion(string $destNombre, string $voceroNombre, string $ficha, string $ambiente, string $jornada, string $horaInicio, string $horaFin, ?string $motivo = null): string {
    $dest = trim($destNombre) !== '' ? $destNombre : 'Hola';
    $jornadaLabel = ucfirst($jornada);
    $obsHtml = ($motivo !== null && trim($motivo) !== '')
        ? "<p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f4dd;&nbsp; Motivo: <strong>" . htmlspecialchars($motivo) . "</strong></p>"
        : '';
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$dest</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>El vocero/a <strong>$voceroNombre</strong> solicita <strong>ocupar un ambiente</strong>. Revisa el sistema para aprobar o rechazar.</p>
    <div style='background:#eff6ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#1e40af;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Solicitud de ocupación</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f4d3;&nbsp; Ficha: <strong>$ficha</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f305;&nbsp; Jornada: <strong>$jornadaLabel</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x23f0;&nbsp; Horario: <strong>$horaInicio &ndash; $horaFin</strong></p>
      $obsHtml
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Ingresa a SAGA para aprobar o rechazar la solicitud.</p>";
    return _emailBase("Nueva solicitud de ocupación", $cuerpo);
}

/** Aviso al vocero: su solicitud de ocupación fue aprobada o rechazada. */
function emailOcupacionResuelta(string $voceroNombre, string $ambiente, string $ficha, bool $aprobada): string {
    $nombre = trim($voceroNombre) !== '' ? $voceroNombre : 'Vocero/a';
    if ($aprobada) {
        $color = '#22c55e'; $bg = '#f0fdf4'; $tit = '#15803d'; $label = 'Ocupación aprobada';
        $intro = 'Tu solicitud para ocupar el ambiente fue <strong>APROBADA</strong>. Ya puedes usar el ambiente.';
        $titulo = 'Ocupación aprobada';
    } else {
        $color = '#ef4444'; $bg = '#fef2f2'; $tit = '#b91c1c'; $label = 'Ocupación rechazada';
        $intro = 'Tu solicitud para ocupar el ambiente fue <strong>RECHAZADA</strong>.';
        $titulo = 'Ocupación rechazada';
    }
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombre</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>$intro</p>
    <div style='background:$bg;border-left:4px solid $color;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:$tit;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>$label</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f4d3;&nbsp; Ficha: <strong>$ficha</strong></p>
    </div>";
    return _emailBase($titulo, $cuerpo);
}

/** Aviso al celador: un vocero solicita finalizar la ocupación. */
function emailSolicitudFinalizacion(string $destNombre, string $voceroNombre, string $ficha, string $ambiente): string {
    $dest = trim($destNombre) !== '' ? $destNombre : 'Hola';
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$dest</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>El vocero/a <strong>$voceroNombre</strong> solicita <strong>finalizar una ocupación</strong>. Apruébala si estás de acuerdo.</p>
    <div style='background:#fffbeb;border-left:4px solid #f59e0b;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#b45309;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Solicitud de finalización</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f4d3;&nbsp; Ficha: <strong>$ficha</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Ingresa a SAGA para aprobar o rechazar.</p>";
    return _emailBase("Solicitud de finalización", $cuerpo);
}

// ── Cambio de jornada (instructor -> admin) ──────────────────────

/** Aviso al admin: un instructor solicita cambiar la jornada de una clase. */
function emailSolicitudCambioJornada(string $destNombre, string $instructor, string $ambiente, string $fecha, string $jornadaActual, string $jornadaNueva, string $motivo): string {
    $dest = trim($destNombre) !== '' ? $destNombre : 'Administrador';
    $fechaFmt = $fecha ? date('d/m/Y', strtotime($fecha)) : '';
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$dest</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>El instructor <strong>$instructor</strong> solicita un <strong>cambio de jornada</strong>. Revisa el sistema para aprobar o rechazar.</p>
    <div style='background:#eff6ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#1e40af;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Cambio de jornada</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fechaFmt</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f504;&nbsp; De <strong>" . ucfirst($jornadaActual) . "</strong> a <strong>" . ucfirst($jornadaNueva) . "</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f4dd;&nbsp; Motivo: <strong>" . htmlspecialchars($motivo) . "</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Ingresa a SAGA para aprobar o rechazar la solicitud.</p>";
    return _emailBase("Nueva solicitud de cambio de jornada", $cuerpo);
}

/** Aviso al instructor: su solicitud de cambio de jornada fue aprobada o rechazada. */
function emailCambioJornadaResuelta(string $instructor, string $ambiente, string $fecha, string $jornadaActual, string $jornadaNueva, bool $aprobada, string $respuesta = ''): string {
    $nombre = trim($instructor) !== '' ? $instructor : 'Instructor';
    if ($aprobada) {
        $color = '#22c55e'; $bg = '#f0fdf4'; $tit = '#15803d'; $label = 'Cambio aprobado';
        $intro = "Tu cambio de jornada fue <strong>APROBADO</strong>. Ahora la clase es en <strong>" . ucfirst($jornadaNueva) . "</strong>.";
        $titulo = 'Cambio de jornada aprobado';
    } else {
        $color = '#ef4444'; $bg = '#fef2f2'; $tit = '#b91c1c'; $label = 'Cambio rechazado';
        $intro = "Tu cambio de jornada fue <strong>RECHAZADO</strong>. Se mantiene <strong>" . ucfirst($jornadaActual) . "</strong>.";
        $titulo = 'Cambio de jornada rechazado';
    }
    $respHtml = trim($respuesta) !== ''
        ? "<p style='margin:12px 0 0;color:#475569;font-size:13px;'>Comentario del administrador: &ldquo;" . htmlspecialchars($respuesta) . "&rdquo;</p>" : '';
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombre</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>$intro</p>
    <div style='background:$bg;border-left:4px solid $color;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:6px;'>
      <p style='margin:0 0 10px;color:$tit;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>$label</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fecha</strong></p>
    </div>
    $respHtml";
    return _emailBase($titulo, $cuerpo);
}

/** Aviso al vocero: la jornada de una clase de su ficha cambió. */
function emailCambioJornadaVocero(string $voceroNombre, string $ficha, string $ambiente, string $fecha, string $jornadaNueva): string {
    $nombre = trim($voceroNombre) !== '' ? $voceroNombre : 'Vocero/a';
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombre</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>Te informamos que una clase" . ($ficha ? " de tu ficha <strong>$ficha</strong>" : '') . " <strong>cambió de jornada</strong>.</p>
    <div style='background:#faf5ff;border-left:4px solid #a855f7;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:#7c3aed;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>Nueva jornada</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0;color:#334155;font-size:14px;'>&#x1f4c5;&nbsp; Fecha: <strong>$fecha</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f305;&nbsp; Jornada: <strong>" . ucfirst($jornadaNueva) . "</strong></p>
    </div>
    <p style='color:#64748b;font-size:13px;margin:0;'>Avísales a tus compañeros del cambio. Ante cualquier duda, comunícate con el administrador.</p>";
    return _emailBase("Clase con nueva jornada", $cuerpo);
}

/** Aviso al vocero: su solicitud de finalización fue aprobada o rechazada. */
function emailFinalizacionResuelta(string $voceroNombre, string $ambiente, string $ficha, bool $aprobada): string {
    $nombre = trim($voceroNombre) !== '' ? $voceroNombre : 'Vocero/a';
    if ($aprobada) {
        $color = '#f97316'; $bg = '#fff7ed'; $tit = '#c2410c'; $label = 'Finalización aprobada';
        $intro = 'El celador <strong>aprobó</strong> finalizar la ocupación. Quedó finalizada.';
        $titulo = 'Finalización aprobada';
    } else {
        $color = '#ef4444'; $bg = '#fef2f2'; $tit = '#b91c1c'; $label = 'Finalización rechazada';
        $intro = 'El celador <strong>rechazó</strong> finalizar la ocupación. Se mantiene.';
        $titulo = 'Finalización rechazada';
    }
    $cuerpo = "
    <p style='color:#334155;margin:0 0 14px;font-size:15px;'>Hola, <strong>$nombre</strong>.</p>
    <p style='color:#334155;margin:0 0 22px;font-size:14px;'>$intro</p>
    <div style='background:$bg;border-left:4px solid $color;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:22px;'>
      <p style='margin:0 0 10px;color:$tit;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;'>$label</p>
      <p style='margin:0;color:#334155;font-size:14px;'>&#x1f3eb;&nbsp; Ambiente: <strong>$ambiente</strong></p>
      <p style='margin:6px 0 0;color:#334155;font-size:14px;'>&#x1f4d3;&nbsp; Ficha: <strong>$ficha</strong></p>
    </div>";
    return _emailBase($titulo, $cuerpo);
}
?>

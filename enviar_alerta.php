<?php
/**
 * enviar_alerta.php
 * Envía un correo electrónico al acudiente de un estudiante en alerta
 * y actualiza el estado en la tabla `alertas`.
 *
 * Solo acepta peticiones POST de coordinadores autenticados.
 * Devuelve siempre JSON: { "ok": true|false, "mensaje": "..." }
 *
 * ── Configuración de correo ────────────────────────────────────────────────
 * Este archivo soporta dos modos, controlados por la constante USAR_PHPMAILER:
 *
 *   true  → PHPMailer vía SMTP (recomendado para producción).
 *            Requiere instalar la librería:
 *            composer require phpmailer/phpmailer
 *
 *   false → mail() nativa de PHP (solo funciona si el servidor tiene
 *            un MTA configurado, como Postfix/Sendmail en Linux).
 *            En XAMPP local normalmente NO envía correos reales;
 *            usar MailHog o Mailtrap para pruebas locales.
 * ──────────────────────────────────────────────────────────────────────────
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// ── Respuesta siempre en JSON ─────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Función helper: terminar con JSON ────────────────────────────────────
function responder(bool $ok, string $mensaje): never
{
    echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Solo coordinadores autenticados ──────────────────────────────────────
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'coordinador') {
    http_response_code(403);
    responder(false, 'Acceso no autorizado.');
}

// ── Solo POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, 'Método no permitido.');
}

require_once __DIR__ . '/conexion.php';

// ── Validar y sanear el ID del estudiante ─────────────────────────────────
$estudianteId = filter_input(INPUT_POST, 'estudiante_id', FILTER_VALIDATE_INT);
if (!$estudianteId || $estudianteId <= 0) {
    responder(false, 'ID de estudiante inválido.');
}

// ── Obtener datos del estudiante y acudiente desde la BD ──────────────────
$pdo  = obtenerConexion();
$stmt = $pdo->prepare(
    "SELECT e.nombre, e.curso, e.correo_acudiente, e.datos_contacto_acudiente,
            COUNT(a.id) AS total_fallas,
            MIN(a.fecha) AS primera_falla,
            MAX(a.fecha) AS ultima_falla
       FROM estudiantes e
       JOIN asistencia a ON a.estudiante_id = e.id
      WHERE e.id     = :id
        AND e.activo = 1
        AND a.estado = 'falla'
        AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      GROUP BY e.id, e.nombre, e.curso, e.correo_acudiente, e.datos_contacto_acudiente"
);
$stmt->execute([':id' => $estudianteId]);
$est = $stmt->fetch();

if (!$est) {
    responder(false, 'Estudiante no encontrado o sin fallas recientes.');
}

// ── Construir el correo ───────────────────────────────────────────────────
$destinatario = $est['correo_acudiente'];
$asunto       = 'Alerta de Inasistencia Escolar — ' . $est['nombre'] . ' | Colegio OEA';
$fDesde       = date('d/m/Y', strtotime($est['primera_falla']));
$fHasta       = date('d/m/Y', strtotime($est['ultima_falla']));
$fechaHoy     = date('d/m/Y');

// Cuerpo del correo en HTML (compatible con los principales clientes de correo)
$cuerpoHtml = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Segoe UI',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)">

        <!-- Cabecera verde del colegio -->
        <tr>
          <td style="background:#059669;padding:28px 36px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#fff">Colegio OEA</p>
            <p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.8)">
              SIPAE — Sistema Integral de Permanencia, Asistencia y Alimentación Escolar
            </p>
          </td>
        </tr>

        <!-- Alerta roja -->
        <tr>
          <td style="background:#fef2f2;padding:14px 36px;border-left:4px solid #dc2626">
            <p style="margin:0;font-size:13px;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.05em">
              ⚠ Alerta de Inasistencia Reiterada
            </p>
          </td>
        </tr>

        <!-- Cuerpo del mensaje -->
        <tr>
          <td style="padding:28px 36px">
            <p style="margin:0 0 16px;font-size:15px;color:#374151">
              Estimado(a) acudiente de <strong>{$est['nombre']}</strong>,
            </p>
            <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6">
              Le informamos que su acudido(a) ha acumulado
              <strong style="color:#dc2626">{$est['total_fallas']} inasistencia(s)</strong>
              en los últimos 7 días de clase, lo cual supera el umbral de seguimiento
              establecido por el Colegio OEA.
            </p>

            <!-- Tabla de resumen -->
            <table width="100%" cellpadding="10" cellspacing="0"
                   style="background:#f9fafb;border-radius:8px;margin-bottom:20px;font-size:14px">
              <tr>
                <td style="color:#6b7280;width:45%">Estudiante</td>
                <td style="font-weight:700;color:#1e2a3a">{$est['nombre']}</td>
              </tr>
              <tr style="background:#f3f4f6">
                <td style="color:#6b7280">Curso</td>
                <td style="font-weight:700;color:#1e2a3a">{$est['curso']}</td>
              </tr>
              <tr>
                <td style="color:#6b7280">Inasistencias</td>
                <td style="font-weight:700;color:#dc2626">{$est['total_fallas']}</td>
              </tr>
              <tr style="background:#f3f4f6">
                <td style="color:#6b7280">Período</td>
                <td style="font-weight:700;color:#1e2a3a">{$fDesde} — {$fHasta}</td>
              </tr>
              <tr>
                <td style="color:#6b7280">Fecha de notificación</td>
                <td style="font-weight:700;color:#1e2a3a">{$fechaHoy}</td>
              </tr>
            </table>

            <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6">
              Le solicitamos respetuosamente comunicarse con la coordinación académica
              del colegio para conocer los motivos de estas ausencias y acordar un
              plan de acompañamiento para su acudido(a).
            </p>

            <!-- Botón de contacto -->
            <p style="text-align:center;margin:24px 0">
              <a href="mailto:coordinacion@oea.edu.co"
                 style="background:#059669;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700">
                Contactar coordinación
              </a>
            </p>
          </td>
        </tr>

        <!-- Pie del correo -->
        <tr>
          <td style="background:#f9fafb;padding:18px 36px;border-top:1px solid #e5e7eb">
            <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.5">
              Este mensaje fue generado automáticamente por el sistema SIPAE del Colegio OEA.
              Por favor no responda directamente a este correo.
              <br>© {$fechaHoy} Colegio OEA — Bogotá, Colombia.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

// Versión texto plano (fallback para clientes que no renderizan HTML)
$cuerpoTexto = "Estimado(a) acudiente de {$est['nombre']},\n\n"
    . "Le informamos que su acudido(a) ha acumulado {$est['total_fallas']} inasistencia(s) "
    . "entre el {$fDesde} y el {$fHasta}.\n\n"
    . "Por favor comuníquese con la coordinación del Colegio OEA.\n\n"
    . "— Sistema SIPAE";

// =============================================================================
// MODO 1: PHPMailer (recomendado)
// Instalar con: composer require phpmailer/phpmailer
// =============================================================================
define('USAR_PHPMAILER', true);

// Configuración SMTP — ajustar según el proveedor de correo del colegio
define('SMTP_HOST',     'smtp.gmail.com');     // Gmail; cambiar por el servidor del colegio
define('SMTP_PUERTO',   587);                  // 587 = TLS, 465 = SSL
define('SMTP_USUARIO',  'sipae.oea@gmail.com');// Cuenta remitente
define('SMTP_CLAVE',    'xxxx xxxx xxxx xxxx');// Contraseña de aplicación de Google
define('SMTP_NOMBRE',   'SIPAE — Colegio OEA');// Nombre visible en el correo

$enviado = false;
$errorEnvio = '';

if (USAR_PHPMAILER) {

    // Verificar que PHPMailer esté instalado vía Composer
    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!file_exists($autoload)) {
        responder(false,
            'PHPMailer no está instalado. Ejecuta: composer require phpmailer/phpmailer ' .
            'en el directorio del proyecto, o cambia USAR_PHPMAILER a false para usar mail().'
        );
    }

    require $autoload;

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;

    $mail = new PHPMailer(true); // true = lanza excepciones en vez de errores silenciosos

    try {
        // ── Configuración del servidor SMTP ────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USUARIO;
        $mail->Password   = SMTP_CLAVE;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
        $mail->Port       = SMTP_PUERTO;
        $mail->CharSet    = 'UTF-8';

        // ── Remitente y destinatario ───────────────────────────────────────
        $mail->setFrom(SMTP_USUARIO, SMTP_NOMBRE);
        $mail->addAddress($destinatario); // correo del acudiente

        // ── Contenido ─────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpoHtml;
        $mail->AltBody = $cuerpoTexto; // texto plano de respaldo

        $mail->send();
        $enviado = true;

    } catch (Exception $e) {
        $errorEnvio = $mail->ErrorInfo;
        error_log('[SIPAE] Error PHPMailer: ' . $errorEnvio);
    }

} else {

    // ==========================================================================
    // MODO 2: mail() nativa de PHP
    // Útil en producción con un servidor Linux que tenga Postfix/Sendmail.
    // En XAMPP local no envía correos reales sin configuración adicional.
    // ==========================================================================
    $cabeceras  = "MIME-Version: 1.0\r\n";
    $cabeceras .= "Content-Type: text/html; charset=UTF-8\r\n";
    $cabeceras .= "From: " . SMTP_NOMBRE . " <" . SMTP_USUARIO . ">\r\n";
    $cabeceras .= "Reply-To: coordinacion@oea.edu.co\r\n";
    $cabeceras .= "X-Mailer: PHP/" . phpversion();

    $enviado = mail($destinatario, $asunto, $cuerpoHtml, $cabeceras);

    if (!$enviado) {
        $errorEnvio = 'mail() devolvió false. Verifica la configuración del servidor de correo.';
        error_log('[SIPAE] Error mail(): ' . $errorEnvio);
    }
}

// ── Actualizar tabla alertas si el correo fue enviado ─────────────────────
if ($enviado) {

    // Marcar alertas pendientes existentes de este estudiante como notificadas
    $stmtUpdate = $pdo->prepare(
        "UPDATE alertas
            SET estado        = 'notificado',
                notificado_en = NOW()
          WHERE estudiante_id = :id
            AND estado        = 'pendiente'"
    );
    $stmtUpdate->execute([':id' => $estudianteId]);

    // Si no había alertas pendientes, insertar un nuevo registro histórico
    if ($stmtUpdate->rowCount() === 0) {
        $stmtInsert = $pdo->prepare(
            "INSERT INTO alertas
                   (estudiante_id, tipo_alerta, descripcion, fecha, estado, notificado_en)
             VALUES (:id, 'inasistencia_reiterada',
                    :desc, CURDATE(), 'notificado', NOW())"
        );
        $stmtInsert->execute([
            ':id'   => $estudianteId,
            ':desc' => "Acudiente notificado por {$est['total_fallas']} inasistencias "
                     . "entre {$fDesde} y {$fHasta}.",
        ]);
    }

    responder(true, 'Correo enviado correctamente a ' . $destinatario);

} else {
    responder(false, 'No se pudo enviar el correo: ' . $errorEnvio);
}

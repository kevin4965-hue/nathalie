<?php
/**
 * procesar_estudiante.php
 * Recibe el formulario manual de estudiantes.php y agrega un solo
 * estudiante a la tabla `estudiantes`.
 */

session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'coordinador') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: estudiantes.php');
    exit;
}

require_once __DIR__ . '/conexion.php';

$nombre              = trim($_POST['nombre']               ?? '');
$curso               = trim($_POST['curso']                ?? '');
$nombreAcudiente     = trim($_POST['nombre_acudiente']      ?? '');
$parentescoAcudiente = trim($_POST['parentesco_acudiente']  ?? '');
$whatsappAcudiente   = trim($_POST['whatsapp_acudiente']    ?? '');
$correoAcudiente     = trim($_POST['correo_acudiente']      ?? '');

// Validación básica: ningún campo puede estar vacío y el correo debe ser válido
if (
    $nombre === '' || $curso === '' || $nombreAcudiente === ''
    || $parentescoAcudiente === '' || $whatsappAcudiente === ''
    || !filter_var($correoAcudiente, FILTER_VALIDATE_EMAIL)
) {
    header('Location: estudiantes.php?error=validacion');
    exit;
}

$pdo = obtenerConexion();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO estudiantes
                (nombre, curso, nombre_acudiente, parentesco_acudiente, whatsapp_acudiente, correo_acudiente)
         VALUES (:nombre, :curso, :nombre_acudiente, :parentesco_acudiente, :whatsapp_acudiente, :correo_acudiente)'
    );
    $stmt->execute([
        ':nombre'               => $nombre,
        ':curso'                => $curso,
        ':nombre_acudiente'     => $nombreAcudiente,
        ':parentesco_acudiente' => $parentescoAcudiente,
        ':whatsapp_acudiente'   => $whatsappAcudiente,
        ':correo_acudiente'     => $correoAcudiente,
    ]);

    header('Location: estudiantes.php?guardado=1');

} catch (PDOException $e) {
    error_log('[SIPAE] Error al guardar estudiante: ' . $e->getMessage());
    header('Location: estudiantes.php?error=bd');
}

exit;

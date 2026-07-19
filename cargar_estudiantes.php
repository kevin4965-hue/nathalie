<?php
/**
 * cargar_estudiantes.php
 * Recibe el archivo plano (CSV/TXT) de estudiantes.php y carga varios
 * estudiantes de una sola vez.
 *
 * Formato esperado de cada línea, separado por comas:
 *   nombre,curso,nombre_acudiente,parentesco_acudiente,whatsapp_acudiente,correo_acudiente
 *
 * Las filas incompletas o con correo inválido se descartan (no detienen
 * la carga de las demás filas).
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

// ── Verificar que el archivo llegó bien ───────────────────────────────────
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    header('Location: estudiantes.php?error=archivo');
    exit;
}

$handle = fopen($_FILES['archivo']['tmp_name'], 'r');
if (!$handle) {
    header('Location: estudiantes.php?error=archivo');
    exit;
}

$pdo  = obtenerConexion();
$stmt = $pdo->prepare(
    'INSERT INTO estudiantes
            (nombre, curso, nombre_acudiente, parentesco_acudiente, whatsapp_acudiente, correo_acudiente)
     VALUES (:nombre, :curso, :nombre_acudiente, :parentesco_acudiente, :whatsapp_acudiente, :correo_acudiente)'
);

$cargados    = 0;
$primeraFila = true;

try {
    $pdo->beginTransaction();

    while (($fila = fgetcsv($handle, 0, ',')) !== false) {

        // Ignorar líneas completamente vacías
        if (count($fila) === 1 && trim((string) $fila[0]) === '') {
            continue;
        }

        // Si la primera fila es un encabezado ("nombre,curso,..."), se salta
        if ($primeraFila) {
            $primeraFila = false;
            if (strtolower(trim($fila[0])) === 'nombre') {
                continue;
            }
        }

        // Fila incompleta: se descarta
        if (count($fila) < 6) {
            continue;
        }

        [$nombre, $curso, $nombreAcudiente, $parentescoAcudiente, $whatsappAcudiente, $correoAcudiente] =
            array_map('trim', array_slice($fila, 0, 6));

        // Datos obligatorios y correo válido; si no, se descarta esta fila
        if (
            $nombre === '' || $curso === '' || $nombreAcudiente === ''
            || $parentescoAcudiente === '' || $whatsappAcudiente === ''
            || !filter_var($correoAcudiente, FILTER_VALIDATE_EMAIL)
        ) {
            continue;
        }

        $stmt->execute([
            ':nombre'               => $nombre,
            ':curso'                => $curso,
            ':nombre_acudiente'     => $nombreAcudiente,
            ':parentesco_acudiente' => $parentescoAcudiente,
            ':whatsapp_acudiente'   => $whatsappAcudiente,
            ':correo_acudiente'     => $correoAcudiente,
        ]);

        $cargados++;
    }

    $pdo->commit();
    fclose($handle);

    if ($cargados === 0) {
        header('Location: estudiantes.php?error=formato');
    } else {
        header('Location: estudiantes.php?cargados=' . $cargados);
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fclose($handle);
    error_log('[SIPAE] Error al cargar estudiantes: ' . $e->getMessage());
    header('Location: estudiantes.php?error=bd');
}

exit;

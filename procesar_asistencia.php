<?php
/**
 * procesar_asistencia.php
 * Recibe el formulario POST de dashboard_docente.php y persiste
 * los registros de asistencia en la base de datos.
 *
 * Este archivo NO genera salida HTML: solo procesa datos y redirige.
 *
 * Seguridad aplicada:
 *   - Solo acepta peticiones POST autenticadas con rol 'docente'.
 *   - Valida cada campo antes de tocar la base de datos.
 *   - Verifica que los IDs de estudiantes recibidos pertenezcan
 *     realmente al curso indicado (previene manipulación del formulario).
 *   - Usa prepared statements (PDO) para toda inserción.
 *   - INSERT ... ON DUPLICATE KEY UPDATE permite corregir registros ya
 *     guardados sin duplicarlos (la clave única es estudiante+fecha+bloque).
 *   - Toda la operación está envuelta en una transacción: si falla un
 *     registro, se revierten todos.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// ── Verificar autenticación y rol ─────────────────────────────────────────
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'docente') {
    header('Location: login.php');
    exit;
}

// ── Solo se acepta POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard_docente.php');
    exit;
}

require_once __DIR__ . '/conexion.php';

// ── Recoger datos del formulario ──────────────────────────────────────────
$docenteId  = (int)$_SESSION['usuario_id'];
$curso      = trim($_POST['curso']  ?? '');
$fecha      = trim($_POST['fecha']  ?? '');
$bloque     = (int)($_POST['bloque'] ?? 0);
$asistencia = $_POST['asistencia'] ?? [];   // array [ estudiante_id => estado ]

// ── Construir URL de retorno con los filtros actuales ─────────────────────
// Permite volver a la misma vista (mismo curso/fecha/bloque) tras redirigir.
$queryRetorno = http_build_query([
    'curso'  => $curso,
    'fecha'  => $fecha,
    'bloque' => $bloque,
]);

// ── Función auxiliar de redirección ──────────────────────────────────────
function redirigir(string $estado, string $queryRetorno): never
{
    header("Location: dashboard_docente.php?{$estado}&{$queryRetorno}");
    exit;
}

// ── Validación de campos obligatorios ────────────────────────────────────

// Curso: no vacío
if ($curso === '') {
    redirigir('error=validacion', $queryRetorno);
}

// Fecha: formato YYYY-MM-DD y no futura
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) {
    redirigir('error=validacion', $queryRetorno);
}

// Bloque: entre 1 y 8
if ($bloque < 1 || $bloque > 8) {
    redirigir('error=validacion', $queryRetorno);
}

// Asistencia: debe ser un array no vacío
if (!is_array($asistencia) || empty($asistencia)) {
    redirigir('error=validacion', $queryRetorno);
}

// ── Valores de estado permitidos (lista blanca) ───────────────────────────
// Cualquier valor fuera de esta lista es descartado silenciosamente.
$estadosPermitidos = ['asistió', 'falla', 'justificado', 'novedad'];

// ── Extraer y sanear los IDs recibidos ────────────────────────────────────
// Convertir todas las claves a enteros y eliminar ceros o negativos.
$idsRecibidos = array_filter(
    array_map('intval', array_keys($asistencia)),
    fn($id) => $id > 0
);

if (empty($idsRecibidos)) {
    redirigir('error=validacion', $queryRetorno);
}

// ── Conectar a la base de datos ───────────────────────────────────────────
$pdo = obtenerConexion();

// ── Verificación de integridad: los IDs deben pertenecer al curso ─────────
// Previene que un docente malicioso manipule el formulario para registrar
// asistencia de estudiantes de otros cursos.
$placeholders = implode(',', array_fill(0, count($idsRecibidos), '?'));
$stmtVerif    = $pdo->prepare(
    "SELECT id FROM estudiantes
      WHERE id IN ($placeholders)
        AND curso  = ?
        AND activo = 1"
);
$stmtVerif->execute([...$idsRecibidos, $curso]);
$idsValidos = array_column($stmtVerif->fetchAll(), 'id');

// Si ningún ID es válido, no hay nada que guardar
if (empty($idsValidos)) {
    redirigir('error=validacion', $queryRetorno);
}

// ── Preparar el statement de inserción ───────────────────────────────────
//
// INSERT ... ON DUPLICATE KEY UPDATE:
//   - Si el par (estudiante_id, fecha, bloque_clase) NO existe → inserta.
//   - Si YA existe → actualiza el estado y la marca de tiempo.
//   Esto permite que el docente corrija registros sin duplicarlos.
//
// La restricción UNIQUE (estudiante_id, fecha, bloque_clase) de la tabla
// es quien dispara el ON DUPLICATE KEY UPDATE.
$stmt = $pdo->prepare(
    'INSERT INTO asistencia (estudiante_id, docente_id, fecha, bloque_clase, estado)
          VALUES (:estudiante_id, :docente_id, :fecha, :bloque, :estado)
     ON DUPLICATE KEY UPDATE
          estado      = VALUES(estado),
          docente_id  = VALUES(docente_id),
          registrado_en = NOW()'
);

// ── Ejecutar dentro de una transacción ───────────────────────────────────
// Si falla cualquier fila, se hace ROLLBACK y se redirige con error.
try {
    $pdo->beginTransaction();

    foreach ($asistencia as $estudianteId => $estado) {

        $estudianteId = (int)$estudianteId;

        // Descartar IDs que no superaron la verificación de seguridad
        if (!in_array($estudianteId, $idsValidos, true)) {
            continue;
        }

        // Descartar estados que no están en la lista blanca
        if (!in_array($estado, $estadosPermitidos, true)) {
            continue;
        }

        $stmt->execute([
            ':estudiante_id' => $estudianteId,
            ':docente_id'    => $docenteId,
            ':fecha'         => $fecha,
            ':bloque'        => $bloque,
            ':estado'        => $estado,
        ]);
    }

    $pdo->commit();

    // Éxito: redirigir con mensaje y los mismos filtros para confirmación visual
    redirigir('guardado=1', $queryRetorno);

} catch (PDOException $e) {

    // Deshacer todo si algo falló
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Registrar el error real en el log del servidor (nunca en pantalla)
    error_log('[SIPAE] Error al guardar asistencia: ' . $e->getMessage());

    redirigir('error=bd', $queryRetorno);
}

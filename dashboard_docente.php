<?php
/**
 * dashboard_docente.php
 * Panel principal del docente: selección de curso y registro de asistencia.
 *
 * Flujo:
 *   1. El docente elige curso, fecha y bloque → GET al mismo archivo.
 *   2. PHP consulta los estudiantes de ese curso y cualquier asistencia
 *      ya registrada para esa fecha/bloque (para permitir correcciones).
 *   3. El docente marca el estado de cada estudiante y guarda.
 *   4. El formulario de asistencia hace POST a procesar_asistencia.php.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Protección: solo docentes autenticados
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'docente') {
    header('Location: login.php');
    exit;
}

// Cerrar sesión
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/conexion.php';

$pdo = obtenerConexion();

// ── Cargar lista de cursos disponibles ──────────────────────────────────────
$stmtCursos = $pdo->query(
    'SELECT DISTINCT curso FROM estudiantes WHERE activo = 1 ORDER BY curso ASC'
);
$cursos = $stmtCursos->fetchAll(PDO::FETCH_COLUMN);

// ── Filtros seleccionados (vienen por GET) ──────────────────────────────────
$cursoSel  = trim($_GET['curso']  ?? '');
$fechaSel  = $_GET['fecha']  ?? date('Y-m-d');       // Hoy por defecto
$bloqueSel = max(1, min(8, (int)($_GET['bloque'] ?? 1))); // Entre 1 y 8

// ── Cargar estudiantes y asistencia existente ────────────────────────────────
$estudiantes        = [];
$asistenciaExistente = [];   // [ estudiante_id => estado ]

if ($cursoSel !== '') {
    // Estudiantes del curso seleccionado
    $stmtEst = $pdo->prepare(
        'SELECT id, nombre FROM estudiantes
          WHERE curso = :curso AND activo = 1
          ORDER BY nombre ASC'
    );
    $stmtEst->execute([':curso' => $cursoSel]);
    $estudiantes = $stmtEst->fetchAll();

    // Si hay estudiantes, buscar registros previos para esa fecha y bloque
    if (!empty($estudiantes)) {
        $ids          = array_column($estudiantes, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmtExist = $pdo->prepare(
            "SELECT estudiante_id, estado
               FROM asistencia
              WHERE estudiante_id IN ($placeholders)
                AND fecha        = ?
                AND bloque_clase = ?"
        );
        $stmtExist->execute([...$ids, $fechaSel, $bloqueSel]);

        foreach ($stmtExist->fetchAll() as $row) {
            $asistenciaExistente[$row['estudiante_id']] = $row['estado'];
        }
    }
}

// ── Mensaje de resultado (llegado desde procesar_asistencia.php) ─────────────
$alerta = null;
if (isset($_GET['guardado'])) {
    $alerta = ['tipo' => 'exito', 'texto' => 'Asistencia guardada correctamente.'];
} elseif (isset($_GET['error'])) {
    $textos = [
        'validacion' => 'Faltan datos requeridos. Verifica el formulario.',
        'bd'         => 'Error al guardar en la base de datos. Intenta de nuevo.',
    ];
    $alerta = [
        'tipo'  => 'error',
        'texto' => $textos[$_GET['error']] ?? 'Ocurrió un error inesperado.',
    ];
}

// ── Opciones de estado con etiqueta, valor y clase CSS ──────────────────────
$estados = [
    ['valor' => 'asistió',    'etiqueta' => 'Asistió',    'clase' => 'est-asistio'],
    ['valor' => 'falla',      'etiqueta' => 'Falla',       'clase' => 'est-falla'],
    ['valor' => 'justificado','etiqueta' => 'Justif.',     'clase' => 'est-justificado'],
    ['valor' => 'novedad',    'etiqueta' => 'Novedad',     'clase' => 'est-novedad'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIPAE — Panel Docente</title>
    <style>
        /* ── Reset ─────────────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── Variables de color ─────────────────────────────────────────────── */
        :root {
            --azul:        #1a56db;
            --azul-oscuro: #1648c0;
            --verde:       #059669;
            --rojo:        #dc2626;
            --azul-claro:  #2563eb;
            --naranja:     #d97706;
            --gris-fondo:  #f0f4f8;
            --gris-borde:  #e2e8f0;
            --gris-texto:  #6b7280;
            --texto:       #1e2a3a;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--gris-fondo);
            color: var(--texto);
            min-height: 100vh;
        }

        /* ── Barra de navegación superior ──────────────────────────────────── */
        .navbar {
            background: var(--azul);
            padding: .875rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar__marca {
            display: flex;
            align-items: center;
            gap: .625rem;
            color: #fff;
            font-size: 1.125rem;
            font-weight: 700;
            text-decoration: none;
        }

        .navbar__marca svg { width: 28px; height: 28px; fill: #fff; }

        .navbar__usuario {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            color: rgba(255,255,255,.9);
            font-size: .875rem;
        }

        .navbar__nombre { font-weight: 600; }
        .navbar__rol    { font-size: .75rem; opacity: .75; }

        .navbar__logout {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: .35rem .875rem;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 600;
            transition: background .2s;
        }
        .navbar__logout:hover { background: rgba(255,255,255,.25); }

        /* ── Contenedor principal ───────────────────────────────────────────── */
        .contenedor {
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1.25rem;
        }

        /* ── Tarjeta genérica ───────────────────────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            padding: 1.5rem 1.75rem;
            margin-bottom: 1.5rem;
        }

        .card__titulo {
            font-size: 1rem;
            font-weight: 700;
            color: var(--texto);
            margin-bottom: 1.25rem;
            padding-bottom: .75rem;
            border-bottom: 2px solid var(--gris-borde);
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .card__titulo svg { width: 20px; height: 20px; fill: var(--azul); }

        /* ── Formulario de filtros ──────────────────────────────────────────── */
        .filtros {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filtro__grupo { display: flex; flex-direction: column; gap: .375rem; flex: 1; min-width: 160px; }

        .filtro__label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--gris-texto);
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .filtro__input, .filtro__select {
            padding: .575rem .875rem;
            border: 1.5px solid var(--gris-borde);
            border-radius: 8px;
            font-size: .925rem;
            color: var(--texto);
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
            width: 100%;
        }

        .filtro__input:focus, .filtro__select:focus {
            border-color: var(--azul);
            box-shadow: 0 0 0 3px rgba(26,86,219,.12);
        }

        .btn-cargar {
            padding: .6rem 1.5rem;
            background: var(--azul);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .925rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            height: 40px;
            white-space: nowrap;
        }
        .btn-cargar:hover { background: var(--azul-oscuro); }

        /* ── Alertas de resultado ───────────────────────────────────────────── */
        .alerta {
            padding: .875rem 1.125rem;
            border-radius: 10px;
            font-size: .9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: .625rem;
            margin-bottom: 1.5rem;
        }

        .alerta svg { width: 20px; height: 20px; flex-shrink: 0; }

        .alerta--exito { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }
        .alerta--exito svg { fill: #15803d; }

        .alerta--error { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
        .alerta--error svg { fill: #b91c1c; }

        /* ── Cabecera de la sección de asistencia ───────────────────────────── */
        .seccion-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 1rem;
        }

        .seccion-header h2 { font-size: 1rem; font-weight: 700; }

        .badge {
            background: #eff6ff;
            color: var(--azul);
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            padding: .25rem .75rem;
            font-size: .8rem;
            font-weight: 700;
        }

        /* ── Tabla de asistencia ────────────────────────────────────────────── */
        .tabla-wrapper { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
        }

        thead th {
            background: #f8fafc;
            padding: .75rem 1rem;
            text-align: left;
            font-size: .75rem;
            font-weight: 700;
            color: var(--gris-texto);
            text-transform: uppercase;
            letter-spacing: .05em;
            border-bottom: 2px solid var(--gris-borde);
        }

        thead th:first-child  { border-radius: 8px 0 0 0; width: 48px; text-align: center; }
        thead th:last-child   { border-radius: 0 8px 0 0; }

        tbody tr {
            border-bottom: 1px solid var(--gris-borde);
            transition: background .15s;
        }

        tbody tr:last-child  { border-bottom: none; }
        tbody tr:hover       { background: #f8fafc; }

        tbody td { padding: .75rem 1rem; vertical-align: middle; }

        td.num {
            text-align: center;
            color: var(--gris-texto);
            font-size: .8rem;
            font-weight: 600;
        }

        td.nombre { font-weight: 600; color: var(--texto); }

        /* ── Radio buttons con estilo de píldoras ───────────────────────────── */
        .radio-grupo {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Ocultar el input nativo pero mantenerlo funcional */
        .radio-grupo input[type="radio"] { display: none; }

        .radio-grupo label {
            padding: .35rem .8rem;
            border-radius: 20px;
            border: 1.5px solid;
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s, color .15s, transform .1s;
            user-select: none;
        }

        .radio-grupo label:hover { opacity: .82; transform: scale(1.04); }

        /* Estado: Asistió — verde */
        .radio-grupo input[type="radio"] + label.est-asistio {
            border-color: var(--verde); color: var(--verde); background: transparent;
        }
        .radio-grupo input[type="radio"]:checked + label.est-asistio {
            background: var(--verde); color: #fff;
        }

        /* Estado: Falla — rojo */
        .radio-grupo input[type="radio"] + label.est-falla {
            border-color: var(--rojo); color: var(--rojo); background: transparent;
        }
        .radio-grupo input[type="radio"]:checked + label.est-falla {
            background: var(--rojo); color: #fff;
        }

        /* Estado: Justificado — azul */
        .radio-grupo input[type="radio"] + label.est-justificado {
            border-color: var(--azul-claro); color: var(--azul-claro); background: transparent;
        }
        .radio-grupo input[type="radio"]:checked + label.est-justificado {
            background: var(--azul-claro); color: #fff;
        }

        /* Estado: Novedad — naranja */
        .radio-grupo input[type="radio"] + label.est-novedad {
            border-color: var(--naranja); color: var(--naranja); background: transparent;
        }
        .radio-grupo input[type="radio"]:checked + label.est-novedad {
            background: var(--naranja); color: #fff;
        }

        /* ── Pie de formulario ──────────────────────────────────────────────── */
        .form-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 2px solid var(--gris-borde);
        }

        .form-footer__nota {
            font-size: .82rem;
            color: var(--gris-texto);
        }

        .btn-guardar {
            padding: .75rem 2.25rem;
            background: var(--verde);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .975rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .5rem;
            transition: background .2s, transform .1s;
        }
        .btn-guardar:hover  { background: #047857; }
        .btn-guardar:active { transform: scale(.98); }
        .btn-guardar svg    { width: 18px; height: 18px; fill: #fff; }

        /* ── Estado vacío ───────────────────────────────────────────────────── */
        .vacio {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gris-texto);
        }

        .vacio svg { width: 48px; height: 48px; fill: #d1d5db; margin-bottom: .75rem; }
        .vacio p   { font-size: .925rem; }

        /* ── Leyenda de colores ─────────────────────────────────────────────── */
        .leyenda {
            display: flex;
            flex-wrap: wrap;
            gap: .625rem;
            margin-top: .75rem;
        }

        .leyenda__item {
            display: flex;
            align-items: center;
            gap: .375rem;
            font-size: .78rem;
            color: var(--gris-texto);
        }

        .leyenda__dot {
            width: 10px; height: 10px; border-radius: 50%;
        }

        .dot-asistio    { background: var(--verde); }
        .dot-falla      { background: var(--rojo); }
        .dot-justif     { background: var(--azul-claro); }
        .dot-novedad    { background: var(--naranja); }

        /* ── Responsive ─────────────────────────────────────────────────────── */
        @media (max-width: 640px) {
            .filtros { flex-direction: column; }
            .btn-cargar { width: 100%; }
            .radio-grupo label { font-size: .7rem; padding: .3rem .6rem; }
        }
    </style>
</head>
<body>

<!-- ══ BARRA DE NAVEGACIÓN ══════════════════════════════════════════════════ -->
<nav class="navbar">
    <a href="dashboard_docente.php" class="navbar__marca">
        <!-- Ícono escudo SVG inline -->
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.25C17.25 22.15 21 17.25 21 12V7L12 2zm0 2.18l7 3.89V12c0 4.35-3.1 8.4-7 9.43C8.1 20.4 5 16.35 5 12V8.07l7-3.89z"/>
        </svg>
        SIPAE
    </a>

    <div class="navbar__usuario">
        <div>
            <div class="navbar__nombre">
                <?= htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="navbar__rol">Docente</div>
        </div>
        <a href="?logout=1" class="navbar__logout">Cerrar sesión</a>
    </div>
</nav>

<!-- ══ CONTENIDO PRINCIPAL ══════════════════════════════════════════════════ -->
<main class="contenedor">

    <!-- Alerta de resultado -->
    <?php if ($alerta): ?>
        <div class="alerta alerta--<?= $alerta['tipo'] ?>" role="alert">
            <?php if ($alerta['tipo'] === 'exito'): ?>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <?php else: ?>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
            <?php endif; ?>
            <?= htmlspecialchars($alerta['texto'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ── TARJETA DE FILTROS ──────────────────────────────────────────────── -->
    <div class="card">
        <h2 class="card__titulo">
            <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L13 10.414V17a1 1 0 01-.553.894l-4 2A1 1 0 017 19v-8.586L3.293 6.707A1 1 0 013 6V3z" clip-rule="evenodd"/></svg>
            Seleccionar curso y sesión
        </h2>

        <!--
            Formulario de filtro: método GET al mismo archivo.
            Al enviarse, recarga la página con los parámetros en la URL
            y PHP consulta los estudiantes correspondientes.
        -->
        <form method="get" action="dashboard_docente.php">
            <div class="filtros">

                <!-- Selector de curso -->
                <div class="filtro__grupo">
                    <label class="filtro__label" for="curso">Curso</label>
                    <select class="filtro__select" id="curso" name="curso" required>
                        <option value="">— Selecciona un curso —</option>
                        <?php foreach ($cursos as $c): ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($c === $cursoSel) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Selector de fecha (máximo: hoy, para no registrar fechas futuras) -->
                <div class="filtro__grupo">
                    <label class="filtro__label" for="fecha">Fecha</label>
                    <input
                        class="filtro__input"
                        type="date"
                        id="fecha"
                        name="fecha"
                        value="<?= htmlspecialchars($fechaSel, ENT_QUOTES, 'UTF-8') ?>"
                        max="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <!-- Selector de bloque de clase -->
                <div class="filtro__grupo">
                    <label class="filtro__label" for="bloque">Bloque</label>
                    <select class="filtro__select" id="bloque" name="bloque">
                        <?php for ($b = 1; $b <= 8; $b++): ?>
                            <option value="<?= $b ?>" <?= ($b === $bloqueSel) ? 'selected' : '' ?>>
                                Bloque <?= $b ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button class="btn-cargar" type="submit">Cargar estudiantes</button>
            </div>
        </form>

        <!-- Leyenda de colores -->
        <div class="leyenda">
            <span class="leyenda__item"><span class="leyenda__dot dot-asistio"></span>Asistió</span>
            <span class="leyenda__item"><span class="leyenda__dot dot-falla"></span>Falla</span>
            <span class="leyenda__item"><span class="leyenda__dot dot-justif"></span>Justificado</span>
            <span class="leyenda__item"><span class="leyenda__dot dot-novedad"></span>Novedad</span>
        </div>
    </div>

    <!-- ── TABLA DE ASISTENCIA ─────────────────────────────────────────────── -->
    <?php if ($cursoSel !== ''): ?>
        <div class="card">

            <div class="seccion-header">
                <h2>
                    Asistencia —
                    <?= htmlspecialchars($cursoSel, ENT_QUOTES, 'UTF-8') ?> /
                    <?= htmlspecialchars(date('d/m/Y', strtotime($fechaSel)), ENT_QUOTES, 'UTF-8') ?> /
                    Bloque <?= $bloqueSel ?>
                </h2>
                <?php if (!empty($estudiantes)): ?>
                    <span class="badge"><?= count($estudiantes) ?> estudiante(s)</span>
                <?php endif; ?>
            </div>

            <?php if (empty($estudiantes)): ?>
                <!-- Estado vacío: no hay estudiantes en ese curso -->
                <div class="vacio">
                    <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                    <p>No se encontraron estudiantes activos en el curso <strong><?= htmlspecialchars($cursoSel, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
                </div>

            <?php else: ?>
                <!--
                    Formulario de asistencia: POST a procesar_asistencia.php.
                    Los campos ocultos pasan los datos de contexto (curso, fecha, bloque).
                    Cada estudiante aporta un input radio con name="asistencia[{id}]".
                -->
                <form id="form-asistencia" method="post" action="procesar_asistencia.php">

                    <!-- Campos de contexto (no los ve el usuario) -->
                    <input type="hidden" name="curso"  value="<?= htmlspecialchars($cursoSel, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="fecha"  value="<?= htmlspecialchars($fechaSel, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="bloque" value="<?= $bloqueSel ?>">

                    <div class="tabla-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Estudiante</th>
                                    <th>Estado de asistencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes as $i => $est):
                                    $id = (int)$est['id'];
                                    // Estado pre-seleccionado: registro existente o 'asistió' por defecto
                                    $estadoActual = $asistenciaExistente[$id] ?? 'asistió';
                                ?>
                                <tr>
                                    <td class="num"><?= $i + 1 ?></td>

                                    <td class="nombre">
                                        <?= htmlspecialchars($est['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <div class="radio-grupo" role="group"
                                             aria-label="Estado de <?= htmlspecialchars($est['nombre'], ENT_QUOTES, 'UTF-8') ?>">

                                            <?php foreach ($estados as $est_op):
                                                $inputId = 'est_' . $id . '_' . $est_op['valor'];
                                                $checked = ($estadoActual === $est_op['valor']) ? 'checked' : '';
                                            ?>
                                                <input
                                                    type="radio"
                                                    id="<?= $inputId ?>"
                                                    name="asistencia[<?= $id ?>]"
                                                    value="<?= $est_op['valor'] ?>"
                                                    <?= $checked ?>
                                                    required
                                                >
                                                <label
                                                    for="<?= $inputId ?>"
                                                    class="<?= $est_op['clase'] ?>"
                                                >
                                                    <?= $est_op['etiqueta'] ?>
                                                </label>
                                            <?php endforeach; ?>

                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-footer">
                        <p class="form-footer__nota">
                            <?php if (!empty($asistenciaExistente)): ?>
                                ⚠️ Ya existe un registro para esta sesión. Guardar sobreescribirá los datos anteriores.
                            <?php else: ?>
                                Marca el estado de cada estudiante antes de guardar.
                            <?php endif; ?>
                        </p>

                        <button class="btn-guardar" type="submit">
                            <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Guardar asistencia
                        </button>
                    </div>

                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</main>

<!-- ══ JAVASCRIPT ══════════════════════════════════════════════════════════
     Confirmación antes de guardar la asistencia.
     El formulario solo existe cuando hay un curso cargado,
     por eso se verifica con getElementById antes de añadir el listener.
     ════════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    const formAsistencia = document.getElementById('form-asistencia');

    if (formAsistencia) {
        formAsistencia.addEventListener('submit', function (e) {
            // Mostrar diálogo de confirmación nativo del navegador
            const confirmar = window.confirm(
                '¿Está seguro de que desea registrar la asistencia actual?\n\n' +
                'Esta acción guardará o actualizará los estados marcados para todos los estudiantes.'
            );

            // Si el docente cancela, detener el envío del formulario
            if (!confirmar) {
                e.preventDefault();
            }
        });
    }
})();
</script>

</body>
</html>

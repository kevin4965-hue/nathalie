<?php
/**
 * dashboard_coordinador.php
 * Panel de control del coordinador — SIPAE, Colegio OEA.
 *
 * Secciones:
 *   1. KPIs del día  — cifras clave en tarjetas de resumen.
 *   2. PAE           — total de almuerzos requeridos hoy, desglosado por curso.
 *   3. Alertas       — estudiantes con 3+ inasistencias en los últimos 7 días.
 *   4. Control Docente — qué docentes ya registraron asistencia hoy y cuáles no.
 *
 * Todas las consultas usan PDO con prepared statements o queries seguras
 * sobre datos que provienen exclusivamente de la base de datos (no de entrada
 * del usuario), por lo que no hay riesgo de inyección SQL en este archivo.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// ── Protección: solo coordinadores autenticados ───────────────────────────
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'coordinador') {
    header('Location: login.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/conexion.php';

$pdo = obtenerConexion();

// =============================================================================
// BLOQUE 1 — KPIs del día
// Cuatro métricas clave que aparecen en tarjetas en la parte superior.
// =============================================================================

/**
 * KPI 1: Total de estudiantes con al menos UN registro de asistencia hoy.
 * Indica el avance del registro: cuántos estudiantes ya fueron marcados
 * por algún docente, independientemente del estado.
 */
$kpiRegistradosHoy = (int) $pdo
    ->query("SELECT COUNT(DISTINCT estudiante_id)
               FROM asistencia
              WHERE fecha = CURDATE()")
    ->fetchColumn();

/**
 * KPI 2: Almuerzos PAE.
 * Cuenta estudiantes únicos que marcaron 'asistió' hoy.
 * Un mismo estudiante puede tener varios bloques registrados;
 * DISTINCT garantiza contarlo solo una vez para el almuerzo.
 */
$kpiAlmuerzosHoy = (int) $pdo
    ->query("SELECT COUNT(DISTINCT estudiante_id)
               FROM asistencia
              WHERE fecha   = CURDATE()
                AND estado  = 'asistió'")
    ->fetchColumn();

/**
 * KPI 3: Inasistencias del día (fallas sin justificar).
 * Estudiantes únicos marcados como 'falla' en cualquier bloque de hoy.
 */
$kpiFallasHoy = (int) $pdo
    ->query("SELECT COUNT(DISTINCT estudiante_id)
               FROM asistencia
              WHERE fecha  = CURDATE()
                AND estado = 'falla'")
    ->fetchColumn();

/**
 * KPI 4: Alertas pendientes de notificación.
 * Cuenta las alertas en la tabla de alertas que aún no han sido
 * comunicadas al acudiente (estado = 'pendiente').
 */
$kpiAlertasPendientes = (int) $pdo
    ->query("SELECT COUNT(*)
               FROM alertas
              WHERE estado = 'pendiente'")
    ->fetchColumn();


// =============================================================================
// BLOQUE 2 — PAE: desglose de almuerzos por curso
// Agrupa los estudiantes con 'asistió' hoy por curso para facilitar
// la organización del servicio de alimentación.
// =============================================================================

/**
 * Consulta agrupada por curso:
 *   - JOIN con estudiantes para obtener el nombre del curso.
 *   - DISTINCT en estudiante_id por si un mismo alumno tiene
 *     varios bloques marcados como 'asistió' en el día.
 *   - ORDER BY curso ASC para presentación ordenada.
 */
$stmtPAE = $pdo->query(
    "SELECT e.curso,
            COUNT(DISTINCT a.estudiante_id) AS almuerzos
       FROM asistencia a
       JOIN estudiantes e ON e.id = a.estudiante_id
      WHERE a.fecha  = CURDATE()
        AND a.estado = 'asistió'
      GROUP BY e.curso
      ORDER BY e.curso ASC"
);
$paePorCurso = $stmtPAE->fetchAll();


// =============================================================================
// BLOQUE 3 — Alertas de inasistencia
// Estudiantes con 3 o más fallas en los últimos 7 días corridos.
//
// La ventana de 7 días se calcula con DATE_SUB(CURDATE(), INTERVAL 7 DAY),
// lo que evita depender de la fecha actual hardcodeada y hace la consulta
// siempre vigente sin intervención manual.
// =============================================================================

/**
 * Subconsulta conceptual:
 *   1. Filtrar registros de asistencia con estado = 'falla'
 *      en la ventana de 7 días.
 *   2. Agrupar por estudiante.
 *   3. HAVING filtra solo los que acumularon >= 3 fallas.
 *   4. Se traen datos del acudiente para notificación directa.
 *   5. primera_falla / ultima_falla permiten ver el rango del problema.
 *   6. Ordenar de mayor a menor fallas (los más críticos, primero).
 */
$stmtAlertas = $pdo->query(
    "SELECT e.id,
            e.nombre,
            e.curso,
            e.correo_acudiente,
            COUNT(a.id)    AS total_fallas,
            MIN(a.fecha)   AS primera_falla,
            MAX(a.fecha)   AS ultima_falla
       FROM estudiantes e
       JOIN asistencia a ON a.estudiante_id = e.id
      WHERE a.estado = 'falla'
        AND a.fecha  >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND e.activo  = 1
      GROUP BY e.id, e.nombre, e.curso, e.correo_acudiente
     HAVING total_fallas >= 3
      ORDER BY total_fallas DESC, e.nombre ASC"
);
$estudiantesEnAlerta = $stmtAlertas->fetchAll();


// =============================================================================
// BLOQUE 4 — Control de docentes
// Compara todos los docentes activos contra los que tienen registros hoy.
//
// Técnica: LEFT JOIN de usuarios (docentes) contra asistencia del día.
//   - Si el docente registró → COUNT(a.id) > 0 → estado = 'registrado'.
//   - Si NO registró → COUNT(a.id) = 0 → estado = 'pendiente'.
// Un solo LEFT JOIN reemplaza una subconsulta NOT IN, con mejor rendimiento.
// =============================================================================

/**
 * Columnas relevantes:
 *   - estado_registro: 'registrado' | 'pendiente'
 *   - estudiantes_marcados: cuántos alumnos únicos marcó hoy.
 *   - bloques_registrados: en cuántos bloques distintos registró.
 *   - hora_ultimo: hora del último registro (útil para confirmar cuándo lo hizo).
 *
 * ORDER BY:
 *   - 'pendiente' < 'registrado' alfabéticamente → ASC pone pendientes primero,
 *     que son los casos que el coordinador necesita atender.
 */
$stmtDocentes = $pdo->query(
    "SELECT u.id,
            u.nombre,
            CASE
                WHEN COUNT(a.id) > 0 THEN 'registrado'
                ELSE 'pendiente'
            END                              AS estado_registro,
            COUNT(DISTINCT a.estudiante_id)  AS estudiantes_marcados,
            COUNT(DISTINCT a.bloque_clase)   AS bloques_registrados,
            TIME(MAX(a.registrado_en))       AS hora_ultimo
       FROM usuarios u
       LEFT JOIN asistencia a
              ON a.docente_id = u.id
             AND a.fecha      = CURDATE()
      WHERE u.rol    = 'docente'
        AND u.activo = 1
      GROUP BY u.id, u.nombre
      ORDER BY estado_registro ASC, u.nombre ASC"
);
$docentesControl = $stmtDocentes->fetchAll();

// Contadores derivados para el resumen de docentes
$docentesPendientes  = count(array_filter($docentesControl,
    fn($d) => $d['estado_registro'] === 'pendiente'));
$docentesRegistrados = count($docentesControl) - $docentesPendientes;

// Marca de tiempo de última actualización de la página
$ultimaActualizacion = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIPAE — Panel Coordinador</title>
    <style>
        /* ── Reset y variables ─────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --verde:         #059669;
            --verde-oscuro:  #047857;
            --verde-claro:   #d1fae5;
            --azul:          #1a56db;
            --azul-claro:    #eff6ff;
            --rojo:          #dc2626;
            --rojo-claro:    #fef2f2;
            --naranja:       #d97706;
            --naranja-claro: #fffbeb;
            --amarillo:      #ca8a04;
            --amarillo-claro:#fefce8;
            --gris-fondo:    #f0f4f8;
            --gris-borde:    #e2e8f0;
            --gris-texto:    #6b7280;
            --texto:         #1e2a3a;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--gris-fondo);
            color: var(--texto);
            min-height: 100vh;
        }

        /* ── Navbar ────────────────────────────────────────────────────────── */
        .navbar {
            background: var(--verde);
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

        .navbar__derecha {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .navbar__usuario { color: rgba(255,255,255,.9); font-size: .875rem; }
        .navbar__nombre  { font-weight: 700; }
        .navbar__rol     { font-size: .75rem; opacity: .75; }

        .navbar__ts {
            font-size: .72rem;
            color: rgba(255,255,255,.7);
            background: rgba(0,0,0,.15);
            padding: .3rem .7rem;
            border-radius: 20px;
        }

        .navbar__logout {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.18);
            padding: .35rem .875rem;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 600;
            transition: background .2s;
        }
        .navbar__logout:hover { background: rgba(255,255,255,.30); }

        /* ── Contenedor ────────────────────────────────────────────────────── */
        .contenedor {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.25rem;
        }

        /* ── Grid KPIs ─────────────────────────────────────────────────────── */
        .grid-kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }

        /* ── Tarjeta KPI ───────────────────────────────────────────────────── */
        .kpi {
            background: #fff;
            border-radius: 12px;
            padding: 1.375rem 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            display: flex;
            flex-direction: column;
            gap: .375rem;
            border-left: 4px solid transparent;
        }

        .kpi__icono {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: .25rem;
        }

        .kpi__icono svg { width: 22px; height: 22px; }

        .kpi__valor {
            font-size: 2.25rem;
            font-weight: 800;
            line-height: 1;
        }

        .kpi__etiqueta {
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--gris-texto);
        }

        .kpi__sub {
            font-size: .75rem;
            color: var(--gris-texto);
            margin-top: .125rem;
        }

        /* Variantes de color por KPI */
        .kpi--verde  { border-color: var(--verde);   }
        .kpi--verde  .kpi__icono { background: var(--verde-claro); }
        .kpi--verde  .kpi__icono svg { fill: var(--verde); }
        .kpi--verde  .kpi__valor { color: var(--verde); }

        .kpi--azul   { border-color: var(--azul);    }
        .kpi--azul   .kpi__icono { background: var(--azul-claro); }
        .kpi--azul   .kpi__icono svg { fill: var(--azul); }
        .kpi--azul   .kpi__valor { color: var(--azul); }

        .kpi--rojo   { border-color: var(--rojo);    }
        .kpi--rojo   .kpi__icono { background: var(--rojo-claro); }
        .kpi--rojo   .kpi__icono svg { fill: var(--rojo); }
        .kpi--rojo   .kpi__valor { color: var(--rojo); }

        .kpi--naranja { border-color: var(--naranja); }
        .kpi--naranja .kpi__icono { background: var(--naranja-claro); }
        .kpi--naranja .kpi__icono svg { fill: var(--naranja); }
        .kpi--naranja .kpi__valor { color: var(--naranja); }

        /* ── Grid dos columnas ─────────────────────────────────────────────── */
        .grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.75rem;
            align-items: start;
        }

        /* ── Tarjeta genérica ──────────────────────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            overflow: hidden;
        }

        .card__header {
            padding: 1.125rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--gris-borde);
        }

        .card__titulo {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .975rem;
            font-weight: 700;
            color: var(--texto);
        }

        .card__titulo svg { width: 18px; height: 18px; }

        .card__body { padding: 1.25rem 1.5rem; }

        /* ── Badge ─────────────────────────────────────────────────────────── */
        .badge {
            border-radius: 20px;
            padding: .25rem .75rem;
            font-size: .75rem;
            font-weight: 700;
        }

        .badge--verde   { background: var(--verde-claro);   color: var(--verde-oscuro); }
        .badge--azul    { background: var(--azul-claro);    color: var(--azul); }
        .badge--rojo    { background: var(--rojo-claro);    color: var(--rojo); }
        .badge--naranja { background: var(--naranja-claro); color: var(--naranja); }
        .badge--amarillo{ background: var(--amarillo-claro);color: var(--amarillo); }

        /* ── Total PAE prominente ──────────────────────────────────────────── */
        .pae-total {
            text-align: center;
            padding: 1rem 0 1.25rem;
            border-bottom: 1px solid var(--gris-borde);
            margin-bottom: 1rem;
        }

        .pae-total__numero {
            font-size: 3.5rem;
            font-weight: 900;
            color: var(--verde);
            line-height: 1;
        }

        .pae-total__label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--gris-texto);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-top: .375rem;
        }

        /* ── Tabla compacta ────────────────────────────────────────────────── */
        .tabla-compacta {
            width: 100%;
            border-collapse: collapse;
            font-size: .875rem;
        }

        .tabla-compacta th {
            text-align: left;
            padding: .5rem .625rem;
            font-size: .72rem;
            font-weight: 700;
            color: var(--gris-texto);
            text-transform: uppercase;
            letter-spacing: .05em;
            border-bottom: 2px solid var(--gris-borde);
        }

        .tabla-compacta td {
            padding: .625rem .625rem;
            border-bottom: 1px solid var(--gris-borde);
            vertical-align: middle;
        }

        .tabla-compacta tbody tr:last-child td { border-bottom: none; }

        .tabla-compacta tbody tr:hover { background: #fafafa; }

        /* ── Tabla de alertas (ancho completo) ─────────────────────────────── */
        .card--full { margin-bottom: 1.75rem; }

        /* ── Indicador de severidad de fallas ──────────────────────────────── */
        .fallas-chip {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            border-radius: 20px;
            padding: .3rem .75rem;
            font-size: .8rem;
            font-weight: 800;
        }

        /* 3 fallas → amarillo; 4 → naranja; 5+ → rojo */
        .fallas-chip--medio   { background: var(--amarillo-claro); color: var(--amarillo); }
        .fallas-chip--alto    { background: var(--naranja-claro);  color: var(--naranja); }
        .fallas-chip--critico { background: var(--rojo-claro);     color: var(--rojo); }

        /* ── Estado docente ────────────────────────────────────────────────── */
        .estado-doc {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .8rem;
            font-weight: 700;
            padding: .3rem .7rem;
            border-radius: 20px;
        }

        .estado-doc--ok      { background: var(--verde-claro);   color: var(--verde-oscuro); }
        .estado-doc--pending { background: var(--rojo-claro);    color: var(--rojo); }

        .estado-doc .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .dot--verde { background: var(--verde); }
        .dot--rojo  { background: var(--rojo); }

        /* ── Barra de progreso ─────────────────────────────────────────────── */
        .barra-wrap {
            background: var(--gris-borde);
            border-radius: 4px;
            height: 6px;
            width: 80px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
        }

        .barra-fill {
            height: 100%;
            background: var(--verde);
            border-radius: 4px;
        }

        /* ── Enlace correo ─────────────────────────────────────────────────── */
        .link-correo {
            color: var(--azul);
            text-decoration: none;
            font-size: .82rem;
        }
        .link-correo:hover { text-decoration: underline; }

        /* ── Estado vacío ──────────────────────────────────────────────────── */
        .vacio {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--gris-texto);
            font-size: .9rem;
        }

        .vacio svg {
            width: 44px;
            height: 44px;
            fill: #d1d5db;
            display: block;
            margin: 0 auto .75rem;
        }

        /* ── Responsive ────────────────────────────────────────────────────── */
        @media (max-width: 900px) {
            .grid-kpis { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 700px) {
            .grid-2col  { grid-template-columns: 1fr; }
            .grid-kpis  { grid-template-columns: repeat(2, 1fr); }
            .navbar__ts { display: none; }
        }

        @media (max-width: 440px) {
            .grid-kpis { grid-template-columns: 1fr; }
        }

        /* ── Botón Notificar Acudiente ─────────────────────────────────────── */
        .btn-notificar {
            padding: .35rem .85rem;
            background: var(--azul);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            transition: background .2s, opacity .2s;
            white-space: nowrap;
        }
        .btn-notificar:hover    { background: #1648c0; }
        .btn-notificar:disabled { opacity: .55; cursor: not-allowed; }
        .btn-notificar svg      { width: 14px; height: 14px; fill: #fff; flex-shrink: 0; }
        .btn-notificar--ok      { background: var(--verde) !important; pointer-events: none; }
        .btn-notificar--error   { background: var(--rojo)  !important; }

        /* Toast flotante para confirmación / error ─────────────────────── */
        .toast {
            position: fixed;
            bottom: 1.5rem;
            right:  1.5rem;
            z-index: 9999;
            padding: .875rem 1.25rem;
            border-radius: 10px;
            font-size: .9rem;
            font-weight: 600;
            color: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,.18);
            display: flex;
            align-items: center;
            gap: .625rem;
            animation: slideIn .25s ease;
            max-width: 360px;
        }
        .toast svg    { width: 20px; height: 20px; fill: #fff; flex-shrink: 0; }
        .toast--ok    { background: var(--verde); }
        .toast--error { background: var(--rojo);  }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        /* ── Nombre curso ──────────────────────────────────────────────────── */
        .curso-tag {
            background: #f1f5f9;
            border: 1px solid var(--gris-borde);
            border-radius: 4px;
            padding: .15rem .5rem;
            font-size: .78rem;
            font-weight: 700;
            color: var(--gris-texto);
        }
    </style>
</head>
<body>

<!-- ══ BARRA DE NAVEGACIÓN ══════════════════════════════════════════════════ -->
<nav class="navbar">
    <a href="dashboard_coordinador.php" class="navbar__marca">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.25C17.25 22.15 21 17.25 21 12V7L12 2zm0 2.18l7 3.89V12c0 4.35-3.1 8.4-7 9.43C8.1 20.4 5 16.35 5 12V8.07l7-3.89z"/>
        </svg>
        SIPAE — Coordinación
    </a>

    <div class="navbar__derecha">
        <span class="navbar__ts">Actualizado: <?= $ultimaActualizacion ?></span>
        <div class="navbar__usuario">
            <div class="navbar__nombre">
                <?= htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="navbar__rol">Coordinador(a)</div>
        </div>
        <a href="?logout=1" class="navbar__logout">Cerrar sesión</a>
    </div>
</nav>

<!-- ══ CONTENIDO PRINCIPAL ══════════════════════════════════════════════════ -->
<main class="contenedor">

    <!-- ════════════════════════════════════════════════════════════════════
         SECCIÓN 1 — KPIs DEL DÍA
         Cuatro indicadores clave en tarjetas de color.
         ════════════════════════════════════════════════════════════════════ -->
    <div class="grid-kpis">

        <!-- KPI: Estudiantes registrados hoy -->
        <div class="kpi kpi--azul">
            <div class="kpi__icono">
                <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </div>
            <div class="kpi__valor"><?= $kpiRegistradosHoy ?></div>
            <div class="kpi__etiqueta">Registrados hoy</div>
            <div class="kpi__sub">Estudiantes con algún estado marcado</div>
        </div>

        <!-- KPI: Almuerzos PAE -->
        <div class="kpi kpi--verde">
            <div class="kpi__icono">
                <svg viewBox="0 0 24 24"><path d="M18.06 22.99h1.66c.84 0 1.53-.64 1.63-1.46L23 5.05h-5V1h-1.97v4.05h-4.97l.3 2.34c1.71.47 3.31 1.32 4.27 2.26 1.44 1.42 2.43 2.89 2.43 5.29v8.05zM1 21.99V21h15.03v.99c0 .55-.45 1-1.01 1H2.01c-.56 0-1.01-.45-1.01-1zm15.03-7c0-4.5-6.72-5.99-8.01-5.99-2.28 0-6.99 1.58-6.99 5.99H16.03z"/></svg>
            </div>
            <div class="kpi__valor"><?= $kpiAlmuerzosHoy ?></div>
            <div class="kpi__etiqueta">Almuerzos PAE</div>
            <div class="kpi__sub">Estudiantes que asistieron hoy</div>
        </div>

        <!-- KPI: Inasistencias -->
        <div class="kpi kpi--rojo">
            <div class="kpi__icono">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            </div>
            <div class="kpi__valor"><?= $kpiFallasHoy ?></div>
            <div class="kpi__etiqueta">Inasistencias hoy</div>
            <div class="kpi__sub">Fallas sin justificar registradas</div>
        </div>

        <!-- KPI: Alertas pendientes -->
        <div class="kpi kpi--naranja">
            <div class="kpi__icono">
                <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
            </div>
            <div class="kpi__valor"><?= $kpiAlertasPendientes ?></div>
            <div class="kpi__etiqueta">Alertas pendientes</div>
            <div class="kpi__sub">Sin notificar al acudiente</div>
        </div>

    </div><!-- /grid-kpis -->


    <!-- ════════════════════════════════════════════════════════════════════
         SECCIÓN 2+4 — PAE (izq.) y CONTROL DOCENTE (der.)
         Dos tarjetas en columnas iguales.
         ════════════════════════════════════════════════════════════════════ -->
    <div class="grid-2col">

        <!-- ── PAE: Desglose por curso ─────────────────────────────────────── -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__titulo">
                    <svg viewBox="0 0 24 24" style="fill:var(--verde)">
                        <path d="M18.06 22.99h1.66c.84 0 1.53-.64 1.63-1.46L23 5.05h-5V1h-1.97v4.05h-4.97l.3 2.34c1.71.47 3.31 1.32 4.27 2.26 1.44 1.42 2.43 2.89 2.43 5.29v8.05zM1 21.99V21h15.03v.99c0 .55-.45 1-1.01 1H2.01c-.56 0-1.01-.45-1.01-1zm15.03-7c0-4.5-6.72-5.99-8.01-5.99-2.28 0-6.99 1.58-6.99 5.99H16.03z"/>
                    </svg>
                    Programa de Alimentación Escolar
                </h2>
                <span class="badge badge--verde">Hoy</span>
            </div>

            <div class="card__body">

                <!-- Total prominente -->
                <div class="pae-total">
                    <div class="pae-total__numero"><?= $kpiAlmuerzosHoy ?></div>
                    <div class="pae-total__label">Almuerzos requeridos hoy</div>
                </div>

                <!-- Desglose por curso -->
                <?php if (!empty($paePorCurso)): ?>
                    <table class="tabla-compacta">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th style="text-align:right">Almuerzos</th>
                                <th style="text-align:right">% del total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paePorCurso as $fila):
                                // Porcentaje de este curso sobre el total PAE
                                $pct = $kpiAlmuerzosHoy > 0
                                    ? round(($fila['almuerzos'] / $kpiAlmuerzosHoy) * 100)
                                    : 0;
                            ?>
                            <tr>
                                <td><span class="curso-tag"><?= htmlspecialchars($fila['curso'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td style="text-align:right; font-weight:700">
                                    <?= $fila['almuerzos'] ?>
                                </td>
                                <td style="text-align:right">
                                    <span style="font-size:.8rem;color:var(--gris-texto)"><?= $pct ?>%</span>
                                    <div class="barra-wrap">
                                        <div class="barra-fill" style="width:<?= $pct ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="vacio">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        <p>Aún no hay registros de asistencia para hoy.</p>
                    </div>
                <?php endif; ?>

            </div><!-- /card__body PAE -->
        </div><!-- /card PAE -->


        <!-- ── Control de docentes ─────────────────────────────────────────── -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__titulo">
                    <svg viewBox="0 0 24 24" style="fill:var(--azul)">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 9h-2V5h2v6zm0 4h-2v-2h2v2z"/>
                    </svg>
                    Control de Docentes
                </h2>
                <div style="display:flex;gap:.5rem">
                    <span class="badge badge--verde"><?= $docentesRegistrados ?> OK</span>
                    <?php if ($docentesPendientes > 0): ?>
                        <span class="badge badge--rojo"><?= $docentesPendientes ?> Pendiente<?= $docentesPendientes > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card__body">
                <?php if (!empty($docentesControl)): ?>
                    <table class="tabla-compacta">
                        <thead>
                            <tr>
                                <th>Docente</th>
                                <th>Estado</th>
                                <th style="text-align:right">Estudiantes</th>
                                <th style="text-align:right">Última hora</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($docentesControl as $doc): ?>
                            <tr>
                                <td style="font-weight:600">
                                    <?= htmlspecialchars($doc['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php if ($doc['estado_registro'] === 'registrado'): ?>
                                        <span class="estado-doc estado-doc--ok">
                                            <span class="dot dot--verde"></span>Registró
                                        </span>
                                    <?php else: ?>
                                        <span class="estado-doc estado-doc--pending">
                                            <span class="dot dot--rojo"></span>Pendiente
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;font-weight:700">
                                    <?php if ($doc['estado_registro'] === 'registrado'): ?>
                                        <?= $doc['estudiantes_marcados'] ?>
                                        <span style="font-size:.72rem;color:var(--gris-texto)">
                                            / <?= $doc['bloques_registrados'] ?> bloque<?= $doc['bloques_registrados'] != 1 ? 's' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--gris-texto)">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;font-size:.82rem;color:var(--gris-texto)">
                                    <?= $doc['hora_ultimo']
                                        ? htmlspecialchars($doc['hora_ultimo'], ENT_QUOTES, 'UTF-8')
                                        : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="vacio">
                        <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12z"/></svg>
                        <p>No hay docentes activos registrados.</p>
                    </div>
                <?php endif; ?>
            </div><!-- /card__body docentes -->
        </div><!-- /card docentes -->

    </div><!-- /grid-2col -->


    <!-- ════════════════════════════════════════════════════════════════════
         SECCIÓN 3 — ALERTAS DE INASISTENCIA
         Tabla ancho completo con estudiantes en riesgo.
         ════════════════════════════════════════════════════════════════════ -->
    <div class="card card--full">
        <div class="card__header">
            <h2 class="card__titulo">
                <svg viewBox="0 0 24 24" style="fill:var(--rojo)">
                    <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
                </svg>
                Alertas de Inasistencia — Últimos 7 días
            </h2>
            <?php if (!empty($estudiantesEnAlerta)): ?>
                <span class="badge badge--rojo"><?= count($estudiantesEnAlerta) ?> estudiante(s) en riesgo</span>
            <?php else: ?>
                <span class="badge badge--verde">Sin alertas activas</span>
            <?php endif; ?>
        </div>

        <div class="card__body">
            <?php if (!empty($estudiantesEnAlerta)): ?>
                <table class="tabla-compacta">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Estudiante</th>
                            <th>Curso</th>
                            <th>Fallas (7 días)</th>
                            <th>Período</th>
                            <th>Correo acudiente</th>
                            <th style="text-align:center">Nivel</th>
                            <th style="text-align:center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantesEnAlerta as $i => $est):
                            // Determinar clase de severidad según número de fallas
                            $claseChip = match(true) {
                                $est['total_fallas'] >= 5 => 'fallas-chip--critico',
                                $est['total_fallas'] >= 4 => 'fallas-chip--alto',
                                default                   => 'fallas-chip--medio',
                            };
                            $labelNivel = match(true) {
                                $est['total_fallas'] >= 5 => 'Crítico',
                                $est['total_fallas'] >= 4 => 'Alto',
                                default                   => 'Medio',
                            };
                            // Formatear fechas para mostrar dd/mm/YYYY
                            $fDesde = date('d/m', strtotime($est['primera_falla']));
                            $fHasta = date('d/m', strtotime($est['ultima_falla']));
                        ?>
                        <tr>
                            <td style="color:var(--gris-texto);font-size:.8rem"><?= $i + 1 ?></td>

                            <td style="font-weight:700">
                                <?= htmlspecialchars($est['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <span class="curso-tag">
                                    <?= htmlspecialchars($est['curso'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <!-- Chip con número de fallas y color según severidad -->
                                <span class="fallas-chip <?= $claseChip ?>">
                                    <?= $est['total_fallas'] ?> falla<?= $est['total_fallas'] > 1 ? 's' : '' ?>
                                </span>
                            </td>

                            <td style="font-size:.82rem;color:var(--gris-texto)">
                                <?= $fDesde ?> → <?= $fHasta ?>
                            </td>

                            <td>
                                <!-- Enlace mailto para notificar al acudiente directamente -->
                                <a class="link-correo"
                                   href="mailto:<?= htmlspecialchars($est['correo_acudiente'], ENT_QUOTES, 'UTF-8') ?>
                                        ?subject=<?= urlencode('Alerta de inasistencia — ' . $est['nombre']) ?>
                                        &body=<?= urlencode("Estimado acudiente,\n\nEl estudiante " . $est['nombre'] . " del curso " . $est['curso'] . " ha acumulado " . $est['total_fallas'] . " inasistencias en los últimos 7 días.\n\nPor favor comuníquese con el colegio.\n\nColegio OEA — SIPAE") ?>">
                                    <?= htmlspecialchars($est['correo_acudiente'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>

                            <td style="text-align:center">
                                <span class="badge <?= match($labelNivel) {
                                    'Crítico' => 'badge--rojo',
                                    'Alto'    => 'badge--naranja',
                                    default   => 'badge--amarillo',
                                } ?>">
                                    <?= $labelNivel ?>
                                </span>
                            </td>

                            <!-- Botón de notificación: dispara AJAX a enviar_alerta.php -->
                            <td style="text-align:center">
                                <button
                                    class="btn-notificar"
                                    data-id="<?= $est['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($est['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                    type="button"
                                    title="Enviar correo al acudiente de <?= htmlspecialchars($est['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                    <!-- Ícono de sobre -->
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                                    </svg>
                                    Notificar acudiente
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php else: ?>
                <div class="vacio">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                    <p>Ningún estudiante acumula 3 o más inasistencias en los últimos 7 días.</p>
                </div>
            <?php endif; ?>
        </div><!-- /card__body alertas -->
    </div><!-- /card alertas -->

</main>

<!-- ══ JAVASCRIPT ═══════════════════════════════════════════════════════════
     Maneja los clics en todos los botones "Notificar acudiente" mediante
     delegación de eventos: un solo listener en el documento captura todos.
     Usa fetch() (AJAX) para no recargar la página.
     ════════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    // ── Helper: mostrar toast flotante en esquina inferior derecha ───────
    function mostrarToast(mensaje, tipo) {
        const previo = document.getElementById('sipae-toast');
        if (previo) previo.remove();

        const icono = tipo === 'ok'
            ? '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>'
            : '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>';

        const toast = document.createElement('div');
        toast.id        = 'sipae-toast';
        toast.className = 'toast toast--' + tipo;
        toast.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true">' + icono + '</svg>' + mensaje;
        document.body.appendChild(toast);

        // El toast desaparece automáticamente tras 4 segundos
        setTimeout(function () { toast.remove(); }, 4000);
    }

    // ── Delegación de eventos: captura clics en cualquier .btn-notificar ─
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-notificar');
        if (!btn) return;

        const estudianteId     = btn.dataset.id;
        const nombreEstudiante = btn.dataset.nombre;

        // Pedir confirmación antes de enviar el correo
        if (!window.confirm(
            '¿Desea enviar un correo al acudiente de ' + nombreEstudiante + '?\n\n' +
            'Se notificará la inasistencia reiterada al correo registrado.'
        )) return;

        // Estado de carga: deshabilitar y mostrar spinner
        btn.disabled  = true;
        btn.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true" style="animation:spin 1s linear infinite">' +
            '<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z"/></svg> Enviando…';

        // Construir datos del formulario para el POST
        const formData = new FormData();
        formData.append('estudiante_id', estudianteId);

        // Llamada AJAX a enviar_alerta.php
        fetch('enviar_alerta.php', {
            method:      'POST',
            credentials: 'same-origin', // incluye la cookie de sesión
            body:        formData,
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (data.ok) {
                // Éxito: botón cambia a "Notificado ✓" en verde
                btn.classList.add('btn-notificar--ok');
                btn.innerHTML =
                    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
                    '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>' +
                    '</svg> Notificado';
                mostrarToast(data.mensaje, 'ok');
            } else {
                // Error controlado desde PHP
                btn.disabled = false;
                btn.classList.add('btn-notificar--error');
                btn.innerHTML =
                    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
                    '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>' +
                    '</svg> Reintentar';
                mostrarToast('Error: ' + data.mensaje, 'error');
            }
        })
        .catch(function (err) {
            // Error de red o respuesta inesperada del servidor
            btn.disabled  = false;
            btn.innerHTML =
                '<svg viewBox="0 0 24 24" aria-hidden="true">' +
                '<path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>' +
                '</svg> Notificar acudiente';
            mostrarToast('Error de conexión. Intenta de nuevo.', 'error');
            console.error('[SIPAE] enviar_alerta:', err);
        });
    });

    // Inyectar keyframes del spinner una sola vez
    const s = document.createElement('style');
    s.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(s);
})();
</script>

</body>
</html>

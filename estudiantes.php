<?php
/**
 * estudiantes.php
 * Pantalla del coordinador para alimentar la tabla `estudiantes`.
 *
 * Dos formas de cargar datos:
 *   1. Formulario manual: agrega un estudiante a la vez.
 *   2. Archivo plano (CSV/TXT): carga varios estudiantes de una vez.
 *      Formato de cada línea, separado por comas:
 *      nombre,curso,nombre_acudiente,parentesco_acudiente,whatsapp_acudiente,correo_acudiente
 *
 * Ambos formularios envían los datos a un archivo aparte que los procesa
 * (procesar_estudiante.php y cargar_estudiantes.php) y luego redirige aquí.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Protección: solo coordinadores autenticados
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

// ── Mensaje de resultado (llega desde procesar_estudiante.php o cargar_estudiantes.php) ──
$alerta = null;

if (isset($_GET['guardado'])) {
    $alerta = ['tipo' => 'exito', 'texto' => 'Estudiante agregado correctamente.'];

} elseif (isset($_GET['cargados'])) {
    $n = (int) $_GET['cargados'];
    $alerta = [
        'tipo'  => 'exito',
        'texto' => $n . ' estudiante(s) cargado(s) desde el archivo.',
    ];

} elseif (isset($_GET['error'])) {
    $textos = [
        'validacion' => 'Faltan datos o el correo del acudiente no es válido.',
        'bd'         => 'Error al guardar en la base de datos. Intenta de nuevo.',
        'archivo'    => 'No se recibió ningún archivo o hubo un problema al subirlo.',
        'formato'    => 'El archivo no tiene ninguna fila válida. Revisa que tenga 6 columnas separadas por coma.',
    ];
    $alerta = [
        'tipo'  => 'error',
        'texto' => $textos[$_GET['error']] ?? 'Ocurrió un error inesperado.',
    ];
}

// ── Listado de estudiantes activos, para ver lo que ya se ha cargado ──────
$estudiantes = $pdo->query(
    'SELECT id, nombre, curso, nombre_acudiente, parentesco_acudiente,
            whatsapp_acudiente, correo_acudiente
       FROM estudiantes
      WHERE activo = 1
      ORDER BY curso ASC, nombre ASC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIPAE — Gestión de Estudiantes</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --verde:        #059669;
            --verde-oscuro: #047857;
            --azul:         #1a56db;
            --rojo:         #dc2626;
            --gris-fondo:   #f0f4f8;
            --gris-borde:   #e2e8f0;
            --gris-texto:   #6b7280;
            --texto:        #1e2a3a;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--gris-fondo);
            color: var(--texto);
            min-height: 100vh;
        }

        .navbar {
            background: var(--verde);
            padding: .875rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            position: sticky;
            top: 0;
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

        .navbar__derecha { display: flex; align-items: center; gap: 1.25rem; }

        .navbar__link {
            color: #fff;
            text-decoration: none;
            font-size: .8rem;
            font-weight: 600;
            opacity: .9;
        }
        .navbar__link:hover { opacity: 1; text-decoration: underline; }

        .navbar__logout {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.18);
            padding: .35rem .875rem;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 600;
        }
        .navbar__logout:hover { background: rgba(255,255,255,.30); }

        .contenedor {
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1.25rem;
        }

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
            margin-bottom: 1.25rem;
            padding-bottom: .75rem;
            border-bottom: 2px solid var(--gris-borde);
        }

        .alerta {
            padding: .875rem 1.125rem;
            border-radius: 10px;
            font-size: .9rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
        .alerta--exito { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }
        .alerta--error { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }

        /* ── Formulario manual ─────────────────────────────────────────────── */
        .grid-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem 1.25rem;
        }

        .campo { display: flex; flex-direction: column; gap: .375rem; }

        .campo label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--gris-texto);
        }

        .campo input, .campo select {
            padding: .575rem .875rem;
            border: 1.5px solid var(--gris-borde);
            border-radius: 8px;
            font-size: .925rem;
            color: var(--texto);
        }

        .campo input:focus, .campo select:focus {
            outline: none;
            border-color: var(--verde);
            box-shadow: 0 0 0 3px rgba(5,150,105,.12);
        }

        .btn {
            padding: .7rem 1.75rem;
            border: none;
            border-radius: 8px;
            font-size: .925rem;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
            margin-top: 1.25rem;
        }
        .btn--verde { background: var(--verde); }
        .btn--verde:hover { background: var(--verde-oscuro); }

        /* ── Carga de archivo ───────────────────────────────────────────────── */
        .formato-ejemplo {
            background: #f8fafc;
            border: 1px solid var(--gris-borde);
            border-radius: 8px;
            padding: .875rem 1.125rem;
            font-family: monospace;
            font-size: .8rem;
            color: var(--texto);
            margin: .75rem 0 1.25rem;
            overflow-x: auto;
        }

        .nota {
            font-size: .82rem;
            color: var(--gris-texto);
            margin-bottom: 1rem;
        }

        input[type="file"] {
            display: block;
            margin-bottom: 1rem;
            font-size: .875rem;
        }

        /* ── Tabla de estudiantes ───────────────────────────────────────────── */
        .tabla-wrapper { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; font-size: .875rem; }

        thead th {
            background: #f8fafc;
            padding: .625rem .875rem;
            text-align: left;
            font-size: .72rem;
            font-weight: 700;
            color: var(--gris-texto);
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 2px solid var(--gris-borde);
        }

        tbody td {
            padding: .625rem .875rem;
            border-bottom: 1px solid var(--gris-borde);
        }

        tbody tr:hover { background: #fafafa; }

        .curso-tag {
            background: #f1f5f9;
            border: 1px solid var(--gris-borde);
            border-radius: 4px;
            padding: .15rem .5rem;
            font-size: .78rem;
            font-weight: 700;
            color: var(--gris-texto);
        }

        .vacio { text-align: center; padding: 2rem; color: var(--gris-texto); }

        @media (max-width: 640px) {
            .grid-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="dashboard_coordinador.php" class="navbar__marca">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.25C17.25 22.15 21 17.25 21 12V7L12 2zm0 2.18l7 3.89V12c0 4.35-3.1 8.4-7 9.43C8.1 20.4 5 16.35 5 12V8.07l7-3.89z"/>
        </svg>
        SIPAE — Estudiantes
    </a>
    <div class="navbar__derecha">
        <a href="dashboard_coordinador.php" class="navbar__link">← Volver al panel</a>
        <a href="?logout=1" class="navbar__logout">Cerrar sesión</a>
    </div>
</nav>

<main class="contenedor">

    <?php if ($alerta): ?>
        <div class="alerta alerta--<?= $alerta['tipo'] ?>">
            <?= htmlspecialchars($alerta['texto'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ── Agregar un estudiante manualmente ──────────────────────────────── -->
    <div class="card">
        <h2 class="card__titulo">Agregar estudiante manualmente</h2>

        <form method="post" action="procesar_estudiante.php">
            <div class="grid-form">
                <div class="campo">
                    <label for="nombre">Nombre del estudiante</label>
                    <input type="text" id="nombre" name="nombre" maxlength="120" required>
                </div>

                <div class="campo">
                    <label for="curso">Curso</label>
                    <input type="text" id="curso" name="curso" maxlength="30" placeholder="Ej: 601" required>
                </div>

                <div class="campo">
                    <label for="nombre_acudiente">Nombre del acudiente</label>
                    <input type="text" id="nombre_acudiente" name="nombre_acudiente" maxlength="120" required>
                </div>

                <div class="campo">
                    <label for="parentesco_acudiente">Parentesco</label>
                    <select id="parentesco_acudiente" name="parentesco_acudiente" required>
                        <option value="">— Selecciona —</option>
                        <option>Madre</option>
                        <option>Padre</option>
                        <option>Abuelo(a)</option>
                        <option>Tío(a)</option>
                        <option>Otro</option>
                    </select>
                </div>

                <div class="campo">
                    <label for="whatsapp_acudiente">WhatsApp del acudiente</label>
                    <input type="text" id="whatsapp_acudiente" name="whatsapp_acudiente" maxlength="20" placeholder="3001234567" required>
                </div>

                <div class="campo">
                    <label for="correo_acudiente">Correo del acudiente</label>
                    <input type="email" id="correo_acudiente" name="correo_acudiente" maxlength="150" required>
                </div>
            </div>

            <button type="submit" class="btn btn--verde">Agregar estudiante</button>
        </form>
    </div>

    <!-- ── Carga masiva por archivo plano ─────────────────────────────────── -->
    <div class="card">
        <h2 class="card__titulo">Cargar estudiantes desde un archivo</h2>

        <p class="nota">
            Archivo .csv o .txt, con una fila por estudiante y estas 6 columnas
            separadas por coma, en este orden (la primera fila puede ser el
            encabezado o el primer estudiante, ambos casos funcionan):
        </p>

        <div class="formato-ejemplo">
            nombre,curso,nombre_acudiente,parentesco_acudiente,whatsapp_acudiente,correo_acudiente<br>
            Sofía López Vargas,601,María Vargas,Madre,3115550001,maria.vargas@gmail.com
        </div>

        <form method="post" action="cargar_estudiantes.php" enctype="multipart/form-data">
            <input type="file" name="archivo" accept=".csv,.txt" required>
            <button type="submit" class="btn btn--verde">Cargar archivo</button>
        </form>
    </div>

    <!-- ── Listado de estudiantes ya registrados ──────────────────────────── -->
    <div class="card">
        <h2 class="card__titulo">Estudiantes registrados (<?= count($estudiantes) ?>)</h2>

        <?php if (empty($estudiantes)): ?>
            <div class="vacio">Aún no hay estudiantes registrados.</div>
        <?php else: ?>
            <div class="tabla-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Estudiante</th>
                            <th>Curso</th>
                            <th>Acudiente</th>
                            <th>Parentesco</th>
                            <th>WhatsApp</th>
                            <th>Correo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantes as $e): ?>
                        <tr>
                            <td style="font-weight:600"><?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="curso-tag"><?= htmlspecialchars($e['curso'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($e['nombre_acudiente'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($e['parentesco_acudiente'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($e['whatsapp_acudiente'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($e['correo_acudiente'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

</body>
</html>

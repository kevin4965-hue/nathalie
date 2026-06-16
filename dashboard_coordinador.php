<?php
/**
 * dashboard_coordinador.php
 * Página de inicio para usuarios con rol 'coordinador'.
 * Protegida: solo accesible con sesión activa y rol correcto.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Verificar sesión y rol
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'coordinador') {
    header('Location: login.php');
    exit;
}

// Cerrar sesión si se solicita
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Coordinador — SIPAE</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; margin: 0; padding: 2rem; }
        .banner { background: #065f46; color: #fff; padding: 1.25rem 1.75rem; border-radius: 10px;
                  display: flex; justify-content: space-between; align-items: center; }
        .banner h1 { font-size: 1.25rem; margin: 0; }
        .banner a  { color: #fff; font-size: .85rem; text-decoration: underline; }
        .content   { margin-top: 2rem; background: #fff; border-radius: 10px;
                     padding: 1.75rem; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
        .content p { color: #6b7280; }
    </style>
</head>
<body>
    <div class="banner">
        <h1>Bienvenida, <?= htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') ?></h1>
        <a href="?logout=1">Cerrar sesión</a>
    </div>
    <div class="content">
        <h2>Panel de Coordinación</h2>
        <p>Desde aquí podrás gestionar alertas, revisar reportes de asistencia y contactar acudientes.</p>
        <p><em>(Módulos en construcción)</em></p>
    </div>
</body>
</html>

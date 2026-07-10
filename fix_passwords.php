<?php
/**
 * fix_passwords.php
 * Utilidad de un solo uso: genera hashes bcrypt reales y actualiza
 * la contraseña de todos los usuarios de prueba en la base de datos.
 *
 * ⚠ IMPORTANTE: elimina este archivo después de usarlo.
 *   No debe quedar accesible en producción.
 */

require_once __DIR__ . '/conexion.php';

// Contraseña en texto plano para todos los usuarios de prueba
const CONTRASENA_PRUEBA = 'Test1234!';

$pdo  = obtenerConexion();
$hash = password_hash(CONTRASENA_PRUEBA, PASSWORD_BCRYPT);

// Actualizar TODOS los usuarios con el hash recién generado
$stmt = $pdo->prepare("UPDATE usuarios SET contrasena = :hash");
$stmt->execute([':hash' => $hash]);
$actualizados = $stmt->rowCount();

// Verificar que el hash funciona antes de mostrar el resultado
$hashValido = password_verify(CONTRASENA_PRUEBA, $hash);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SIPAE — Fix Passwords</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8;
               display: flex; align-items: center; justify-content: center;
               min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 2rem 2.5rem;
                box-shadow: 0 4px 20px rgba(0,0,0,.1); max-width: 520px; width: 100%; }
        h1    { font-size: 1.2rem; margin: 0 0 1.25rem; color: #1e2a3a; }
        .ok   { background: #f0fdf4; border: 1px solid #86efac; color: #15803d;
                border-radius: 8px; padding: .875rem 1rem; margin-bottom: 1rem; font-weight: 600; }
        .dato { background: #f8fafc; border-radius: 8px; padding: 1rem;
                margin-bottom: 1rem; font-size: .9rem; }
        .dato b { display: inline-block; width: 140px; color: #6b7280; }
        .hash  { word-break: break-all; font-family: monospace; font-size: .78rem;
                 background: #f1f5f9; padding: .5rem; border-radius: 6px; margin-top: .5rem; }
        .warn  { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c;
                 border-radius: 8px; padding: .875rem 1rem; font-size: .875rem; margin-top: 1rem; }
        a      { color: #1a56db; font-weight: 700; }
    </style>
</head>
<body>
<div class="card">
    <h1>SIPAE — Actualización de contraseñas</h1>

    <?php if ($hashValido && $actualizados > 0): ?>
        <div class="ok">
            ✓ <?= $actualizados ?> usuario(s) actualizado(s) correctamente.
        </div>

        <div class="dato">
            <div><b>Contraseña:</b> <?= CONTRASENA_PRUEBA ?></div>
            <div style="margin-top:.5rem"><b>Hash generado:</b></div>
            <div class="hash"><?= htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div class="dato">
            <div><b>Verificación:</b>
                <span style="color:#059669;font-weight:700">password_verify() = true ✓</span>
            </div>
        </div>

        <p style="margin:1rem 0 .5rem;font-size:.9rem">
            Ahora puedes iniciar sesión en
            <a href="login.php">login.php</a> con estas credenciales:
        </p>

        <div class="dato" style="font-size:.875rem">
            <div><b>Coordinador:</b> laura.martinez@oea.edu.co</div>
            <div><b>Docente:</b> carlos.herrera@oea.edu.co</div>
            <div style="margin-top:.375rem"><b>Contraseña:</b> Test1234!</div>
        </div>

        <div class="warn">
            ⚠ <strong>Elimina este archivo</strong> una vez que hayas verificado el login.
            No debe quedar accesible en el servidor.<br><br>
            Ruta: <code><?= __FILE__ ?></code>
        </div>

    <?php else: ?>
        <div style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;
                    border-radius:8px;padding:1rem">
            ✗ Algo falló. Verifica que la base de datos esté activa y que
            <code>conexion.php</code> tenga las credenciales correctas.
            <br><br>Hash válido: <?= $hashValido ? 'sí' : 'no' ?> |
            Filas afectadas: <?= $actualizados ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

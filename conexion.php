<?php
/**
 * conexion.php
 * Centraliza la conexión a la base de datos usando PDO.
 * Se incluye con require_once en cualquier archivo que necesite la BD.
 *
 * Seguridad aplicada:
 *  - Las credenciales se definen como constantes (no variables globales mutables).
 *  - PDO::ERRMODE_EXCEPTION lanza excepciones en vez de fallos silenciosos.
 *  - EMULATE_PREPARES en false obliga a usar prepared statements reales en MySQL.
 *  - El charset utf8mb4 en el DSN previene ataques de encoding.
 *  - El bloque catch oculta detalles técnicos al usuario final.
 */

// ---------------------------------------------------------------------------
// CONFIGURACIÓN — En producción, mover estas constantes a un archivo .env
// o a variables de entorno del servidor y NUNCA subirlas al repositorio.
// ---------------------------------------------------------------------------
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'sipae');
define('DB_USER',    'root');       // Cambiar por el usuario de producción
define('DB_PASS',    '');           // Cambiar por la contraseña real
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// FUNCIÓN ÚNICA DE CONEXIÓN
// Retorna un objeto PDO listo para usar.
// Lanza una excepción si la conexión falla (se captura en el llamador).
// ---------------------------------------------------------------------------
function obtenerConexion(): PDO
{
    // DSN (Data Source Name): cadena que identifica la base de datos
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $opciones = [
        // Lanza PDOException en cualquier error → nunca falla en silencio
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

        // Devuelve filas como arrays asociativos por defecto
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        // FALSE = usa prepared statements reales del servidor MySQL,
        // no emulados por PHP. Esto bloquea inyecciones SQL de forma nativa.
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        return $pdo;

    } catch (PDOException $e) {
        // Se registra el error real en el log del servidor (nunca en pantalla)
        error_log('[SIPAE] Error de conexión a BD: ' . $e->getMessage());

        // Al usuario solo se muestra un mensaje genérico
        http_response_code(503);
        die('El servicio no está disponible en este momento. Intente más tarde.');
    }
}

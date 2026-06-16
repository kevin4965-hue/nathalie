-- ==========================================================
-- SIPAE - Sistema Integral de Permanencia, Asistencia
--         y Alimentación Escolar
-- Colegio OEA
-- Stack: PHP + MySQL
-- ==========================================================
-- DESCRIPCIÓN GENERAL:
--   Este script crea la base de datos completa del sistema SIPAE.
--   El orden de creación de tablas es importante:
--     1. usuarios      → no depende de ninguna otra tabla
--     2. estudiantes   → no depende de ninguna otra tabla
--     3. asistencia    → depende de usuarios y estudiantes
--     4. alertas       → depende de estudiantes
--   Al final se insertan datos de prueba y se crean dos vistas
--   de consulta rápida para usar desde PHP.
-- ==========================================================


-- ----------------------------------------------------------
-- CREACIÓN DE LA BASE DE DATOS
-- IF NOT EXISTS evita error si ya existe al ejecutar de nuevo.
-- utf8mb4 soporta tildes, ñ y emojis (superconjunto de utf8).
-- utf8mb4_unicode_ci hace comparaciones sin distinguir mayúsculas.
-- ----------------------------------------------------------
CREATE DATABASE IF NOT EXISTS sipae
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Selecciona la base de datos para que todos los comandos
-- siguientes operen sobre ella.
USE sipae;


-- ==========================================================
-- SECCIÓN 1: DEFINICIÓN DE TABLAS
-- ==========================================================

-- ----------------------------------------------------------
-- TABLA: usuarios
-- Almacena las cuentas de acceso al sistema.
-- Roles posibles:
--   'docente'      → registra asistencia de sus cursos
--   'coordinador'  → gestiona alertas y tiene visión global
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (

    -- Identificador único autoincremental (no puede ser negativo)
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,

    -- Nombre completo del usuario (máx. 120 caracteres)
    nombre        VARCHAR(120)     NOT NULL,

    -- Correo institucional; se usa como nombre de usuario para iniciar sesión
    correo        VARCHAR(150)     NOT NULL,

    -- Contraseña NUNCA se guarda en texto plano.
    -- Se almacena el hash generado por password_hash() de PHP con bcrypt.
    -- VARCHAR(255) es el tamaño recomendado para alojar cualquier hash futuro.
    contrasena    VARCHAR(255)     NOT NULL COMMENT 'Hash bcrypt generado desde PHP',

    -- ENUM restringe los valores a exactamente estos dos; cualquier otro es rechazado por MySQL.
    rol           ENUM('docente','coordinador') NOT NULL DEFAULT 'docente',

    -- Permite desactivar un usuario sin eliminarlo (borrado lógico).
    -- 1 = activo, 0 = inactivo.
    activo        TINYINT(1)       NOT NULL DEFAULT 1,

    -- Se registra automáticamente la fecha y hora de creación de la cuenta.
    creado_en     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Llave primaria: identifica de forma única cada fila de la tabla.
    CONSTRAINT pk_usuarios   PRIMARY KEY (id),

    -- Restricción de unicidad: no pueden existir dos usuarios con el mismo correo.
    CONSTRAINT uq_usr_correo UNIQUE (correo)

) ENGINE=InnoDB; -- InnoDB es obligatorio para que funcionen las llaves foráneas en MySQL.


-- ----------------------------------------------------------
-- TABLA: estudiantes
-- Almacena el listado de alumnos matriculados.
-- No tiene llave foránea propia; otras tablas la referencian.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS estudiantes (

    -- Identificador único del estudiante
    id                       INT UNSIGNED  NOT NULL AUTO_INCREMENT,

    -- Nombre completo del estudiante
    nombre                   VARCHAR(120)  NOT NULL,

    -- Grado/grupo al que pertenece. Se usa VARCHAR para soportar
    -- formatos como '601', '1101' o 'Primero A'.
    curso                    VARCHAR(30)   NOT NULL COMMENT 'Ej: 601, 1101, Primero A',

    -- Texto libre con nombre completo del acudiente, teléfono y parentesco.
    -- Se usa TEXT porque puede ser extenso y su estructura varía.
    datos_contacto_acudiente TEXT          NOT NULL COMMENT 'Nombre, teléfono, parentesco',

    -- Correo del acudiente para el envío de notificaciones automáticas.
    correo_acudiente         VARCHAR(150)  NOT NULL,

    -- Borrado lógico: permite retirar al estudiante sin perder su historial.
    activo                   TINYINT(1)   NOT NULL DEFAULT 1,

    -- Fecha y hora en que se registró el estudiante en el sistema.
    creado_en                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_estudiantes PRIMARY KEY (id)

) ENGINE=InnoDB;


-- ----------------------------------------------------------
-- TABLA: asistencia
-- Registra el estado de cada estudiante por bloque de clase.
-- Un mismo estudiante puede tener varios registros por día
-- (uno por cada bloque/hora de clase).
--
-- Estados posibles:
--   'asistió'    → presente y puntual
--   'falla'      → ausente sin justificación
--   'justificado'→ ausente con justificación válida (cita médica, etc.)
--   'novedad'    → presente pero con alguna situación especial
--                  (llegada tarde, conflicto, etc.)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS asistencia (

    -- Identificador único del registro de asistencia
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,

    -- FK al estudiante al que corresponde este registro
    estudiante_id INT UNSIGNED     NOT NULL,

    -- FK al docente que tomó la asistencia en ese bloque
    docente_id    INT UNSIGNED     NOT NULL,

    -- Fecha en que se registró la asistencia (sin hora)
    fecha         DATE             NOT NULL,

    -- Número de bloque u hora de clase dentro del día.
    -- Permite diferenciar el bloque 1 del bloque 2 para el mismo estudiante y día.
    bloque_clase  TINYINT UNSIGNED NOT NULL COMMENT 'Número de bloque/hora de clase',

    -- Estado de asistencia restringido a los cuatro valores del negocio.
    estado        ENUM('asistió','falla','justificado','novedad') NOT NULL,

    -- Campo opcional para notas adicionales (motivo de la novedad, etc.)
    observacion   VARCHAR(255)     NULL DEFAULT NULL,

    -- Marca de tiempo automática del momento exacto del registro.
    registrado_en TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_asistencia PRIMARY KEY (id),

    -- FK a estudiantes: ON UPDATE CASCADE actualiza la FK si cambia el id del estudiante.
    -- ON DELETE RESTRICT impide borrar un estudiante que ya tiene asistencias registradas.
    CONSTRAINT fk_asist_estudiante FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes (id) ON UPDATE CASCADE ON DELETE RESTRICT,

    -- FK a usuarios: misma lógica; el docente no puede eliminarse si tomó asistencias.
    CONSTRAINT fk_asist_docente FOREIGN KEY (docente_id)
        REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT,

    -- Índice UNIQUE compuesto: garantiza que no se registre dos veces
    -- el mismo bloque para el mismo estudiante en el mismo día.
    -- Esto previene duplicados sin importar qué docente haga el registro.
    CONSTRAINT uq_asist_dia_bloque UNIQUE (estudiante_id, fecha, bloque_clase)

) ENGINE=InnoDB;


-- ----------------------------------------------------------
-- TABLA: alertas
-- Guarda las alarmas generadas por el sistema cuando se detecta
-- un patrón de riesgo en un estudiante (inasistencias, deserción, etc.).
-- El coordinador las revisa y las marca como 'notificado'
-- una vez que contactó al acudiente.
--
-- Estados posibles:
--   'pendiente'   → creada pero el acudiente aún no fue notificado
--   'notificado'  → el acudiente fue contactado; se registra la fecha
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS alertas (

    -- Identificador único de la alerta
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,

    -- FK al estudiante sobre el que recae la alerta
    estudiante_id INT UNSIGNED  NOT NULL,

    -- Categoría de la alerta. Ejemplos:
    --   'inasistencia_reiterada', 'riesgo_desercion', 'riesgo_convivencia'
    tipo_alerta   VARCHAR(80)   NOT NULL COMMENT 'Ej: inasistencia_reiterada, riesgo_desercion',

    -- Texto libre con el detalle de la situación detectada
    descripcion   TEXT          NULL DEFAULT NULL,

    -- Fecha en que se originó o detectó la situación de alerta
    fecha         DATE          NOT NULL,

    -- Estado del flujo de atención de la alerta
    estado        ENUM('pendiente','notificado') NOT NULL DEFAULT 'pendiente',

    -- Se llena SOLO cuando el coordinador notifica al acudiente.
    -- NULL indica que aún no se ha realizado la notificación.
    notificado_en TIMESTAMP     NULL DEFAULT NULL COMMENT 'Fecha en que se envió la notificación',

    -- Fecha y hora automática en que el sistema creó la alerta
    creado_en     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_alertas PRIMARY KEY (id),

    -- FK a estudiantes: no se puede eliminar un estudiante con alertas registradas.
    CONSTRAINT fk_alerta_estudiante FOREIGN KEY (estudiante_id)
        REFERENCES estudiantes (id) ON UPDATE CASCADE ON DELETE RESTRICT

) ENGINE=InnoDB;


-- ==========================================================
-- SECCIÓN 2: DATOS DE PRUEBA
-- Permiten probar el sistema sin necesidad de ingresar datos
-- manualmente. Cubren escenarios reales: asistencias normales,
-- fallas repetidas, justificados, novedades y alertas generadas.
-- ==========================================================

-- ----------------------------------------------------------
-- INSERT: usuarios
-- Contraseña en texto plano de TODOS: Test1234!
-- El hash fue generado con: password_hash('Test1234!', PASSWORD_BCRYPT)
-- En producción cada usuario tendrá su propio hash único.
-- ----------------------------------------------------------
INSERT INTO usuarios (nombre, correo, contrasena, rol) VALUES
-- id=1 → coordinadora con visión global del sistema
('Laura Martínez',   'laura.martinez@oea.edu.co',   '$2y$12$XKpD1VqU3kDNoeRZVLj7OOqSO9z4AQXKhRs5vJwZs2mYbPGNiIhwW', 'coordinador'),
-- id=2 → docente a cargo de grado 601
('Carlos Herrera',   'carlos.herrera@oea.edu.co',   '$2y$12$XKpD1VqU3kDNoeRZVLj7OOqSO9z4AQXKhRs5vJwZs2mYbPGNiIhwW', 'docente'),
-- id=3 → docente a cargo de grados 701 y 901
('Patricia Suárez',  'patricia.suarez@oea.edu.co',  '$2y$12$XKpD1VqU3kDNoeRZVLj7OOqSO9z4AQXKhRs5vJwZs2mYbPGNiIhwW', 'docente'),
-- id=4 → docente a cargo de grados 901 y 1101
('Andrés Rodríguez', 'andres.rodriguez@oea.edu.co', '$2y$12$XKpD1VqU3kDNoeRZVLj7OOqSO9z4AQXKhRs5vJwZs2mYbPGNiIhwW', 'docente');


-- ----------------------------------------------------------
-- INSERT: estudiantes
-- Dos estudiantes por cada curso para simular un grupo pequeño.
-- Los ids se asignan automáticamente del 1 al 8.
-- ----------------------------------------------------------
INSERT INTO estudiantes (nombre, curso, datos_contacto_acudiente, correo_acudiente) VALUES
-- id=1  - Grado 601
('Sofía López Vargas',    '601',  'María Vargas (Madre) - 311 555 0001',  'maria.vargas@gmail.com'),
-- id=2  - Grado 601 (estudiante con fallas reiteradas en los datos de asistencia)
('Juan Pablo Gómez',      '601',  'Roberto Gómez (Padre) - 312 555 0002', 'roberto.gomez@hotmail.com'),
-- id=3  - Grado 701
('Valeria Rincón Torres', '701',  'Ana Torres (Madre) - 313 555 0003',    'ana.torres@gmail.com'),
-- id=4  - Grado 701
('Miguel Ángel Pérez',    '701',  'Luis Pérez (Padre) - 314 555 0004',    'luis.perez@gmail.com'),
-- id=5  - Grado 901
('Isabella Castro Muñoz', '901',  'Claudia Muñoz (Madre) - 315 555 0005', 'claudia.munoz@yahoo.com'),
-- id=6  - Grado 901 (estudiante con riesgo de deserción en los datos de alertas)
('Santiago Rueda Pardo',  '901',  'Héctor Rueda (Padre) - 316 555 0006',  'hector.rueda@gmail.com'),
-- id=7  - Grado 1101
('Mariana Díaz Flores',   '1101', 'Elena Flores (Madre) - 317 555 0007',  'elena.flores@gmail.com'),
-- id=8  - Grado 1101
('Tomás Morales Nieto',   '1101', 'Jorge Morales (Padre) - 318 555 0008', 'jorge.morales@hotmail.com');


-- ----------------------------------------------------------
-- INSERT: asistencia
-- Se simulan tres días de clase: 10, 11 y 12 de junio de 2026.
-- El estudiante id=2 (Juan Pablo) acumula 4 fallas seguidas,
-- lo que debería disparar una alerta en el sistema.
-- Formato de cada fila: (estudiante_id, docente_id, fecha, bloque, estado, observacion)
-- ----------------------------------------------------------
INSERT INTO asistencia (estudiante_id, docente_id, fecha, bloque_clase, estado, observacion) VALUES

-- === DÍA 1: 2026-06-10 ===
(1, 2, '2026-06-10', 1, 'asistió',     NULL),                                       -- Sofía, bloque 1, presente
(1, 2, '2026-06-10', 2, 'asistió',     NULL),                                       -- Sofía, bloque 2, presente
(2, 2, '2026-06-10', 1, 'falla',       'No se presentó sin aviso'),                 -- Juan Pablo, bloque 1, falla #1
(2, 2, '2026-06-10', 2, 'falla',       NULL),                                       -- Juan Pablo, bloque 2, falla #2
(3, 3, '2026-06-10', 1, 'justificado', 'Cita médica certificada'),                  -- Valeria, ausente con excusa
(4, 3, '2026-06-10', 1, 'asistió',     NULL),                                       -- Miguel Ángel, presente
(5, 4, '2026-06-10', 1, 'asistió',     NULL),                                       -- Isabella, presente
(6, 4, '2026-06-10', 1, 'novedad',     'Estudiante llegó tarde por paro de transporte'), -- Santiago, novedad

-- === DÍA 2: 2026-06-11 ===
(1, 2, '2026-06-11', 1, 'asistió',     NULL),                                       -- Sofía, presente
(2, 2, '2026-06-11', 1, 'falla',       'Tercera falla consecutiva'),                -- Juan Pablo, falla #3
(3, 3, '2026-06-11', 1, 'asistió',     NULL),                                       -- Valeria, presente
(4, 3, '2026-06-11', 1, 'falla',       NULL),                                       -- Miguel Ángel, falla
(7, 4, '2026-06-11', 1, 'asistió',     NULL),                                       -- Mariana, presente
(8, 4, '2026-06-11', 1, 'asistió',     NULL),                                       -- Tomás, presente

-- === DÍA 3: 2026-06-12 ===
(1, 2, '2026-06-12', 1, 'asistió',     NULL),                                       -- Sofía, presente
(2, 2, '2026-06-12', 1, 'falla',       'Cuarta falla consecutiva — se genera alerta'), -- Juan Pablo, falla #4 → alerta
(5, 3, '2026-06-12', 1, 'asistió',     NULL),                                       -- Isabella, presente
(6, 3, '2026-06-12', 1, 'justificado', 'Diligencia familiar notificada'),           -- Santiago, justificado
(7, 4, '2026-06-12', 1, 'novedad',     'Conflicto en descanso, remitido a coordinación'), -- Mariana, novedad → alerta
(8, 4, '2026-06-12', 1, 'asistió',     NULL);                                       -- Tomás, presente


-- ----------------------------------------------------------
-- INSERT: alertas
-- Cuatro alertas que ilustran los distintos tipos y estados
-- que el sistema puede manejar.
-- ----------------------------------------------------------
INSERT INTO alertas (estudiante_id, tipo_alerta, descripcion, fecha, estado, notificado_en) VALUES

-- Alerta ya atendida: Juan Pablo (id=2) con 4 fallas → coordinadora ya llamó al acudiente
(2, 'inasistencia_reiterada',
    'El estudiante acumula 4 fallas consecutivas entre el 10 y el 12 de junio de 2026. Se requiere contacto con acudiente.',
    '2026-06-12', 'notificado', '2026-06-12 14:30:00'),

-- Alerta pendiente: Miguel Ángel (id=4) con 2 fallas, en observación
(4, 'inasistencia_reiterada',
    'El estudiante registra 2 fallas en la semana. Monitorear evolución.',
    '2026-06-11', 'pendiente', NULL),

-- Alerta pendiente: Mariana (id=7) involucrada en un conflicto de convivencia
(7, 'riesgo_convivencia',
    'Estudiante involucrada en conflicto durante el descanso. Pendiente seguimiento de coordinación.',
    '2026-06-12', 'pendiente', NULL),

-- Alerta pendiente: Santiago (id=6) con ausentismo crónico y posible riesgo de abandono
(6, 'riesgo_desercion',
    'Ausentismo frecuente en el último mes. Se recomienda reunión con acudiente y revisión de situación socioeconómica.',
    '2026-06-12', 'pendiente', NULL);


-- ==========================================================
-- SECCIÓN 3: VISTAS
-- Las vistas son consultas guardadas. Desde PHP basta con
-- hacer SELECT * FROM nombre_vista para obtener el resultado.
-- Se usan CREATE OR REPLACE para poder actualizar la definición
-- sin tener que borrar y volver a crear la vista.
-- ==========================================================

-- ----------------------------------------------------------
-- VISTA: v_fallas_mes_actual
-- Cuenta cuántas fallas tiene cada estudiante en el mes en curso.
-- Útil para el panel de coordinación y para identificar
-- candidatos a generar una alerta de inasistencia.
--
-- DATE_FORMAT(CURDATE(), '%Y-%m-01') calcula el primer día
-- del mes actual de forma dinámica, sin hardcodear fechas.
-- ----------------------------------------------------------
CREATE OR REPLACE VIEW v_fallas_mes_actual AS
SELECT
    e.id            AS estudiante_id,
    e.nombre        AS estudiante,    -- nombre del estudiante para mostrar en pantalla
    e.curso,                          -- curso para agrupar por grado en la vista del coordinador
    COUNT(a.id)     AS total_fallas   -- cantidad de registros con estado 'falla' en el mes
FROM estudiantes e
-- JOIN trae solo los estudiantes que tienen al menos un registro de asistencia
JOIN asistencia a ON a.estudiante_id = e.id
WHERE a.estado = 'falla'
  -- Filtra desde el día 1 del mes actual hasta hoy
  AND a.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
GROUP BY e.id, e.nombre, e.curso
-- Ordena de mayor a menor para mostrar primero los casos más críticos
ORDER BY total_fallas DESC;


-- ----------------------------------------------------------
-- VISTA: v_alertas_pendientes
-- Muestra todas las alertas que aún no han sido atendidas,
-- junto con los datos del estudiante y el correo del acudiente.
-- Desde PHP se puede iterar sobre esta vista para enviar
-- correos automáticos o mostrársela al coordinador.
-- ----------------------------------------------------------
CREATE OR REPLACE VIEW v_alertas_pendientes AS
SELECT
    al.id            AS alerta_id,        -- id de la alerta para actualizarla luego
    e.nombre         AS estudiante,       -- nombre del estudiante afectado
    e.curso,                              -- curso, para contexto del coordinador
    e.correo_acudiente,                   -- correo de destino para la notificación
    al.tipo_alerta,                       -- categoría de la alerta
    al.descripcion,                       -- detalle de la situación
    al.fecha,                             -- fecha en que se originó la alerta
    al.estado                             -- siempre 'pendiente' en esta vista
FROM alertas al
-- JOIN para obtener los datos del estudiante en la misma consulta
JOIN estudiantes e ON e.id = al.estudiante_id
-- Filtra solo las alertas que todavía no se han notificado
WHERE al.estado = 'pendiente'
-- Las más recientes primero
ORDER BY al.fecha DESC;

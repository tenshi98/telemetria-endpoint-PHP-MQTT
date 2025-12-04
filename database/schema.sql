-- ============================================================================
-- Schema de Base de Datos para Sistema de Telemetría
-- Motor: MySQL 5.7+
-- Descripción: Estructura completa para almacenar datos de telemetría,
--              dispositivos y errores
-- ============================================================================

-- Crear base de datos si no existe
CREATE DATABASE IF NOT EXISTS telemetria
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE telemetria;

-- ============================================================================
-- Tabla: equipos_telemetria
-- Descripción: Almacena información de los dispositivos de telemetría
-- ============================================================================
CREATE TABLE IF NOT EXISTS equipos_telemetria (
    idTelemetria INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Identificador VARCHAR(255) NOT NULL,
    Nombre VARCHAR(255) NOT NULL,
    UltimaConexion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    TiempoFueraLinea TIME DEFAULT '00:00:00',

    PRIMARY KEY (idTelemetria),
    UNIQUE KEY uk_identificador (Identificador),
    INDEX idx_ultima_conexion (UltimaConexion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Tabla: equipos_telemetria_datos
-- Descripción: Almacena las mediciones de telemetría de cada dispositivo
-- ============================================================================
CREATE TABLE IF NOT EXISTS equipos_telemetria_datos (
    idMedicion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idTelemetria INT UNSIGNED NOT NULL,
    Fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Latitud DECIMAL(9, 6),
    Longitud DECIMAL(9, 6),
    Distancia DECIMAL(9, 6),
    Sensor_1 DECIMAL(9, 6),
    Sensor_2 DECIMAL(9, 6),
    Sensor_3 DECIMAL(9, 6),
    Sensor_4 DECIMAL(9, 6),
    Sensor_5 DECIMAL(9, 6),

    PRIMARY KEY (idMedicion),
    INDEX idx_telemetria_fecha (idTelemetria, Fecha),
    INDEX idx_fecha (Fecha),

    CONSTRAINT fk_datos_telemetria
        FOREIGN KEY (idTelemetria)
        REFERENCES equipos_telemetria(idTelemetria)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Tabla: equipos_telemetria_errores
-- Descripción: Almacena errores y eventos anómalos del sistema
-- ============================================================================
CREATE TABLE IF NOT EXISTS equipos_telemetria_errores (
    idError BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idTelemetria INT UNSIGNED,
    Identificador VARCHAR(255),
    Fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    descripcion TEXT,

    PRIMARY KEY (idError),
    INDEX idx_telemetria_fecha (idTelemetria, Fecha),
    INDEX idx_identificador (Identificador),
    INDEX idx_fecha (Fecha),

    CONSTRAINT fk_errores_telemetria
        FOREIGN KEY (idTelemetria)
        REFERENCES equipos_telemetria(idTelemetria)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Vistas útiles
-- ============================================================================

-- Vista: Última posición de cada dispositivo
CREATE OR REPLACE VIEW v_ultima_posicion AS
SELECT
    et.idTelemetria,
    et.Identificador,
    et.Nombre,
    et.UltimaConexion,
    etd.Latitud,
    etd.Longitud,
    etd.Fecha AS FechaUltimaMedicion
FROM equipos_telemetria et
LEFT JOIN (
    SELECT
        idTelemetria,
        Latitud,
        Longitud,
        Fecha,
        ROW_NUMBER() OVER (PARTITION BY idTelemetria ORDER BY Fecha DESC) as rn
    FROM equipos_telemetria_datos
) etd ON et.idTelemetria = etd.idTelemetria AND etd.rn = 1;

-- Vista: Resumen de errores por dispositivo
CREATE OR REPLACE VIEW v_resumen_errores AS
SELECT
    et.idTelemetria,
    et.Identificador,
    et.Nombre,
    COUNT(ete.idError) AS TotalErrores,
    MAX(ete.Fecha) AS UltimoError
FROM equipos_telemetria et
LEFT JOIN equipos_telemetria_errores ete ON et.idTelemetria = ete.idTelemetria
GROUP BY et.idTelemetria, et.Identificador, et.Nombre;

-- ============================================================================
-- Comentarios en las tablas
-- ============================================================================
ALTER TABLE equipos_telemetria
    COMMENT = 'Catálogo de dispositivos de telemetría registrados';

ALTER TABLE equipos_telemetria_datos
    COMMENT = 'Mediciones de telemetría recibidas de los dispositivos';

ALTER TABLE equipos_telemetria_errores
    COMMENT = 'Registro de errores y eventos anómalos del sistema';

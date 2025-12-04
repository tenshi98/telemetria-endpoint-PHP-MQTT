-- ============================================================================
-- Datos de prueba para Sistema de Telemetría
-- ============================================================================

USE telemetria;

-- ============================================================================
-- Insertar dispositivos de prueba
-- ============================================================================
INSERT INTO equipos_telemetria (Identificador, Nombre, UltimaConexion, TiempoFueraLinea) VALUES
('DEVICE001', 'Vehículo 1 - Zona Norte', '2025-11-27 10:00:00', '00:30:00'),
('DEVICE002', 'Vehículo 2 - Zona Sur', '2025-11-27 10:15:00', '01:00:00'),
('DEVICE003', 'Vehículo 3 - Zona Este', '2025-11-27 10:30:00', '00:45:00'),
('DEVICE004', 'Vehículo 4 - Zona Oeste', '2025-11-27 10:45:00', '00:20:00'),
('DEVICE005', 'Sensor Fijo 1 - Centro', '2025-11-27 11:00:00', '02:00:00');

-- ============================================================================
-- Insertar datos de telemetría de ejemplo
-- ============================================================================

-- Datos para DEVICE001 (Buenos Aires - movimiento por la ciudad)
INSERT INTO equipos_telemetria_datos (idTelemetria, Fecha, Latitud, Longitud, Distancia, Sensor_1, Sensor_2, Sensor_3) VALUES
(1, '2025-11-27 10:00:00', -34.603722, -58.381592, 0.000000, 25.5, 60.2, 1013.25),
(1, '2025-11-27 10:05:00', -34.605123, -58.383456, 215.340000, 26.1, 62.5, 1013.20),
(1, '2025-11-27 10:10:00', -34.607890, -58.385234, 342.120000, 26.8, 63.1, 1013.18);

-- Datos para DEVICE002 (movimiento en otra zona)
INSERT INTO equipos_telemetria_datos (idTelemetria, Fecha, Latitud, Longitud, Distancia, Sensor_1, Sensor_2) VALUES
(2, '2025-11-27 10:15:00', -34.620000, -58.390000, 0.000000, 24.3, 58.7),
(2, '2025-11-27 10:20:00', -34.622500, -58.392000, 310.450000, 24.8, 59.2);

-- Datos para DEVICE003
INSERT INTO equipos_telemetria_datos (idTelemetria, Fecha, Latitud, Longitud, Distancia, Sensor_1) VALUES
(3, '2025-11-27 10:30:00', -34.615000, -58.375000, 0.000000, 27.2),
(3, '2025-11-27 10:35:00', -34.617000, -58.377000, 280.120000, 27.5);

-- Datos para DEVICE004
INSERT INTO equipos_telemetria_datos (idTelemetria, Fecha, Latitud, Longitud, Distancia, Sensor_1, Sensor_2, Sensor_3, Sensor_4, Sensor_5) VALUES
(4, '2025-11-27 10:45:00', -34.610000, -58.395000, 0.000000, 23.5, 65.0, 1012.50, 100.0, 50.5);

-- Datos para DEVICE005 (sensor fijo - sin movimiento)
INSERT INTO equipos_telemetria_datos (idTelemetria, Fecha, Latitud, Longitud, Distancia, Sensor_1, Sensor_2, Sensor_3) VALUES
(5, '2025-11-27 11:00:00', -34.603000, -58.380000, 0.000000, 25.0, 60.0, 1013.00),
(5, '2025-11-27 11:05:00', -34.603000, -58.380000, 0.000000, 25.2, 60.5, 1013.05),
(5, '2025-11-27 11:10:00', -34.603000, -58.380000, 0.000000, 25.4, 61.0, 1013.10);

-- ============================================================================
-- Insertar algunos errores de ejemplo
-- ============================================================================
INSERT INTO equipos_telemetria_errores (idTelemetria, Identificador, Fecha, descripcion) VALUES
(NULL, 'UNKNOWN_DEVICE', '2025-11-27 09:00:00', 'Dispositivo no existe en la base de datos'),
(1, 'DEVICE001', '2025-11-27 09:30:00', 'Dispositivo estuvo fuera de línea 2400 segundos (máximo permitido: 1800 segundos)'),
(2, 'DEVICE002', '2025-11-27 09:45:00', 'Campos requeridos faltantes: Latitud');

-- ============================================================================
-- Verificar datos insertados
-- ============================================================================
SELECT 'Dispositivos registrados:' AS Info;
SELECT * FROM equipos_telemetria;

SELECT 'Total de mediciones por dispositivo:' AS Info;
SELECT
    et.Identificador,
    et.Nombre,
    COUNT(etd.idMedicion) AS TotalMediciones
FROM equipos_telemetria et
LEFT JOIN equipos_telemetria_datos etd ON et.idTelemetria = etd.idTelemetria
GROUP BY et.idTelemetria, et.Identificador, et.Nombre;

SELECT 'Errores registrados:' AS Info;
SELECT * FROM equipos_telemetria_errores;

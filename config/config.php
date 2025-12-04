<?php
/*
* Configuración principal del sistema de telemetría
*
* Este archivo contiene todas las configuraciones necesarias para el funcionamiento
* del sistema, incluyendo credenciales de base de datos, Redis, rate limiting y logging.
*
* @author Sistema de Telemetría
* @version 1.0.0
*
*/

// Prevenir acceso directo
defined('APP_ACCESS') or define('APP_ACCESS', true);

return [
    /*** Configuración de Base de Datos MySQL ***/
    'database' => [
        'driver'    => getenv('DB_DRIVER') ?: 'mysql',
        'host'      => getenv('DB_HOST') ?: 'localhost',
        'port'      => getenv('DB_PORT') ?: 3306,
        'database'  => getenv('DB_NAME') ?: 'telemetria',
        'username'  => getenv('DB_USER') ?: 'root',
        'password'  => getenv('DB_PASS') ?: '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options'   => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    /*** Configuración de Redis ***/
    'redis' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: 6379,
        'password' => getenv('REDIS_PASS') ?: null,
        'database' => getenv('REDIS_DB') ?: 0,
        'timeout'  => 2.5,          // Timeout de conexión en segundos
        'prefix'   => 'telemetry:', // Prefijo para todas las claves
        'ttl'      => 86400,        // TTL por defecto: 24 horas
    ],

    /*** Configuración de Rate Limiting ***/
    'rate_limit' => [
        'enabled'                 => getenv('RATE_LIMIT_ENABLED') !== 'false',
        'delay_ms'                => (int)(getenv('RATE_LIMIT_DELAY_MS') ?: 100), // Delay entre requests en milisegundos
        'max_requests_per_minute' => (int)(getenv('RATE_LIMIT_MAX_PER_MIN') ?: 60),
    ],

    /*** Configuración de Logging ***/
    'logging' => [
        'enabled'       => getenv('LOG_ENABLED') !== 'false',
        'path'          => getenv('LOG_PATH') ?: __DIR__ . '/../logs',
        'level'         => getenv('LOG_LEVEL') ?: 'INFO', // INFO, WARNING, ERROR
        'max_file_size' => 10 * 1024 * 1024, // 10 MB
        'date_format'   => 'Y-m-d H:i:s',
        'files' => [
            'devices' => 'devices',              // Logs por dispositivo: devices/DEVICE001.log
            'invalid' => 'invalid_requests.log', // Requests sin identificador válido
            'system'  => 'system.log',           // Logs del sistema
            'errors'  => 'errors.log',           // Errores generales
        ],
    ],

    /*** Configuración de Validación ***/
    'validation' => [
        'required_fields'       => ['Identificador', 'Latitud', 'Longitud'],
        'optional_fields'       => ['Distancia', 'Sensor_1', 'Sensor_2', 'Sensor_3', 'Sensor_4', 'Sensor_5'],
        'max_identifier_length' => 255,
        'coordinate_precision'  => 6, // Decimales para lat/lon
    ],

    /*** Configuración de Aplicación ***/
    'app' => [
        'timezone' => getenv('APP_TIMEZONE') ?: 'America/Chile/Santiago',
        'debug'    => getenv('APP_DEBUG') === 'true',
        'version'  => '1.0.0',
    ],

    /*** Configuración de Cálculo de Distancia ***/
    'geo' => [
        'earth_radius_meters'    => 6371000, // Radio de la Tierra en metros
        'min_distance_threshold' => 0.001,   // Distancia mínima para considerar movimiento (metros)
    ],

    /*** Configuración de MQTT ***/
    'mqtt' => [
        'broker_host'   => getenv('MQTT_BROKER_HOST') ?: 'localhost',
        'broker_port'   => (int)(getenv('MQTT_BROKER_PORT') ?: 1883),
        'client_id'     => getenv('MQTT_CLIENT_ID') ?: 'telemetry_subscriber_' . uniqid(),
        'username'      => getenv('MQTT_USERNAME') ?: null,
        'password'      => getenv('MQTT_PASSWORD') ?: null,
        'topics'        => explode(',', getenv('MQTT_TOPICS') ?: 'telemetry/#'),
        'qos'           => (int)(getenv('MQTT_QOS') ?: 1),
        'clean_session' => getenv('MQTT_CLEAN_SESSION') !== 'false',
        'keepalive'     => (int)(getenv('MQTT_KEEPALIVE') ?: 60),
    ],
];

#!/usr/bin/env php
<?php

/*
*=================================================     Detalles    =================================================
*
* Daemon MQTT para recepción de datos de telemetría
*
*=================================================    Descripcion  =================================================
*
* Script daemon que mantiene una conexión activa con el broker MQTT,
* escucha mensajes entrantes y los procesa usando el sistema de telemetría.
*
*===================================================================================================================
*/

// Definir constante de acceso
define('APP_ACCESS', true);

// Configurar zona horaria
date_default_timezone_set('America/Chile/Santiago');

// Autoloader de Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Importar clases necesarias
use Telemetry\Database\MySQLDatabase;
use Telemetry\Cache\RedisCache;
use Telemetry\Logger\Logger;
use Telemetry\Validation\Validator;
use Telemetry\RateLimit\RateLimiter;
use Telemetry\Utils\GeoCalculator;
use Telemetry\Service\TelemetryService;
use Telemetry\MQTT\MQTTSubscriber;

// Variables globales para manejo de señales
$running        = true;
$mqttSubscriber = null;

/*
* Maneja señales del sistema para shutdown graceful
*/
function signalHandler($signal)
{
    global $running, $mqttSubscriber, $logger;

    $signals = [
        SIGTERM => 'SIGTERM',
        SIGINT  => 'SIGINT',
        SIGHUP  => 'SIGHUP'
    ];

    $signalName = $signals[$signal] ?? "Signal $signal";

    if (isset($logger)) {
        $logger->info("Señal recibida: $signalName - Iniciando shutdown graceful");
    } else {
        echo "Señal recibida: $signalName - Iniciando shutdown graceful\n";
    }

    $running = false;

    if ($mqttSubscriber !== null) {
        $mqttSubscriber->stop();
    }
}

// Registrar manejadores de señales
pcntl_signal(SIGTERM, 'signalHandler');
pcntl_signal(SIGINT, 'signalHandler');
pcntl_signal(SIGHUP, 'signalHandler');

try {
    // Cargar configuración
    $config = require_once __DIR__ . '/../config/config.php';

    // Configurar zona horaria desde config
    date_default_timezone_set($config['app']['timezone']);

    echo "=================================================\n";
    echo "  Sistema de Telemetría - MQTT Daemon\n";
    echo "  Versión: {$config['app']['version']}\n";
    echo "=================================================\n\n";

    // Inicializar componentes
    echo "[INIT] Inicializando componentes del sistema...\n";

    $db               = new MySQLDatabase($config['database']);
    $cache            = new RedisCache($config['redis']);
    $logger           = new Logger($config['logging']);
    $validator        = new Validator($config['validation']);
    $rateLimiter      = new RateLimiter($cache, $config['rate_limit']);
    $geoCalculator    = new GeoCalculator($config['geo']['earth_radius_meters']);
    $telemetryService = new TelemetryService($db, $cache, $logger, $geoCalculator);

    echo "[INIT] Componentes inicializados\n";

    // Conectar a base de datos y Redis
    echo "[CONN] Conectando a MySQL...\n";
    $db->connect();
    echo "[CONN] Conectado a MySQL\n";

    echo "[CONN] Conectando a Redis...\n";
    $cache->connect();
    echo "[CONN] Conectado a Redis\n";

    // Inicializar MQTT Subscriber
    echo "[MQTT] Inicializando cliente MQTT...\n";
    $mqttSubscriber = new MQTTSubscriber($config['mqtt'], $logger);

    // Configurar handler de mensajes
    $mqttSubscriber->setMessageHandler(function ($topic, $message) use (
        $validator,
        $rateLimiter,
        $telemetryService,
        $logger,
        $config
    ) {
        // Procesar señales pendientes
        pcntl_signal_dispatch();

        try {
            // Decodificar JSON
            $data = json_decode($message, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->logError("Error al decodificar JSON desde MQTT", [
                    'topic'   => $topic,
                    'error'   => json_last_error_msg(),
                    'message' => substr($message, 0, 500)
                ]);
                return;
            }

            if (empty($data) || !is_array($data)) {
                $logger->logError("Datos vacíos o inválidos desde MQTT", [
                    'topic' => $topic
                ]);
                return;
            }

            // Extraer identificador para rate limiting
            $identifier = $data['Identificador'] ?? 'unknown';

            // Verificar rate limit
            if (!$rateLimiter->allowRequest($identifier)) {
                $timeUntilNext = $rateLimiter->getTimeUntilNextRequest($identifier);
                $logger->warning("Rate limit excedido para dispositivo", [
                    'identifier'      => $identifier,
                    'topic'           => $topic,
                    'time_until_next' => $timeUntilNext
                ]);
                return;
            }

            // Verificar rate limit por minuto
            if (!$rateLimiter->checkRatePerMinute($identifier)) {
                $logger->warning("Rate limit por minuto excedido", [
                    'identifier' => $identifier,
                    'topic'      => $topic
                ]);
                return;
            }

            // Validar campos mínimos requeridos
            if (!$validator->hasMinimumRequiredFields($data)) {
                $missingFields = $validator->getMissingFields($data);

                $logger->logInvalidRequest($identifier, $data, 'Campos requeridos faltantes: ' . implode(', ', $missingFields));

                if (isset($data['Identificador']) && $data['Identificador'] !== '') {
                    $telemetryService->logValidationErrors($data['Identificador'], [
                        'Campos requeridos faltantes: ' . implode(', ', $missingFields)
                    ]);
                }
                return;
            }

            // Validar formato de datos
            if (!$validator->validate($data)) {
                $errors = $validator->getErrors();

                $logger->logValidationError(
                    $data['Identificador'] ?? null,
                    'Errores de validación',
                    ['errors' => $errors, 'data' => $data, 'topic' => $topic]
                );

                $telemetryService->logValidationErrors($data['Identificador'] ?? null, $errors);
                return;
            }

            // Sanitizar datos
            $cleanData = $validator->sanitize($data);

            // Procesar datos de telemetría
            $result = $telemetryService->processTelemetryData($cleanData);

            if ($result['success']) {
                // Aplicar delay para rate limiting
                $rateLimiter->applyDelay();

                $logger->info("Datos procesados correctamente desde MQTT", [
                    'topic'       => $topic,
                    'identifier'  => $cleanData['Identificador'],
                    'medicion_id' => $result['medicion_id'],
                    'distancia'   => $result['distancia']
                ]);
            } else {
                $logger->logError("Error al procesar datos desde MQTT", [
                    'topic'      => $topic,
                    'identifier' => $cleanData['Identificador'] ?? 'unknown',
                    'error'      => $result['error'],
                    'code'       => $result['code'] ?? 'UNKNOWN_ERROR'
                ]);
            }
        } catch (Exception $e) {
            $logger->logError("Error crítico al procesar mensaje MQTT", [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine()
            ]);
        }
    });

    // Conectar al broker MQTT
    echo "[MQTT] Conectando al broker {$config['mqtt']['broker_host']}:{$config['mqtt']['broker_port']}...\n";
    $mqttSubscriber->connect();
    echo "[MQTT] Conectado al broker MQTT\n";

    // Suscribirse a topics
    echo "[MQTT] Suscribiéndose a topics...\n";
    $mqttSubscriber->subscribe();
    echo "[MQTT] Suscripción completada\n";

    echo "\n=================================================\n";
    echo "  Daemon iniciado correctamente\n";
    echo "  Escuchando mensajes MQTT...\n";
    echo "  Presiona Ctrl+C para detener\n";
    echo "=================================================\n\n";

    $logger->info("MQTT Daemon iniciado correctamente", [
        'broker' => $config['mqtt']['broker_host'],
        'port'   => $config['mqtt']['broker_port'],
        'topics' => $config['mqtt']['topics']
    ]);

    // Loop principal
    while ($running) {
        pcntl_signal_dispatch();
        $mqttSubscriber->loop();
    }

    // Shutdown graceful
    echo "\n[SHUTDOWN] Cerrando conexiones...\n";
    $logger->info("Iniciando shutdown del daemon");

    $mqttSubscriber->disconnect();
    $db->disconnect();
    $cache->disconnect();

    echo "[SHUTDOWN] Daemon detenido correctamente\n";
    $logger->info("Daemon detenido correctamente");

    exit(0);
} catch (Exception $e) {
    $errorMsg = "Error crítico en daemon: " . $e->getMessage();

    if (isset($logger)) {
        $logger->logError($errorMsg, [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        error_log($errorMsg);
    }

    echo "\n[ERROR] $errorMsg\n";
    echo "[ERROR] File: {$e->getFile()}\n";
    echo "[ERROR] Line: {$e->getLine()}\n";

    exit(1);
}

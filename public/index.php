<?php

/*
*=================================================     Detalles    =================================================
*
* Endpoint principal de telemetría
*
*=================================================    Descripcion  =================================================
*
* Punto de entrada para recibir datos de telemetría vía POST.
* Integra todos los módulos del sistema y maneja el flujo completo.
*
*===================================================================================================================
*/

// Definir constante de acceso
define('APP_ACCESS', true);

// Configurar zona horaria
date_default_timezone_set('America/Chile/Santiago');

// Autoloader simple para las clases
spl_autoload_register(function ($class) {
    // Convertir namespace a ruta de archivo
    $prefix  = 'Telemetry\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Importar clases necesarias
use Telemetry\Database\MySQLDatabase;
use Telemetry\Cache\RedisCache;
use Telemetry\Logger\Logger;
use Telemetry\Validation\Validator;
use Telemetry\RateLimit\RateLimiter;
use Telemetry\Utils\GeoCalculator;
use Telemetry\Service\TelemetryService;

// Configurar headers de respuesta
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/*
* Envía una respuesta JSON y termina la ejecución
*
* @param array $data Datos a enviar
* @param int $statusCode Código HTTP
* @return void
*/

function sendResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/*
* Obtiene la IP del cliente
*
* @return string IP del cliente
*/

function getClientIP(): string {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

try {
    // Cargar configuración
    $config = require_once __DIR__ . '/../config/config.php';

    // Configurar zona horaria desde config
    date_default_timezone_set($config['app']['timezone']);

    // Inicializar componentes
    $db               = new MySQLDatabase($config['database']);
    $cache            = new RedisCache($config['redis']);
    $logger           = new Logger($config['logging']);
    $validator        = new Validator($config['validation']);
    $rateLimiter      = new RateLimiter($cache, $config['rate_limit']);
    $geoCalculator    = new GeoCalculator($config['geo']['earth_radius_meters']);
    $telemetryService = new TelemetryService($db, $cache, $logger, $geoCalculator);

    // Conectar a base de datos y Redis
    try {
        $db->connect();
        $cache->connect();
    } catch (Exception $e) {
        $logger->logError("Error al conectar con servicios: " . $e->getMessage());
        // Respuesta
        sendResponse([
            'success' => false,
            'error'   => 'Error de conexión con servicios',
            'message' => $config['app']['debug'] ? $e->getMessage() : 'Error interno del servidor'
        ], 503);
    }

    // Verificar que sea método POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $logger->warning("Método no permitido: " . $_SERVER['REQUEST_METHOD']);
        // Respuesta
        sendResponse([
            'success' => false,
            'error'   => 'Método no permitido',
            'message' => 'Solo se aceptan requests POST'
        ], 405);
    }

    // Obtener IP del cliente
    $clientIP = getClientIP();

    // Verificar rate limit
    if (!$rateLimiter->allowRequest($clientIP)) {
        $timeUntilNext = $rateLimiter->getTimeUntilNextRequest($clientIP);
        // Se guarda Log
        $logger->warning("Rate limit excedido", [
            'ip' => $clientIP,
            'time_until_next' => $timeUntilNext
        ]);
        // Respuesta
        sendResponse([
            'success'        => false,
            'error'          => 'Rate limit excedido',
            'message'        => 'Demasiados requests. Intente nuevamente en ' . $timeUntilNext . 'ms',
            'retry_after_ms' => $timeUntilNext
        ], 429);
    }

    // Verificar rate limit por minuto
    if (!$rateLimiter->checkRatePerMinute($clientIP)) {
        // Se guarda Log
        $logger->warning("Rate limit por minuto excedido", ['ip' => $clientIP]);
        // Respuesta
        sendResponse([
            'success'                 => false,
            'error'                   => 'Rate limit excedido',
            'message'                 => 'Demasiados requests por minuto. Intente nuevamente más tarde',
            'max_requests_per_minute' => $config['rate_limit']['max_requests_per_minute']
        ], 429);
    }

    // Leer datos del body
    $rawInput = file_get_contents('php://input');
    $data     = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Se guarda Log
        $logger->logError("Error al decodificar JSON", [
            'ip'        => $clientIP,
            'error'     => json_last_error_msg(),
            'raw_input' => substr($rawInput, 0, 500)
        ]);
        // Respuesta
        sendResponse([
            'success' => false,
            'error'   => 'JSON inválido',
            'message' => json_last_error_msg()
        ], 400);
    }

    if (empty($data) || !is_array($data)) {
        // Se guarda Log
        $logger->logError("Datos vacíos o inválidos", ['ip' => $clientIP]);
        // Respuesta
        sendResponse([
            'success' => false,
            'error'   => 'Datos inválidos',
            'message' => 'El body debe contener un objeto JSON válido'
        ], 400);
    }

    // Validar campos mínimos requeridos
    if (!$validator->hasMinimumRequiredFields($data)) {
        $missingFields = $validator->getMissingFields($data);

        // Log de request inválido con IP
        $logger->logInvalidRequest($clientIP, $data, 'Campos requeridos faltantes: ' . implode(', ', $missingFields));

        // Registrar en base de datos si hay identificador
        if (isset($data['Identificador']) && $data['Identificador'] !== '') {
            $telemetryService->logValidationErrors($data['Identificador'], [
                'Campos requeridos faltantes: ' . implode(', ', $missingFields)
            ]);
        }
        // Respuesta
        sendResponse([
            'success'         => false,
            'error'           => 'Validación fallida',
            'message'         => 'Faltan campos requeridos',
            'missing_fields'  => $missingFields,
            'required_fields' => $config['validation']['required_fields']
        ], 400);
    }

    // Validar formato de datos
    if (!$validator->validate($data)) {
        $errors = $validator->getErrors();
        // Se guarda Log
        $logger->logValidationError(
            $data['Identificador'] ?? null,
            'Errores de validación',
            ['errors' => $errors, 'data' => $data]
        );
        // Registrar en base de datos
        $telemetryService->logValidationErrors($data['Identificador'] ?? null, $errors);
        // Respuesta
        sendResponse([
            'success'           => false,
            'error'             => 'Validación fallida',
            'message'           => 'Los datos no cumplen con el formato requerido',
            'validation_errors' => $errors
        ], 400);
    }

    // Sanitizar datos
    $cleanData = $validator->sanitize($data);

    // Procesar datos de telemetría
    $result = $telemetryService->processTelemetryData($cleanData);

    if ($result['success']) {
        // Aplicar delay para rate limiting
        $rateLimiter->applyDelay();
        // Respuesta
        sendResponse([
            'success' => true,
            'message' => 'Datos de telemetría procesados correctamente',
            'data' => [
                'medicion_id'      => $result['medicion_id'],
                'distancia_metros' => $result['distancia'],
                'warnings'         => $result['warnings'] ?? []
            ]
        ], 201);
    } else {
        // Respuesta
        sendResponse([
            'success' => false,
            'error'   => $result['error'],
            'code'    => $result['code'] ?? 'UNKNOWN_ERROR'
        ], 404);
    }
} catch (Exception $e) {
    // Log de error crítico
    if (isset($logger)) {
        // Se guarda Log
        $logger->logError("Error crítico en endpoint: " . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        error_log("Error crítico: " . $e->getMessage());
    }
    // Respuesta
    sendResponse([
        'success' => false,
        'error'   => 'Error interno del servidor',
        'message' => isset($config) && $config['app']['debug'] ? $e->getMessage() : 'Ha ocurrido un error inesperado'
    ], 500);
}

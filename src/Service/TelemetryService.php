<?php

/*
*=================================================     Detalles    =================================================
*
* Servicio principal de telemetría
*
*=================================================    Descripcion  =================================================
*
* Orquesta toda la lógica de negocio del sistema:
* - Búsqueda de dispositivos (Redis → MySQL fallback)
* - Validación de tiempo fuera de línea
* - Cálculo de distancia entre coordenadas
* - Registro de errores en base de datos
* - Persistencia de datos de telemetría
* - Actualización de caché
*
*===================================================================================================================
*/

namespace Telemetry\Service;

use Telemetry\Database\Database;
use Telemetry\Cache\RedisCache;
use Telemetry\Logger\Logger;
use Telemetry\Utils\GeoCalculator;
use Exception;

class TelemetryService {
    /*
    *===========================================================================
    * @vars
    */
    private $db;            //@var Database Base de datos
    private $cache;         //@var RedisCache Caché Redis
    private $logger;        //@var Logger Logger
    private $geoCalculator; //@var GeoCalculator Calculadora geográfica

    /*
    *===========================================================================
    * Constructor
    *
    * @param Database $db Base de datos
    * @param RedisCache $cache Caché Redis
    * @param Logger $logger Logger
    * @param GeoCalculator $geoCalculator Calculadora geográfica
    */
    public function __construct(
        Database $db,
        RedisCache $cache,
        Logger $logger,
        GeoCalculator $geoCalculator
    ) {
        $this->db            = $db;
        $this->cache         = $cache;
        $this->logger        = $logger;
        $this->geoCalculator = $geoCalculator;
    }

    /*
    *===========================================================================
    * Procesa los datos de telemetría recibidos
    *
    * @param array $data Datos de telemetría
    * @return array Resultado del procesamiento
    * @throws Exception Si hay error en el procesamiento
    */
    public function processTelemetryData(array $data): array {
        $identificador = $data['Identificador'];
        $latitud       = (float)$data['Latitud'];
        $longitud      = (float)$data['Longitud'];

        // 1. Buscar dispositivo (Redis → MySQL)
        $device = $this->findDevice($identificador);

        if (!$device) {
            // Dispositivo no existe
            $this->logDeviceNotFound($identificador, $data);
            return [
                'success' => false,
                'error'   => 'Dispositivo no encontrado',
                'code'    => 'DEVICE_NOT_FOUND'
            ];
        }

        // 2. Validar tiempo fuera de línea
        $offlineErrors = $this->validateOfflineTime($device);

        // 3. Calcular distancia
        $distance = $this->calculateDistance($device, $latitud, $longitud);

        // 4. Guardar datos de telemetría
        $medicionId = $this->saveTelemetryData($device['idTelemetria'], $data, $distance);

        // 5. Actualizar última conexión y coordenadas en caché
        $this->updateDeviceCache($identificador, $device['idTelemetria'], $latitud, $longitud);

        // 6. Actualizar última conexión en base de datos
        $this->updateLastConnection($device['idTelemetria']);

        // 7. Si hay errores de tiempo fuera de línea, registrarlos
        if (!empty($offlineErrors)) {
            $this->logOfflineError($device['idTelemetria'], $identificador, $offlineErrors);
        }

        // 8. Log de éxito
        $this->logger->logTelemetryData($identificador, array_merge($data, [
            'distancia_calculada' => $distance,
            'medicion_id'         => $medicionId
        ]));

        return [
            'success'     => true,
            'medicion_id' => $medicionId,
            'distancia'   => $distance,
            'warnings'    => $offlineErrors
        ];
    }

    /*
    *===========================================================================
    * Busca un dispositivo primero en Redis, luego en MySQL
    *
    * @param string $identificador Identificador del dispositivo
    * @return array|null Datos del dispositivo o null si no existe
    */
    private function findDevice(string $identificador): ?array {
        // Intentar obtener de Redis
        $device = $this->cache->getDevice($identificador);

        if ($device !== null) {
            $this->logger->info("Dispositivo encontrado en caché: {$identificador}");
            return $device;
        }

        // Si no está en Redis, buscar en MySQL
        $this->logger->info("Dispositivo no en caché, consultando MySQL: {$identificador}");

        try {
            $query  = "SELECT idTelemetria, Identificador, Nombre, UltimaConexion, TiempoFueraLinea FROM equipos_telemetria WHERE Identificador = :identificador LIMIT 1";
            $device = $this->db->selectOne($query, ['identificador' => $identificador]);

            if ($device) {
                // Guardar en caché para futuros requests
                $cacheData = [
                    'idTelemetria'     => $device['idTelemetria'],
                    'Identificador'    => $device['Identificador'],
                    'UltimaConexion'   => $device['UltimaConexion'],
                    'TiempoFueraLinea' => $device['TiempoFueraLinea'],
                    'Latitud'          => null, // Se actualizará con el primer dato
                    'Longitud'         => null
                ];

                $this->cache->setDevice($identificador, $cacheData);
                $this->logger->info("Dispositivo guardado en caché: {$identificador}");

                return $device;
            }

            return null;
        } catch (Exception $e) {
            $this->logger->logError("Error al buscar dispositivo en MySQL: " . $e->getMessage(), [
                'identificador' => $identificador
            ]);
            return null;
        }
    }

    /*
    *===========================================================================
    * Valida el tiempo fuera de línea del dispositivo
    *
    * @param array $device Datos del dispositivo
    * @return array Lista de errores (vacía si no hay errores)
    */
    private function validateOfflineTime(array $device): array {
        $errors = [];

        try {
            $now = new \DateTime();
            $lastConnection = new \DateTime($device['UltimaConexion']);
            $interval = $now->diff($lastConnection);

            // Convertir TiempoFueraLinea (formato TIME) a segundos
            $timeParts = explode(':', $device['TiempoFueraLinea']);
            $maxOfflineSeconds = ((int)$timeParts[0] * 3600) + ((int)$timeParts[1] * 60) + (int)$timeParts[2];

            // Calcular segundos transcurridos
            $elapsedSeconds = ($interval->days * 86400) +
                ($interval->h * 3600) +
                ($interval->i * 60) +
                $interval->s;

            if ($elapsedSeconds > $maxOfflineSeconds) {
                $errors[] = sprintf(
                    "Dispositivo estuvo fuera de línea %d segundos (máximo permitido: %d segundos)",
                    $elapsedSeconds,
                    $maxOfflineSeconds
                );

                $this->logger->logDevice(
                    $device['Identificador'],
                    Logger::LEVEL_WARNING,
                    "Tiempo fuera de línea excedido",
                    [
                        'elapsed_seconds' => $elapsedSeconds,
                        'max_seconds'     => $maxOfflineSeconds
                    ]
                );
            }
        } catch (Exception $e) {
            $this->logger->logError("Error al validar tiempo fuera de línea: " . $e->getMessage());
        }

        return $errors;
    }

    /*
    *===========================================================================
    * Calcula la distancia entre la última posición y la actual
    *
    * @param array $device Datos del dispositivo
    * @param float $newLat Nueva latitud
    * @param float $newLon Nueva longitud
    * @return float Distancia en metros
    */
    private function calculateDistance(array $device, float $newLat, float $newLon): float {
        // Si no hay coordenadas previas, la distancia es 0
        if (
            !isset($device['Latitud']) || !isset($device['Longitud']) ||
            $device['Latitud'] === null || $device['Longitud'] === null
        ) {
            return 0.0;
        }

        $oldLat = (float)$device['Latitud'];
        $oldLon = (float)$device['Longitud'];

        return $this->geoCalculator->calculateDistance($oldLat, $oldLon, $newLat, $newLon);
    }

    /*
    *===========================================================================
    * Guarda los datos de telemetría en la base de datos
    *
    * @param int $idTelemetria ID del dispositivo
    * @param array $data Datos de telemetría
    * @param float $distance Distancia calculada
    * @return int ID de la medición insertada
    * @throws Exception Si hay error al guardar
    */
    private function saveTelemetryData(int $idTelemetria, array $data, float $distance): int {
        try {
            $query = "INSERT INTO equipos_telemetria_datos
                      (idTelemetria, Latitud, Longitud, Distancia, Sensor_1, Sensor_2, Sensor_3, Sensor_4, Sensor_5)
                      VALUES
                      (:idTelemetria, :latitud, :longitud, :distancia, :sensor1, :sensor2, :sensor3, :sensor4, :sensor5)";

            $params = [
                'idTelemetria' => $idTelemetria,
                'latitud'      => $data['Latitud'],
                'longitud'     => $data['Longitud'],
                'distancia'    => $distance,
                'sensor1'      => $data['Sensor_1'] ?? null,
                'sensor2'      => $data['Sensor_2'] ?? null,
                'sensor3'      => $data['Sensor_3'] ?? null,
                'sensor4'      => $data['Sensor_4'] ?? null,
                'sensor5'      => $data['Sensor_5'] ?? null,
            ];

            return $this->db->insert($query, $params);
        } catch (Exception $e) {
            $this->logger->logError("Error al guardar datos de telemetría: " . $e->getMessage(), [
                'idTelemetria' => $idTelemetria,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /*
    *===========================================================================
    * Actualiza el caché del dispositivo con las nuevas coordenadas
    *
    * @param string $identificador Identificador del dispositivo
    * @param int $idTelemetria ID del dispositivo
    * @param float $latitud Nueva latitud
    * @param float $longitud Nueva longitud
    * @return void
    */
    private function updateDeviceCache(string $identificador, int $idTelemetria, float $latitud, float $longitud): void {
        $now = date('Y-m-d H:i:s');

        $cacheData = [
            'idTelemetria'   => $idTelemetria,
            'Identificador'  => $identificador,
            'UltimaConexion' => $now,
            'Latitud'        => $latitud,
            'Longitud'       => $longitud
        ];

        $this->cache->setDevice($identificador, $cacheData);
    }

    /*
    *===========================================================================
    * Actualiza la última conexión del dispositivo en la base de datos
    *
    * @param int $idTelemetria ID del dispositivo
    * @return void
    */
    private function updateLastConnection(int $idTelemetria): void {
        try {
            $query = "UPDATE equipos_telemetria
                      SET UltimaConexion = CURRENT_TIMESTAMP
                      WHERE idTelemetria = :id";

            $this->db->update($query, ['id' => $idTelemetria]);
        } catch (Exception $e) {
            $this->logger->logError("Error al actualizar última conexión: " . $e->getMessage(), [
                'idTelemetria' => $idTelemetria
            ]);
        }
    }

    /*
    *===========================================================================
    * Registra error de dispositivo no encontrado
    *
    * @param string $identificador Identificador del dispositivo
    * @param array $data Datos recibidos
    * @return void
    */
    private function logDeviceNotFound(string $identificador, array $data): void {
        try {
            // Log en archivo
            $this->logger->logError("Dispositivo no encontrado: {$identificador}", $data);

            // Log en base de datos
            $query = "INSERT INTO equipos_telemetria_errores
                      (idTelemetria, Identificador, Fecha, descripcion)
                      VALUES
                      (NULL, :identificador, CURRENT_TIMESTAMP, :descripcion)";

            $params = [
                'identificador' => $identificador,
                'descripcion'   => 'Dispositivo no existe en la base de datos'
            ];

            $this->db->insert($query, $params);
        } catch (Exception $e) {
            $this->logger->logError("Error al registrar dispositivo no encontrado: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Registra error de tiempo fuera de línea excedido
    *
    * @param int $idTelemetria ID del dispositivo
    * @param string $identificador Identificador del dispositivo
    * @param array $errors Lista de errores
    * @return void
    */
    private function logOfflineError(int $idTelemetria, string $identificador, array $errors): void {
        try {
            $descripcion = implode('; ', $errors);

            $query = "INSERT INTO equipos_telemetria_errores
                      (idTelemetria, Identificador, Fecha, descripcion)
                      VALUES
                      (:idTelemetria, :identificador, CURRENT_TIMESTAMP, :descripcion)";

            $params = [
                'idTelemetria'  => $idTelemetria,
                'identificador' => $identificador,
                'descripcion'   => $descripcion
            ];

            $this->db->insert($query, $params);
        } catch (Exception $e) {
            $this->logger->logError("Error al registrar error de tiempo fuera de línea: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Registra errores de validación en la base de datos
    *
    * @param string|null $identificador Identificador del dispositivo
    * @param array $errors Lista de errores
    * @return void
    */
    public function logValidationErrors(?string $identificador, array $errors): void {
        try {
            $descripcion = implode('; ', $errors);

            $query = "INSERT INTO equipos_telemetria_errores
                      (idTelemetria, Identificador, Fecha, descripcion)
                      VALUES
                      (NULL, :identificador, CURRENT_TIMESTAMP, :descripcion)";

            $params = [
                'identificador' => $identificador,
                'descripcion'   => $descripcion
            ];

            $this->db->insert($query, $params);
        } catch (Exception $e) {
            $this->logger->logError("Error al registrar errores de validación: " . $e->getMessage());
        }
    }
}

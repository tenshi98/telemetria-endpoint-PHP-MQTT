<?php

/*
*=================================================     Detalles    =================================================
*
* Manejador de caché Redis
*
*=================================================    Descripcion  =================================================
*
* Gestiona el almacenamiento en caché de datos de dispositivos de telemetría utilizando estructuras hash de Redis
* para un acceso eficiente.
*
*=================================================    Modo de uso  =================================================
*
* Estructura de datos en Redis:
* - Key: telemetry:device:{Identificador}
* - Fields: idTelemetria, UltimaConexion, TiempoFueraLinea, Latitud, Longitud
*
*===================================================================================================================
*/

namespace Telemetry\Cache;

use Redis;
use Exception;

class RedisCache {
    /*
    *===========================================================================
    * @vars
    */
    private $redis = null;      //@var Redis|null Conexión Redis
    private $config;            //@var array Configuración de Redis
    private $connected = false; //@var bool Estado de conexión

    /*
    *===========================================================================
    * Constructor
    *
    * @param array $config Configuración de Redis
    */
    public function __construct(array $config) {
        $this->config = $config;
    }

    /*
    *===========================================================================
    * Establece conexión con Redis
    *
    * @return bool True si la conexión fue exitosa
    * @throws Exception Si no se puede conectar
    */
    public function connect(): bool {
        try {
            $this->redis = new Redis();

            $connected = $this->redis->connect(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout']
            );

            if (!$connected) {
                throw new Exception("No se pudo conectar a Redis");
            }

            // Autenticación si hay password
            if (!empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }

            // Seleccionar base de datos
            $this->redis->select($this->config['database']);

            $this->connected = true;
            return true;
        } catch (Exception $e) {
            $this->connected = false;
            throw new Exception("Error al conectar con Redis: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Verifica si hay conexión activa
    *
    * @return bool True si hay conexión
    */
    public function isConnected(): bool {
        return $this->connected && $this->redis !== null;
    }

    /*
    *===========================================================================
    * Asegura que hay una conexión activa
    *
    * @return void
    * @throws Exception Si no se puede conectar
    */
    private function ensureConnection(): void {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    /*
    *===========================================================================
    * Genera la clave Redis para un dispositivo
    *
    * @param string $identificador Identificador del dispositivo
    * @return string Clave Redis
    */
    private function getDeviceKey(string $identificador): string {
        return $this->config['prefix'] . 'device:' . $identificador;
    }

    /*
    *===========================================================================
    * Obtiene los datos de un dispositivo desde Redis
    *
    * @param string $identificador Identificador del dispositivo
    * @return array|null Datos del dispositivo o null si no existe
    */
    public function getDevice(string $identificador): ?array {
        try {
            $this->ensureConnection();
            $key = $this->getDeviceKey($identificador);
            $data = $this->redis->hGetAll($key);

            if (empty($data)) {
                return null;
            }

            return $data;
        } catch (Exception $e) {
            // Si Redis falla, retornar null para que se consulte MySQL
            return null;
        }
    }

    /*
    *===========================================================================
    * Guarda los datos de un dispositivo en Redis
    *
    * @param string $identificador Identificador del dispositivo
    * @param array $data Datos del dispositivo
    * @param int|null $ttl Tiempo de vida en segundos (null = usar default)
    * @return bool True si se guardó correctamente
    */
    public function setDevice(string $identificador, array $data, ?int $ttl = null): bool {
        try {
            $this->ensureConnection();
            $key = $this->getDeviceKey($identificador);

            // Guardar datos como hash
            $this->redis->hMSet($key, $data);

            // Establecer TTL
            $ttl = $ttl ?? $this->config['ttl'];
            $this->redis->expire($key, $ttl);

            return true;
        } catch (Exception $e) {
            // Si Redis falla, no es crítico, solo registrar
            return false;
        }
    }

    /*
    *===========================================================================
    * Actualiza campos específicos de un dispositivo
    *
    * @param string $identificador Identificador del dispositivo
    * @param array $fields Campos a actualizar
    * @return bool True si se actualizó correctamente
    */
    public function updateDevice(string $identificador, array $fields): bool {
        try {
            $this->ensureConnection();
            $key = $this->getDeviceKey($identificador);

            // Verificar si existe
            if (!$this->redis->exists($key)) {
                return false;
            }

            // Actualizar campos
            $this->redis->hMSet($key, $fields);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /*
    *===========================================================================
    * Elimina un dispositivo del caché
    *
    * @param string $identificador Identificador del dispositivo
    * @return bool True si se eliminó correctamente
    */
    public function deleteDevice(string $identificador): bool {
        try {
            $this->ensureConnection();
            $key = $this->getDeviceKey($identificador);
            return $this->redis->del($key) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /*
    *===========================================================================
    * Obtiene un valor genérico de Redis
    *
    * @param string $key Clave
    * @return mixed Valor o null si no existe
    */
    public function get(string $key) {
        try {
            $this->ensureConnection();
            $value = $this->redis->get($this->config['prefix'] . $key);
            return $value !== false ? $value : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /*
    *===========================================================================
    * Guarda un valor genérico en Redis
    *
    * @param string $key Clave
    * @param mixed $value Valor
    * @param int|null $ttl Tiempo de vida en segundos
    * @return bool True si se guardó correctamente
    */
    public function set(string $key, $value, ?int $ttl = null): bool {
        try {
            $this->ensureConnection();
            $fullKey = $this->config['prefix'] . $key;

            if ($ttl !== null) {
                return $this->redis->setex($fullKey, $ttl, $value);
            } else {
                return $this->redis->set($fullKey, $value);
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /*
    *===========================================================================
    * Incrementa un contador atómicamente
    *
    * @param string $key Clave del contador
    * @return int Nuevo valor del contador
    */
    public function increment(string $key): int {
        try {
            $this->ensureConnection();
            return $this->redis->incr($this->config['prefix'] . $key);
        } catch (Exception $e) {
            return 0;
        }
    }

    /*
    *===========================================================================
    * Cierra la conexión con Redis
    *
    * @return void
    */
    public function disconnect(): void {
        if ($this->redis !== null) {
            $this->redis->close();
            $this->redis = null;
            $this->connected = false;
        }
    }

    /*
    *===========================================================================
    * Destructor - cierra la conexión
    */
    public function __destruct() {
        $this->disconnect();
    }
}

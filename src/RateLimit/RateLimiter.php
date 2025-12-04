<?php

/*
*=================================================     Detalles    =================================================
*
* Limitador de tasa de requests (Rate Limiter)
*
*=================================================    Descripcion  =================================================
*
* Controla la frecuencia de requests para evitar sobrecarga del servidor.
* Utiliza Redis para almacenar timestamps de requests y aplicar delays configurables.
*
*===================================================================================================================
*/

namespace Telemetry\RateLimit;

use Telemetry\Cache\RedisCache;

class RateLimiter {
    /*
    *===========================================================================
    * @vars
    */
    private $cache;   //@var RedisCache Cliente Redis
    private $config;  //@var array Configuración de rate limiting
    private $enabled; //@var bool Estado del rate limiter

    /*
    *===========================================================================
    * Constructor
    *
    * @param RedisCache $cache Cliente Redis
    * @param array $config Configuración de rate limiting
    */
    public function __construct(RedisCache $cache, array $config) {
        $this->cache   = $cache;
        $this->config  = $config;
        $this->enabled = $config['enabled'];
    }

    /*
    *===========================================================================
    * Verifica si un request debe ser permitido
    *
    * @param string $identifier Identificador del cliente (IP, device ID, etc.)
    * @return bool True si el request debe ser permitido
    */
    public function allowRequest(string $identifier): bool {
        if (!$this->enabled) {
            return true;
        }

        $key = 'rate_limit:' . $identifier;
        $now = microtime(true);

        // Obtener el timestamp del último request
        $lastRequest = $this->cache->get($key);

        if ($lastRequest === null) {
            // Primer request de este identificador
            $this->cache->set($key, $now, 60); // TTL de 60 segundos
            return true;
        }

        // Calcular tiempo transcurrido desde el último request
        $elapsed = ($now - (float)$lastRequest) * 1000; // Convertir a milisegundos

        if ($elapsed < $this->config['delay_ms']) {
            // Request demasiado rápido
            return false;
        }

        // Actualizar timestamp
        $this->cache->set($key, $now, 60);
        return true;
    }

    /*
    *===========================================================================
    * Verifica el rate limit por minuto
    *
    * @param string $identifier Identificador del cliente
    * @return bool True si no se ha excedido el límite
    */
    public function checkRatePerMinute(string $identifier): bool {
        if (!$this->enabled) {
            return true;
        }

        $key   = 'rate_limit_count:' . $identifier;
        $count = $this->cache->get($key);

        if ($count === null) {
            // Primer request en este minuto
            $this->cache->set($key, 1, 60);
            return true;
        }

        if ((int)$count >= $this->config['max_requests_per_minute']) {
            return false;
        }

        // Incrementar contador
        $this->cache->increment($key);
        return true;
    }

    /*
    *===========================================================================
    * Aplica el delay configurado entre requests
    *
    * @return void
    */
    public function applyDelay(): void {
        if (!$this->enabled || $this->config['delay_ms'] <= 0) {
            return;
        }

        // Convertir milisegundos a microsegundos
        $microseconds = $this->config['delay_ms'] * 1000;
        usleep($microseconds);
    }

    /*
    *===========================================================================
    * Obtiene el tiempo restante hasta que se permita el siguiente request
    *
    * @param string $identifier Identificador del cliente
    * @return int Milisegundos restantes (0 si puede hacer request inmediatamente)
    */
    public function getTimeUntilNextRequest(string $identifier): int {
        if (!$this->enabled) {
            return 0;
        }

        $key = 'rate_limit:' . $identifier;
        $lastRequest = $this->cache->get($key);

        if ($lastRequest === null) {
            return 0;
        }

        $now       = microtime(true);
        $elapsed   = ($now - (float)$lastRequest) * 1000;
        $remaining = $this->config['delay_ms'] - $elapsed;

        return max(0, (int)ceil($remaining));
    }

    /*
    *===========================================================================
    * Resetea el rate limit para un identificador
    *
    * @param string $identifier Identificador del cliente
    * @return bool True si se reseteó correctamente
    */
    public function reset(string $identifier): bool {
        $key      = 'rate_limit:' . $identifier;
        $countKey = 'rate_limit_count:' . $identifier;

        // Intentar eliminar ambas claves
        $this->cache->set($key, null, 1);
        $this->cache->set($countKey, null, 1);

        return true;
    }

    /*
    *===========================================================================
    * Obtiene estadísticas de rate limiting para un identificador
    *
    * @param string $identifier Identificador del cliente
    * @return array Estadísticas
    */
    public function getStats(string $identifier): array {
        $key          = 'rate_limit:' . $identifier;
        $countKey     = 'rate_limit_count:' . $identifier;
        $lastRequest  = $this->cache->get($key);
        $requestCount = $this->cache->get($countKey);

        return [
            'last_request'            => $lastRequest ? (float)$lastRequest : null,
            'requests_this_minute'    => $requestCount ? (int)$requestCount : 0,
            'max_requests_per_minute' => $this->config['max_requests_per_minute'],
            'delay_ms'                => $this->config['delay_ms'],
            'time_until_next_request' => $this->getTimeUntilNextRequest($identifier),
        ];
    }
}

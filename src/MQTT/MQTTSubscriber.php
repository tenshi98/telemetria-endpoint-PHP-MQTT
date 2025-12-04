<?php

namespace Telemetry\MQTT;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Telemetry\Logger\Logger;
use Exception;

/*
*=================================================     Detalles    =================================================
*
* Cliente MQTT Subscriber para recepción de datos de telemetría
*
*=================================================    Descripcion  =================================================
*
* Maneja la conexión al broker MQTT, suscripción a topics y procesamiento de mensajes entrantes.
* Incluye reconexión automática y manejo robusto de errores.
*
*===================================================================================================================
*/

class MQTTSubscriber{
    /*
    *===========================================================================
    * @vars
    */
    private $client;                    //@var MqttClient Cliente MQTT
    private $config;                    //@var array Configuración MQTT
    private $logger;                    //@var Logger Logger del sistema
    private $messageHandler;            //@var callable Callback para procesar mensajes
    private $running = false;           //@var bool Flag para controlar el loop
    private $reconnectAttempts = 0;     //@var int Contador de reconexiones
    private $maxReconnectAttempts = 10; //@var int Máximo de intentos de reconexión

    /*
    *===========================================================================
    * Constructor
    *
    * @param array $config Configuración MQTT
    * @param Logger $logger Logger del sistema
    */
    public function __construct(array $config, Logger $logger){
        $this->config = $config;
        $this->logger = $logger;
    }

    /*
    *===========================================================================
    * Establece el handler para procesar mensajes
    *
    * @param callable $handler Función que recibe ($topic, $message)
    * @return void
    */
    public function setMessageHandler(callable $handler): void {
        $this->messageHandler = $handler;
    }

    /*
    *===========================================================================
    * Conecta al broker MQTT
    *
    * @return bool True si la conexión fue exitosa
    * @throws Exception Si falla la conexión
    */
    public function connect(): bool {
        try {
            // Crear cliente MQTT
            $this->client = new MqttClient(
                $this->config['broker_host'],
                $this->config['broker_port'],
                $this->config['client_id']
            );

            // Configurar opciones de conexión
            $connectionSettings = (new ConnectionSettings())
                ->setKeepAliveInterval($this->config['keepalive'])
                ->setUseTls(false)
                ->setTlsSelfSignedAllowed(false);

            // Configurar autenticación si está disponible
            if (!empty($this->config['username'])) {
                $connectionSettings
                    ->setUsername($this->config['username'])
                    ->setPassword($this->config['password'] ?? '');
            }

            // Conectar
            $this->client->connect($connectionSettings, $this->config['clean_session']);

            // Log en archivo
            $this->logger->info("Conectado al broker MQTT: {$this->config['broker_host']}:{$this->config['broker_port']}");
            $this->reconnectAttempts = 0;

            return true;
        } catch (Exception $e) {
            $this->logger->logError("Error al conectar con broker MQTT: " . $e->getMessage(), [
                'broker' => $this->config['broker_host'],
                'port' => $this->config['broker_port'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /*
    *===========================================================================
    * Suscribe a los topics configurados
    *
    * @return void
    * @throws Exception Si falla la suscripción
    */
    public function subscribe(): void {
        try {
            $topics = is_array($this->config['topics'])
                ? $this->config['topics']
                : [$this->config['topics']];

            foreach ($topics as $topic) {
                $this->client->subscribe(
                    $topic,
                    function ($topic, $message) {
                        $this->handleMessage($topic, $message);
                    },
                    $this->config['qos']
                );

                // Log en archivo
                $this->logger->info("Suscrito al topic: {$topic} (QoS: {$this->config['qos']})");
            }
        } catch (Exception $e) {
            $this->logger->logError("Error al suscribirse a topics: " . $e->getMessage());
            throw $e;
        }
    }

    /*
    *===========================================================================
    * Maneja un mensaje recibido
    *
    * @param string $topic Topic del mensaje
    * @param string $message Contenido del mensaje
    * @return void
    */
    private function handleMessage(string $topic, string $message): void {
        try {
            // Log en archivo
            $this->logger->info("Mensaje recibido", [
                'topic'          => $topic,
                'message_length' => strlen($message)
            ]);

            // Llamar al handler configurado
            if (is_callable($this->messageHandler)) {
                call_user_func($this->messageHandler, $topic, $message);
            } else {
                $this->logger->warning("No hay message handler configurado");
            }
        } catch (Exception $e) {
            $this->logger->logError("Error al procesar mensaje MQTT", [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /*
    *===========================================================================
    * Inicia el loop de escucha
    *
    * @return void
    */
    public function loop(): void {
        $this->running = true;

        // Log en archivo
        $this->logger->info("Iniciando loop de escucha MQTT");

        try {
            while ($this->running) {
                try {
                    $this->client->loop(true);
                } catch (Exception $e) {
                    $this->logger->logError("Error en loop MQTT: " . $e->getMessage());

                    // Intentar reconectar
                    if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                        $this->reconnect();
                    } else {
                        $this->logger->logError("Máximo de intentos de reconexión alcanzado");
                        $this->running = false;
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->logError("Error crítico en loop MQTT: " . $e->getMessage());
            throw $e;
        }
    }

    /*
    *===========================================================================
    * Intenta reconectar al broker
    *
    * @return void
    */
    private function reconnect(): void {
        $this->reconnectAttempts++;
        $delay = min(30, pow(2, $this->reconnectAttempts)); // Exponential backoff, max 30s

        $this->logger->warning("Intentando reconexión #{$this->reconnectAttempts} en {$delay} segundos");

        sleep($delay);

        try {
            $this->disconnect();
            $this->connect();
            $this->subscribe();

            // Log en archivo
            $this->logger->info("Reconexión exitosa");
        } catch (Exception $e) {
            $this->logger->logError("Fallo en reconexión: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Detiene el loop de escucha
    *
    * @return void
    */
    public function stop(): void {
        // Log en archivo
        $this->logger->info("Deteniendo loop MQTT");
        $this->running = false;
    }

    /*
    *===========================================================================
    * Desconecta del broker MQTT
    *
    * @return void
    */
    public function disconnect(): void {
        try {
            if ($this->client !== null) {
                $this->client->disconnect();
                $this->logger->info("Desconectado del broker MQTT");
            }
        } catch (Exception $e) {
            $this->logger->logError("Error al desconectar: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Verifica si está conectado
    *
    * @return bool
    */
    public function isConnected(): bool {
        try {
            return $this->client !== null && $this->client->isConnected();
        } catch (Exception $e) {
            return false;
        }
    }

    /*
    *===========================================================================
    * Publica un mensaje (útil para respuestas)
    *
    * @param string $topic Topic destino
    * @param string $message Mensaje a publicar
    * @param int $qos QoS level (opcional)
    * @return void
    */
    public function publish(string $topic, string $message, int $qos = null): void {
        try {
            $qos = $qos ?? $this->config['qos'];
            $this->client->publish($topic, $message, $qos);

            $this->logger->info("Mensaje publicado", [
                'topic' => $topic,
                'qos'   => $qos
            ]);
        } catch (Exception $e) {
            $this->logger->logError("Error al publicar mensaje: " . $e->getMessage(), [
                'topic' => $topic
            ]);
        }
    }
}

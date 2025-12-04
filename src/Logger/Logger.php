<?php

/*
*=================================================     Detalles    =================================================
*
* Sistema de logging con múltiples niveles y destinos
*
*=================================================    Descripcion  =================================================
*
* Gestiona logs del sistema con soporte para:
* - Múltiples niveles (INFO, WARNING, ERROR)
* - Logs por dispositivo (un archivo por identificador)
* - Log de requests inválidos (con IP)
* - Logs del sistema y errores generales
* - Rotación automática de archivos
*
*===================================================================================================================
*/

namespace Telemetry\Logger;

class Logger{
    // Niveles de log
    const LEVEL_INFO    = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR   = 'ERROR';
    /*
    *===========================================================================
    * @vars
    */
    private $config;  //@var array Configuración del logger
    private $logPath; //@var string Ruta base de logs
    private $enabled; //@var bool Estado del logger

    /*
    *===========================================================================
    * Constructor
    *
    * @param array $config Configuración del logger
    */
    public function __construct(array $config) {
        $this->config  = $config;
        $this->logPath = $config['path'];
        $this->enabled = $config['enabled'];

        // Crear directorio de logs si no existe
        $this->ensureLogDirectory();
    }

    /*
    *===========================================================================
    * Asegura que el directorio de logs existe
    *
    * @return void
    */
    private function ensureLogDirectory(): void {
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        // Crear subdirectorio para logs de dispositivos
        $devicesPath = $this->logPath . '/' . $this->config['files']['devices'];
        if (!is_dir($devicesPath)) {
            mkdir($devicesPath, 0755, true);
        }
    }

    /*
    *===========================================================================
    * Formatea un mensaje de log
    *
    * @param string $level Nivel del log
    * @param string $message Mensaje
    * @param array $context Contexto adicional
    * @return string Mensaje formateado
    */
    private function formatMessage(string $level, string $message, array $context = []): string {
        $timestamp  = date($this->config['date_format']);
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        return sprintf(
            "[%s] [%s] %s%s\n",
            $timestamp,
            $level,
            $message,
            $contextStr
        );
    }

    /*
    *===========================================================================
    * Escribe un mensaje en un archivo de log
    *
    * @param string $filename Nombre del archivo
    * @param string $message Mensaje formateado
    * @return bool True si se escribió correctamente
    */
    private function writeToFile(string $filename, string $message): bool {
        if (!$this->enabled) {
            return false;
        }

        try {
            // Verificar tamaño del archivo y rotar si es necesario
            if (file_exists($filename) && filesize($filename) > $this->config['max_file_size']) {
                $this->rotateLog($filename);
            }

            return file_put_contents($filename, $message, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Exception $e) {
            // Si falla el logging, no queremos detener la aplicación
            error_log("Error al escribir log: " . $e->getMessage());
            return false;
        }
    }

    /*
    *===========================================================================
    * Rota un archivo de log cuando alcanza el tamaño máximo
    *
    * @param string $filename Ruta del archivo
    * @return void
    */
    private function rotateLog(string $filename): void {
        $rotatedFilename = $filename . '.' . date('Y-m-d_His');
        rename($filename, $rotatedFilename);
    }

    /*
    *===========================================================================
    * Registra un mensaje de nivel INFO
    *
    * @param string $message Mensaje
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function info(string $message, array $context = []): bool {
        return $this->log(self::LEVEL_INFO, $message, $context);
    }

    /*
    *===========================================================================
    * Registra un mensaje de nivel WARNING
    *
    * @param string $message Mensaje
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function warning(string $message, array $context = []): bool {
        return $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /*
    *===========================================================================
    * Registra un mensaje de nivel ERROR
    *
    * @param string $message Mensaje
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function error(string $message, array $context = []): bool {
        return $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /*
    *===========================================================================
    * Registra un mensaje en el log del sistema
    *
    * @param string $level Nivel del log
    * @param string $message Mensaje
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function log(string $level, string $message, array $context = []): bool {
        $filename         = $this->logPath . '/' . $this->config['files']['system'];
        $formattedMessage = $this->formatMessage($level, $message, $context);
        return $this->writeToFile($filename, $formattedMessage);
    }

    /*
    *===========================================================================
    * Registra actividad de un dispositivo específico
    *
    * @param string $identificador Identificador del dispositivo
    * @param string $level Nivel del log
    * @param string $message Mensaje
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function logDevice(string $identificador, string $level, string $message, array $context = []): bool {
        // Sanitizar el identificador para nombre de archivo
        $safeIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identificador);

        $filename = sprintf(
            '%s/%s/%s.log',
            $this->logPath,
            $this->config['files']['devices'],
            $safeIdentifier
        );

        $formattedMessage = $this->formatMessage($level, $message, $context);
        return $this->writeToFile($filename, $formattedMessage);
    }

    /*
    *===========================================================================
    * Registra un request inválido (sin identificador, latitud o longitud)
    *
    * @param string $ip Dirección IP del cliente
    * @param array $data Datos recibidos
    * @param string $reason Razón de invalidez
    * @return bool True si se registró correctamente
    */
    public function logInvalidRequest(string $ip, array $data, string $reason): bool {
        $filename = $this->logPath . '/' . $this->config['files']['invalid'];

        $context = [
            'ip'     => $ip,
            'reason' => $reason,
            'data'   => $data
        ];

        $formattedMessage = $this->formatMessage(self::LEVEL_WARNING, 'Request inválido', $context);
        return $this->writeToFile($filename, $formattedMessage);
    }

    /*
    *===========================================================================
    * Registra un error en el log de errores
    *
    * @param string $message Mensaje de error
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function logError(string $message, array $context = []): bool {
        $filename         = $this->logPath . '/' . $this->config['files']['errors'];
        $formattedMessage = $this->formatMessage(self::LEVEL_ERROR, $message, $context);
        return $this->writeToFile($filename, $formattedMessage);
    }

    /*
    *===========================================================================
    * Registra datos de telemetría recibidos
    *
    * @param string $identificador Identificador del dispositivo
    * @param array $data Datos recibidos
    * @return bool True si se registró correctamente
    */
    public function logTelemetryData(string $identificador, array $data): bool {
        return $this->logDevice(
            $identificador,
            self::LEVEL_INFO,
            'Datos de telemetría recibidos',
            $data
        );
    }

    /*
    *===========================================================================
    * Registra una validación exitosa
    *
    * @param string $identificador Identificador del dispositivo
    * @return bool True si se registró correctamente
    */
    public function logValidationSuccess(string $identificador): bool {
        return $this->logDevice(
            $identificador,
            self::LEVEL_INFO,
            'Validación exitosa'
        );
    }

    /*
    *===========================================================================
    * Registra un error de validación
    *
    * @param string|null $identificador Identificador del dispositivo (puede ser null)
    * @param string $error Descripción del error
    * @param array $context Contexto adicional
    * @return bool True si se registró correctamente
    */
    public function logValidationError(?string $identificador, string $error, array $context = []): bool {
        if ($identificador) {
            return $this->logDevice($identificador, self::LEVEL_ERROR, $error, $context);
        } else {
            return $this->logError($error, $context);
        }
    }
}

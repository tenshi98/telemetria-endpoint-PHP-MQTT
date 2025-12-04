<?php

/*
*=================================================     Detalles    =================================================
*
* Validador de datos de entrada
*
*=================================================    Descripcion  =================================================
*
* Valida los datos recibidos por POST asegurando que cumplan con los
* requisitos mínimos (Identificador, Latitud, Longitud) y que tengan
* el formato correcto.
*
*===================================================================================================================
*/

namespace Telemetry\Validation;

class Validator {
    /*
    *===========================================================================
    * @vars
    */
    private $config;      //@var array Configuración de validación
    private $errors = []; //@var array Errores de validación

    /*
    *===========================================================================
    * Constructor
    *
    * @param array $config Configuración de validación
    */
    public function __construct(array $config) {
        $this->config = $config;
    }

    /*
    *===========================================================================
    * Valida los datos de telemetría recibidos
    *
    * @param array $data Datos a validar
    * @return bool True si los datos son válidos
    */
    public function validate(array $data): bool {
        $this->errors = [];

        // Validar campos requeridos
        $this->validateRequiredFields($data);

        // Validar formato de identificador
        if (isset($data['Identificador'])) {
            $this->validateIdentifier($data['Identificador']);
        }

        // Validar coordenadas
        if (isset($data['Latitud'])) {
            $this->validateLatitude($data['Latitud']);
        }

        if (isset($data['Longitud'])) {
            $this->validateLongitude($data['Longitud']);
        }

        // Validar campos opcionales numéricos
        $this->validateOptionalNumericFields($data);

        return empty($this->errors);
    }

    /*
    *===========================================================================
    * Valida que existan los campos requeridos
    *
    * @param array $data Datos a validar
    * @return void
    */
    private function validateRequiredFields(array $data): void {
        foreach ($this->config['required_fields'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $this->errors[] = "El campo '{$field}' es requerido";
            }
        }
    }

    /*
    *===========================================================================
    * Valida el formato del identificador
    *
    * @param mixed $identifier Identificador a validar
    * @return void
    */
    private function validateIdentifier($identifier): void {
        if (!is_string($identifier) && !is_numeric($identifier)) {
            $this->errors[] = "El Identificador debe ser una cadena de texto o número";
            return;
        }

        $identifier = (string)$identifier;

        if (strlen($identifier) > $this->config['max_identifier_length']) {
            $this->errors[] = sprintf(
                "El Identificador no puede exceder %d caracteres",
                $this->config['max_identifier_length']
            );
        }

        if (strlen($identifier) === 0) {
            $this->errors[] = "El Identificador no puede estar vacío";
        }
    }

    /*
    *===========================================================================
    * Valida el formato de la latitud
    *
    * @param mixed $latitude Latitud a validar
    * @return void
    */
    private function validateLatitude($latitude): void {
        if (!is_numeric($latitude)) {
            $this->errors[] = "La Latitud debe ser un valor numérico";
            return;
        }

        $lat = (float)$latitude;

        if ($lat < -90 || $lat > 90) {
            $this->errors[] = "La Latitud debe estar entre -90 y 90 grados";
        }
    }

    /*
    *===========================================================================
    * Valida el formato de la longitud
    *
    * @param mixed $longitude Longitud a validar
    * @return void
    */
    private function validateLongitude($longitude): void {
        if (!is_numeric($longitude)) {
            $this->errors[] = "La Longitud debe ser un valor numérico";
            return;
        }

        $lon = (float)$longitude;

        if ($lon < -180 || $lon > 180) {
            $this->errors[] = "La Longitud debe estar entre -180 y 180 grados";
        }
    }

    /*
    *===========================================================================
    * Valida los campos numéricos opcionales
    *
    * @param array $data Datos a validar
    * @return void
    */
    private function validateOptionalNumericFields(array $data): void {
        $numericFields = ['Distancia', 'Sensor_1', 'Sensor_2', 'Sensor_3', 'Sensor_4', 'Sensor_5'];

        foreach ($numericFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null && !is_numeric($data[$field])) {
                $this->errors[] = "El campo '{$field}' debe ser un valor numérico";
            }
        }
    }

    /*
    *===========================================================================
    * Obtiene los errores de validación
    *
    * @return array Lista de errores
    */
    public function getErrors(): array {
        return $this->errors;
    }

    /*
    *===========================================================================
    * Obtiene los errores como una cadena de texto
    *
    * @param string $separator Separador entre errores
    * @return string Errores concatenados
    */
    public function getErrorsAsString(string $separator = '; '): string {
        return implode($separator, $this->errors);
    }

    /*
    *===========================================================================
    * Verifica si hay errores
    *
    * @return bool True si hay errores
    */
    public function hasErrors(): bool {
        return !empty($this->errors);
    }

    /*
    *===========================================================================
    * Limpia los datos validados, removiendo campos no permitidos
    *
    * @param array $data Datos a limpiar
    * @return array Datos limpios
    */
    public function sanitize(array $data): array {
        $allowedFields = array_merge(
            $this->config['required_fields'],
            $this->config['optional_fields']
        );

        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                // Sanitizar valores
                if (is_string($value)) {
                    $sanitized[$key] = trim($value);
                } elseif (is_numeric($value)) {
                    $sanitized[$key] = $value;
                } else {
                    $sanitized[$key] = $value;
                }
            }
        }

        return $sanitized;
    }

    /*
    *===========================================================================
    * Valida que los campos requeridos mínimos existan
    * (solo Identificador, Latitud, Longitud)
    *
    * @param array $data Datos a validar
    * @return bool True si existen los campos mínimos
    */
    public function hasMinimumRequiredFields(array $data): bool {
        return isset($data['Identificador']) &&
            isset($data['Latitud']) &&
            isset($data['Longitud']) &&
            $data['Identificador'] !== '' &&
            $data['Latitud'] !== '' &&
            $data['Longitud'] !== '';
    }

    /*
    *===========================================================================
    * Obtiene los campos faltantes
    *
    * @param array $data Datos a verificar
    * @return array Lista de campos faltantes
    */
    public function getMissingFields(array $data): array {
        $missing = [];

        foreach ($this->config['required_fields'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}

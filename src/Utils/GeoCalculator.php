<?php

/*
*=================================================     Detalles    =================================================
*
* Calculadora geográfica
*
*=================================================    Descripcion  =================================================
*
* Utiliza la fórmula de Haversine para calcular distancias entre
* coordenadas geográficas con alta precisión.
*
*===================================================================================================================
*/

namespace Telemetry\Utils;

class GeoCalculator {
    /*
    *===========================================================================
    * @vars
    */
    private $earthRadius; //@var float Radio de la Tierra en metros

    /*
    *===========================================================================
    * Constructor
    *
    * @param float $earthRadius Radio de la Tierra en metros (default: 6371000)
    */
    public function __construct(float $earthRadius = 6371000) {
        $this->earthRadius = $earthRadius;
    }

    /*
    *===========================================================================
    * Calcula la distancia entre dos puntos geográficos usando la fórmula de Haversine
    *
    * La fórmula de Haversine determina la distancia del círculo máximo entre dos puntos
    * en una esfera dadas sus longitudes y latitudes.
    *
    * @param float $lat1 Latitud del primer punto (grados)
    * @param float $lon1 Longitud del primer punto (grados)
    * @param float $lat2 Latitud del segundo punto (grados)
    * @param float $lon2 Longitud del segundo punto (grados)
    * @return float Distancia en metros
    */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        // Convertir grados a radianes
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        // Diferencias
        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLon = $lon2Rad - $lon1Rad;

        // Fórmula de Haversine
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1Rad) * cos($lat2Rad) *
            sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        // Distancia en metros
        $distance = $this->earthRadius * $c;

        return round($distance, 6);
    }

    /*
    *===========================================================================
    * Valida que las coordenadas sean válidas
    *
    * @param float $latitude Latitud
    * @param float $longitude Longitud
    * @return bool True si las coordenadas son válidas
    */
    public function validateCoordinates(float $latitude, float $longitude): bool {
        return $latitude >= -90 && $latitude <= 90 &&
            $longitude >= -180 && $longitude <= 180;
    }

    /*
    *===========================================================================
    * Calcula el punto medio entre dos coordenadas
    *
    * @param float $lat1 Latitud del primer punto
    * @param float $lon1 Longitud del primer punto
    * @param float $lat2 Latitud del segundo punto
    * @param float $lon2 Longitud del segundo punto
    * @return array ['latitude' => float, 'longitude' => float]
    */
    public function calculateMidpoint(float $lat1, float $lon1, float $lat2, float $lon2): array {
        // Convertir a radianes
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        $deltaLon = $lon2Rad - $lon1Rad;

        $Bx = cos($lat2Rad) * cos($deltaLon);
        $By = cos($lat2Rad) * sin($deltaLon);

        $lat3Rad = atan2(
            sin($lat1Rad) + sin($lat2Rad),
            sqrt((cos($lat1Rad) + $Bx) * (cos($lat1Rad) + $Bx) + $By * $By)
        );

        $lon3Rad = $lon1Rad + atan2($By, cos($lat1Rad) + $Bx);

        return [
            'latitude' => rad2deg($lat3Rad),
            'longitude' => rad2deg($lon3Rad)
        ];
    }

    /*
    *===========================================================================
    * Formatea coordenadas con la precisión especificada
    *
    * @param float $latitude Latitud
    * @param float $longitude Longitud
    * @param int $precision Número de decimales
    * @return array ['latitude' => float, 'longitude' => float]
    */
    public function formatCoordinates(float $latitude, float $longitude, int $precision = 6): array {
        return [
            'latitude'  => round($latitude, $precision),
            'longitude' => round($longitude, $precision)
        ];
    }
}

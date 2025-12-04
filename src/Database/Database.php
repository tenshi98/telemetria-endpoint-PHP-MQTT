<?php

/*
*=================================================     Detalles    =================================================
*
* Interfaz abstracta para operaciones de base de datos
*
*=================================================    Descripcion  =================================================
*
* Esta interfaz define el contrato que deben cumplir todas las implementaciones
* de base de datos, permitiendo cambiar fácilmente entre diferentes motores
* (MySQL, PostgreSQL, SQL Server, etc.) sin modificar la lógica de negocio.
*
*===================================================================================================================
*/

namespace Telemetry\Database;

interface Database{
    /*
    *===========================================================================
    * Establece la conexión con la base de datos
    *
    * @return bool True si la conexión fue exitosa
    * @throws \Exception Si no se puede establecer la conexión
    */
    public function connect(): bool;

    /*
    *===========================================================================
    * Cierra la conexión con la base de datos
    *
    * @return void
    */
    public function disconnect(): void;

    /*
    *===========================================================================
    * Verifica si hay una conexión activa
    *
    * @return bool True si hay conexión activa
    */
    public function isConnected(): bool;

    /*
    *===========================================================================
    * Ejecuta una consulta SELECT y retorna los resultados
    *
    * @param string $query Consulta SQL
    * @param array $params Parámetros para prepared statement
    * @return array Resultados de la consulta
    * @throws \Exception Si hay error en la consulta
    */
    public function select(string $query, array $params = []): array;

    /*
    *===========================================================================
    * Ejecuta una consulta SELECT y retorna solo la primera fila
    *
    * @param string $query Consulta SQL
    * @param array $params Parámetros para prepared statement
    * @return array|null Primera fila o null si no hay resultados
    * @throws \Exception Si hay error en la consulta
    */
    public function selectOne(string $query, array $params = []): ?array;

    /*
    *===========================================================================
    * Ejecuta una consulta INSERT y retorna el ID insertado
    *
    * @param string $query Consulta SQL
    * @param array $params Parámetros para prepared statement
    * @return int ID del registro insertado
    * @throws \Exception Si hay error en la consulta
    */
    public function insert(string $query, array $params = []): int;

    /*
    *===========================================================================
    * Ejecuta una consulta UPDATE
    *
    * @param string $query Consulta SQL
    * @param array $params Parámetros para prepared statement
    * @return int Número de filas afectadas
    * @throws \Exception Si hay error en la consulta
    */
    public function update(string $query, array $params = []): int;

    /*
    *===========================================================================
    * Ejecuta una consulta DELETE
    *
    * @param string $query Consulta SQL
    * @param array $params Parámetros para prepared statement
    * @return int Número de filas eliminadas
    * @throws \Exception Si hay error en la consulta
    */
    public function delete(string $query, array $params = []): int;

    /*
    *===========================================================================
    * Ejecuta una consulta genérica (INSERT, UPDATE, DELETE)
    *
    * @param string $query Consulta SQL
    * @param array $params Parámetros para prepared statement
    * @return bool True si la ejecución fue exitosa
    * @throws \Exception Si hay error en la consulta
    */
    public function execute(string $query, array $params = []): bool;

    /*
    *===========================================================================
    * Inicia una transacción
    *
    * @return bool True si se inició correctamente
    * @throws \Exception Si no se puede iniciar la transacción
    */
    public function beginTransaction(): bool;

    /*
    *===========================================================================
    * Confirma una transacción
    *
    * @return bool True si se confirmó correctamente
    * @throws \Exception Si no se puede confirmar la transacción
    */
    public function commit(): bool;

    /*
    *===========================================================================
    * Revierte una transacción
    *
    * @return bool True si se revirtió correctamente
    * @throws \Exception Si no se puede revertir la transacción
    */
    public function rollback(): bool;

    /*
    *===========================================================================
    * Obtiene el último error de la base de datos
    *
    * @return string|null Mensaje de error o null si no hay error
    */
    public function getLastError(): ?string;

    /*
    *===========================================================================
    * Escapa un valor para uso seguro en consultas
    * (Nota: Se recomienda usar prepared statements en su lugar)
    *
    * @param mixed $value Valor a escapar
    * @return string Valor escapado
    */
    public function escape($value): string;
}

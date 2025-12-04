<?php

/*
*=================================================     Detalles    =================================================
*
* Implementación de MySQL para la interfaz Database
*
*=================================================    Descripcion  =================================================
*
* Utiliza PDO con prepared statements para prevenir inyección SQL.
* Incluye manejo de errores, transacciones y reconexión automática.
*
*===================================================================================================================
*/

namespace Telemetry\Database;

use PDO;
use PDOException;
use Exception;

class MySQLDatabase implements Database{
    /*
    *===========================================================================
    * @vars
    */
    private $connection = null; //@var PDO|null Conexión PDO
    private $config;            //@var array Configuración de la base de datos
    private $lastError = null;  //@var string|null Último error

    /*
    *===========================================================================
    * Constructor
    *
    * @param array $config Configuración de la base de datos
    */
    public function __construct(array $config) {
        $this->config = $config;
    }

    /*
    *===========================================================================
    * Establece una conexión con la base de datos MySQL utilizando PDO.
    *
    * Este método construye dinámicamente el DSN a partir de la configuración
    * proporcionada (host, puerto, nombre de base de datos y charset) y luego
    * intenta inicializar una instancia de PDO. Si la conexión es exitosa,
    * la propiedad `$connection` almacenará el objeto PDO y cualquier error previo
    * será limpiado. Si ocurre un error al conectar, se captura la excepción
    * lanzada por PDO y se vuelve a lanzar como una excepción genérica con un
    * mensaje más claro para el desarrollador.
    *
    * @return bool Devuelve `true` si la conexión se estableció correctamente.
    *
    * @throws Exception Si ocurre un error al intentar conectarse a MySQL.
    *                   El mensaje incluirá la causa original del PDOException.
    */
    public function connect(): bool {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $this->config['options']
            );

            $this->lastError = null;
            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error al conectar con MySQL: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Cierra la conexión activa con la base de datos MySQL.
    *
    * En PDO, la desconexión se realiza asignando `null` a la instancia de PDO.
    * Este método limpia la propiedad `$connection`, forzando la liberación de
    * recursos asociados a la conexión y permitiendo que PHP cierre la sesión
    * con la base de datos. No lanza excepciones y no devuelve valores.
    *
    * @return void
    */
    public function disconnect(): void {
        $this->connection = null;
    }

    /*
    *===========================================================================
    * Verifica si existe una conexión activa con la base de datos.
    *
    * Este método determina si la propiedad `$connection` contiene una instancia
    * válida de PDO, lo cual indica que la conexión fue establecida previamente
    * mediante el método `connect()`. Si la propiedad es `null`, se asume que no
    * existe una conexión activa.
    *
    * @return bool Devuelve `true` si hay una conexión activa, o `false` si no la hay.
    */
    public function isConnected(): bool {
        return $this->connection !== null;
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
    * Ejecuta una consulta SELECT en la base de datos y devuelve los resultados.
    *
    * Este método prepara y ejecuta una sentencia SQL utilizando parámetros
    * enlazados para evitar inyecciones SQL. Antes de ejecutar la consulta,
    * verifica que exista una conexión activa mediante `ensureConnection()`.
    * Retorna todos los registros obtenidos en forma de arreglo asociativo.
    *
    * Si ocurre un error durante la preparación o ejecución de la consulta,
    * se captura el PDOException, se almacena el mensaje en `$lastError`,
    * y se lanza una nueva excepción más descriptiva.
    *
    * @param string $query   Sentencia SQL SELECT a ejecutar.
    * @param array  $params  Parámetros opcionales para la consulta preparada.
    *
    * @return array          Arreglo con todos los registros obtenidos.
    *
    * @throws Exception      Si ocurre un error en la consulta o en la ejecución.
    */
    public function select(string $query, array $params = []): array {
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error en SELECT: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Ejecuta una consulta SELECT y devuelve únicamente el primer registro encontrado.
    *
    * Este método prepara y ejecuta una consulta SQL utilizando parámetros
    * enlazados para mayor seguridad. Está pensado para consultas que deben
    * retornar una única fila, como búsquedas por llave primaria o consultas
    * limitadas con `LIMIT 1`.
    *
    * Antes de ejecutar la consulta, verifica que exista una conexión activa
    * mediante `ensureConnection()`. Si la consulta no devuelve resultados,
    * el método retorna `null` en lugar de un arreglo vacío.
    *
    * En caso de producirse un error durante la preparación o ejecución,
    * se captura el PDOException, se almacena su mensaje en `$lastError`
    * y se lanza una excepción descriptiva.
    *
    * @param string $query   Sentencia SQL SELECT a ejecutar.
    * @param array  $params  Parámetros opcionales para la consulta preparada.
    *
    * @return array|null     Arreglo asociativo con la primera fila encontrada,
    *                        o `null` si no existen resultados.
    *
    * @throws Exception      Si ocurre un error en la consulta o durante su ejecución.
    */
    public function selectOne(string $query, array $params = []): ?array {
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error en SELECT ONE: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Ejecuta una sentencia SQL de inserción (INSERT) y devuelve el ID generado.
    *
    * Este método prepara y ejecuta una consulta INSERT utilizando parámetros
    * enlazados para mayor seguridad y para evitar inyecciones SQL. Antes de
    * ejecutar la operación, verifica que exista una conexión activa mediante
    * `ensureConnection()`.
    *
    * Tras una inserción exitosa, se obtiene el ID del último registro insertado
    * mediante `lastInsertId()`, el cual es devuelto como un entero. Si la tabla
    * no posee una columna autoincremental, el valor retornado dependerá del
    * comportamiento configurado en la base de datos (generalmente "0").
    *
    * En caso de producirse algún error durante la preparación o ejecución de la
    * sentencia, se captura la excepción PDOException, se almacena el mensaje en
    * `$lastError` y se lanza una nueva excepción más descriptiva.
    *
    * @param string $query   Sentencia SQL INSERT a ejecutar.
    * @param array  $params  Parámetros opcionales para la consulta preparada.
    *
    * @return int            El ID del último registro insertado.
    *
    * @throws Exception      Si ocurre un error durante la inserción.
    */
    public function insert(string $query, array $params = []): int {
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error en INSERT: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Ejecuta una sentencia SQL de actualización (UPDATE) y devuelve el número
    * de filas afectadas.
    *
    * Este método prepara y ejecuta una consulta UPDATE utilizando parámetros
    * enlazados para prevenir inyecciones SQL. Antes de ejecutar la operación,
    * verifica que exista una conexión activa mediante `ensureConnection()`.
    *
    * Tras la ejecución, el método retorna la cantidad de filas modificadas,
    * obtenida mediante `rowCount()`. Este valor puede ser:
    *   - Mayor que 0: si una o más filas fueron modificadas.
    *   - 0: si la consulta no alteró datos, aunque coincidan filas (por ejemplo,
    *        si el nuevo valor es igual al actual).
    *
    * En caso de error durante la preparación o ejecución de la sentencia,
    * se captura el PDOException, se almacena el mensaje en `$lastError` y
    * se lanza una nueva excepción con un mensaje descriptivo.
    *
    * @param string $query   Sentencia SQL UPDATE a ejecutar.
    * @param array  $params  Parámetros opcionales para la consulta preparada.
    *
    * @return int            Número de filas afectadas por la operación.
    *
    * @throws Exception      Si ocurre un error durante la ejecución del UPDATE.
    */
    public function update(string $query, array $params = []): int {
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error en UPDATE: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Ejecuta una sentencia SQL de eliminación (DELETE) y devuelve la cantidad
    * de filas afectadas.
    *
    * Este método prepara y ejecuta una sentencia DELETE utilizando parámetros
    * enlazados, lo que garantiza una ejecución segura y protege contra ataques
    * de inyección SQL. Antes de ejecutar la consulta, se verifica que exista una
    * conexión activa mediante `ensureConnection()`.
    *
    * Después de la ejecución, `rowCount()` devuelve cuántos registros fueron
    * eliminados. Si ninguna fila cumple la condición del WHERE, el resultado será 0.
    *
    * En caso de producirse un error durante la preparación o ejecución, se captura
    * la excepción PDOException, se almacena su mensaje en `$lastError`, y se lanza
    * una nueva excepción más clara para facilitar el diagnóstico.
    *
    * @param string $query   Sentencia SQL DELETE a ejecutar.
    * @param array  $params  Parámetros opcionales para la consulta preparada.
    *
    * @return int            Número de filas eliminadas.
    *
    * @throws Exception      Si ocurre un error durante la ejecución del DELETE.
    */
    public function delete(string $query, array $params = []): int {
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error en DELETE: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Ejecuta una consulta SQL preparada con parámetros opcionales.
    *
    * Este método se utiliza para ejecutar sentencias SQL que no requieren
    * devolver resultados, como INSERT, UPDATE o cualquier instrucción que solo
    * necesite ejecutarse exitosamente. Internamente prepara la consulta,
    * enlaza los parámetros y ejecuta la sentencia.
    *
    * @param string $query   La consulta SQL a ejecutar. Puede contener placeholders.
    * @param array  $params  Parámetros asociados a la consulta preparada.
    *
    * @return bool  Devuelve true si la ejecución fue exitosa, o false si falló.
    *
    * @throws Exception  Lanza una excepción cuando ocurre un error durante la preparación
    *                    o ejecución de la sentencia SQL. El mensaje original de PDO
    *                    se almacena en $this->lastError.
    */
    public function execute(string $query, array $params = []): bool {
        try {
            $this->ensureConnection();
            $stmt = $this->connection->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error en EXECUTE: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Inicia una transacción en la base de datos.
    *
    * Este método permite ejecutar múltiples operaciones SQL de forma atómica.
    * Si todas las operaciones dentro de la transacción se ejecutan correctamente,
    * se debe llamar a commit(). En caso de error, se debe llamar a rollback().
    *
    * @return bool  Devuelve true si la transacción se inició correctamente.
    *
    * @throws Exception  Lanza una excepción si ocurre un error al intentar iniciar
    *                    la transacción. El mensaje original del error se almacena
    *                    en $this->lastError.
    */
    public function beginTransaction(): bool {
        try {
            $this->ensureConnection();
            return $this->connection->beginTransaction();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error al iniciar transacción: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Confirma (hace commit) una transacción activa.
    *
    * Este método finaliza una transacción previamente iniciada con beginTransaction(),
    * aplicando de manera permanente todos los cambios realizados durante la misma.
    *
    * Si no existe una transacción activa, el método devuelve false sin ejecutar nada.
    *
    * @return bool  True si el commit se realizó con éxito; false si no había una
    *               transacción activa.
    *
    * @throws Exception  Si ocurre un error durante la confirmación de la transacción.
    *                    El mensaje se almacena también en $this->lastError.
    */
    public function commit(): bool {
        try {
            if ($this->connection && $this->connection->inTransaction()) {
                return $this->connection->commit();
            }
            return false;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error al confirmar transacción: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Revierte (hace rollback) una transacción activa.
    *
    * Este método deshace todos los cambios realizados desde que se inició la
    * transacción con beginTransaction(). Es útil en caso de errores o fallos
    * durante la ejecución de múltiples operaciones atómicas.
    *
    * Si no existe una transacción activa, el método devuelve false sin ejecutar nada.
    *
    * @return bool  True si el rollback se realizó con éxito; false si no había una
    *               transacción activa.
    *
    * @throws Exception  Lanza una excepción si ocurre un error al revertir la
    *                    transacción. El mensaje se almacena también en $this->lastError.
    */
    public function rollback(): bool {
        try {
            if ($this->connection && $this->connection->inTransaction()) {
                return $this->connection->rollback();
            }
            return false;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new Exception("Error al revertir transacción: " . $e->getMessage());
        }
    }

    /*
    *===========================================================================
    * Obtiene el último mensaje de error registrado en la clase.
    *
    * Este método permite acceder al mensaje del último error ocurrido durante
    * cualquier operación de la base de datos (conexión, consultas, transacciones, etc.).
    * Es útil para depuración, logging o mostrar información controlada al usuario.
    *
    * @return string|null  El mensaje del último error, o `null` si no ha ocurrido ningún error.
    */
    public function getLastError(): ?string {
        return $this->lastError;
    }

    /*
    *===========================================================================
    * Escapa un valor para su uso seguro en una consulta SQL.
    *
    * Este método utiliza PDO::quote() para proteger valores de inyección SQL,
    * asegurando que cualquier cadena proporcionada pueda ser utilizada directamente
    * en una consulta SQL sin riesgo de alterar la sintaxis.
    *
    * Antes de escapar el valor, se asegura que exista una conexión activa
    * mediante `ensureConnection()`.
    *
    * @param mixed $value  El valor a escapar. Generalmente se usa con strings,
    *                      pero PDO::quote puede manejar números y otros tipos
    *                      convertibles a string.
    *
    * @return string       El valor escapado y entre comillas, listo para usar
    *                      en una sentencia SQL.
    */
    public function escape($value): string {
        $this->ensureConnection();
        return $this->connection->quote($value);
    }

    /*
    *===========================================================================
    * Destructor - cierra la conexión
    */
    public function __destruct() {
        $this->disconnect();
    }
}

# Sistema de Telemetría - Endpoint PHP con MQTT

Sistema completo de recepción y procesamiento de datos de telemetría construido en PHP con MQTT, Redis y MySQL. Incluye caché inteligente, rate limiting, validación robusta, cálculo de distancias geográficas y logging completo.

## 📋 Tabla de Contenidos

- [Características](#-características)
- [Requisitos](#-requisitos)
- [Instalación](#-instalación)
- [Configuración](#-configuración)
- [Ejecución](#ejecución)
- [Uso](#-uso)
- [Estructura del Proyecto](#-estructura-del-proyecto)
- [Módulos](#-módulos)
- [Migración a Otras Bases de Datos](#-migración-a-otras-bases-de-datos)
- [Solución de Problemas](#-solución-de-problemas)
- [Notas Adicionales](#-notas-adicionales)

## ✨ Características

- ✅ **Recepción de datos**: Soporta HTTP POST y MQTT
- ✅ **Caché Redis**: Almacenamiento en caché de dispositivos para consultas rápidas
- ✅ **Rate Limiting**: Control de límite de peticiones por dispositivo configurable
- ✅ **Validación Robusta**: Validación completa de datos de entrada
- ✅ **Cálculo de Distancia**: Fórmula de Haversine para cálculo preciso de distancias
- ✅ **Logging Completo**: Logs por dispositivo, requests inválidos, sistema y errores
- ✅ **Arquitectura Modular**: Fácil mantenimiento y extensión
- ✅ **Abstracción de base de datos**: Migración simple a otros motores de base de datos
- ✅ **Manejo de Errores**: Registro de errores en base de datos y archivos
- ✅ **Validación de tiempo offline**: Detección de dispositivos fuera de línea

## 🛠️ Requisitos

### Software Requerido
- **PHP**: 7.0 o superior
- **MySQL**: 5.7 o superior (o MariaDB 10.2+, PostgreSQL, SQL Server)
- **Redis**: 6.0 o superior
- **MQTT Broker**: Mosquitto 1.4+ (u otro broker compatible)
- **Composer**: Gestor de dependencias PHP
- **Extensiones PHP**:
  - `pdo_mysql`
  - `redis`
  - `json`
  - `mbstring`
  - `pcntl` (para manejo de señales en daemon)

### Servidor Web (Opcional)
- Apache 2.4+ con `mod_rewrite` habilitado (si se mantiene endpoint HTTP)
- Nginx 1.10+ (configuración alternativa)

## 📦 Instalación

### 1. Clonar o descargar el proyecto

```bash
git clone https://github.com/tenshi98/telemetria-endpoint-PHP-MQTT.git
cd telemetria-endpoint-PHP-MQTT
```

### 2. Instalar dependencias

```bash
# Instalar Composer si no está instalado
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Instalar dependencias PHP
composer install
```

### 3. Instalar Base de Datos

```bash
# Conectar a MySQL
mysql -u root -p

# Ejecutar schema
mysql -u root -p < database/schema.sql

# (Opcional) Cargar datos de prueba
mysql -u root -p < database/seed.sql
```

### 4. Configurar Permisos

```bash
chmod -R 755 .
chmod -R 777 logs/
mkdir -p logs/devices
chmod +x bin/mqtt_daemon.php
```

### 5. Instalar Redis (opcional)

```bash
# Ubuntu/Debian
sudo apt install redis-server

# Iniciar Redis
sudo systemctl start redis-server
sudo systemctl enable redis-server
# o
redis-server

# Verificar que Redis esté corriendo
redis-cli ping
# Debe responder: PONG
```

### 6. Instalar Mosquitto (Broker MQTT - opcional)

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y mosquitto mosquitto-clients

# Iniciar Mosquitto
sudo systemctl start mosquitto
sudo systemctl enable mosquitto

# Verificar que está corriendo
sudo systemctl status mosquitto
mosquitto_pub -h localhost -t test -m "hello"
```

### 7. Iniciar Daemon MQTT

#### Modo Manual (Desarrollo/Pruebas)

```bash
# Iniciar daemon en foreground
php bin/mqtt_daemon.php

# Detener con Ctrl+C
```

#### Modo Servicio (Producción)

```bash
# Copiar archivo de servicio systemd
sudo cp systemd/mqtt.service /etc/systemd/system/

# Editar rutas si es necesario
sudo nano /etc/systemd/system/mqtt.service

# Recargar systemd
sudo systemctl daemon-reload

# Iniciar servicio
sudo systemctl start mqtt

# Habilitar auto-inicio en boot
sudo systemctl enable mqtt

# Verificar estado
sudo systemctl status mqtt

# Ver logs en tiempo real
sudo journalctl -u mqtt -f
```

## ⚙️ Configuración

### 1. Configurar variables de entorno

```bash
# Copiar archivo de configuración de ejemplo
cp .env.example .env

# Editar configuración
nano .env
```

Ajustar los valores en `.env`:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=telemetria
DB_USER=root
DB_PASS=tu_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MQTT_BROKER_HOST=localhost
MQTT_BROKER_PORT=1883
MQTT_TOPICS=telemetry/#

LOG_PATH=/telemetria-endpoint-PHP-MQTT/logs
```

### Archivo de Configuración Principal

El archivo `config/config.php` contiene toda la configuración del sistema. Los valores pueden ser sobrescritos mediante variables de entorno (archivo `.env`).

### Parámetros Principales

| Parámetro | Descripción | Default |
|-----------|-------------|---------|
| `DB_HOST` | Host de MySQL | localhost |
| `DB_PORT` | Puerto de MySQL | 3306 |
| `DB_NAME` | Nombre de la base de datos | telemetria |
| `REDIS_HOST` | Host de Redis | 127.0.0.1 |
| `REDIS_PORT` | Puerto de Redis | 6379 |
| `RATE_LIMIT_DELAY_MS` | Delay entre requests (ms) | 100 |
| `RATE_LIMIT_MAX_PER_MIN` | Máximo requests por minuto | 60 |
| `LOG_LEVEL` | Nivel de logging | INFO |
| `APP_DEBUG` | Modo debug | false |

### Estructura de Datos en Redis

Los dispositivos se almacenan como hashes con la siguiente estructura:

```
Key: telemetry:device:{Identificador}
Fields:
  - idTelemetria: INT
  - Identificador: STRING
  - UltimaConexion: TIMESTAMP
  - TiempoFueraLinea: TIME
  - Latitud: DECIMAL
  - Longitud: DECIMAL
TTL: 24 horas (configurable)
```

## 🏃 Ejecución

### 1. Configurar Servidor Web

#### Apache

El archivo `.htaccess` ya está incluido en `public/`. Asegúrate de que `mod_rewrite` esté habilitado:

```bash
sudo a2enmod rewrite
sudo service apache2 restart
```

Configurar VirtualHost (opcional):

```apache
<VirtualHost *:80>
    ServerName telemetria.local
    DocumentRoot /telemetria-endpoint-PHP-MQTT/public

    <Directory /telemetria-endpoint-PHP-MQTT/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name telemetria.local;
    root /telemetria-endpoint-PHP-MQTT/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```


## 📡 Uso

### Recepción de Datos vía MQTT

El sistema recibe datos de telemetría mediante mensajes MQTT publicados en topics configurados.

### Topics MQTT

**Estructura recomendada**:
```
telemetry/{Identificador}
```

**Ejemplos**:
- `telemetry/DEVICE001`
- `telemetry/DEVICE002`
- `telemetry/SENSOR_XYZ`

### Formato de Mensaje MQTT

Los mensajes deben ser JSON válido con la siguiente estructura:

#### Campos Requeridos

```json
{
  "Identificador": "DEVICE001",
  "Latitud": -34.603722,
  "Longitud": -58.381592
}
```

#### Campos Opcionales

```json
{
  "Identificador": "DEVICE001",
  "Latitud": -34.603722,
  "Longitud": -58.381592,
  "Sensor_1": 25.5,
  "Sensor_2": 60.2,
  "Sensor_3": 1013.25,
  "Sensor_4": 100.0,
  "Sensor_5": 50.5
}
```

### Ejemplos de Uso

#### 1. Publicar Datos con mosquitto_pub (Línea de Comandos)

```bash
mosquitto_pub -h localhost -t "telemetry/DEVICE001" -m '{
  "Identificador": "DEVICE001",
  "Latitud": -34.603722,
  "Longitud": -58.381592,
  "Sensor_1": 25.5,
  "Sensor_2": 60.2
}'
```

**Resultado**: El daemon procesa el mensaje y registra en logs:
- `logs/devices/DEVICE001.log`
- `logs/system.log`

#### 2. Publicar desde Python

```python
import paho.mqtt.client as mqtt
import json

# Configurar cliente
client = mqtt.Client()
client.connect("localhost", 1883, 60)

# Preparar datos
data = {
    "Identificador": "DEVICE001",
    "Latitud": -34.603722,
    "Longitud": -58.381592,
    "Sensor_1": 25.5,
    "Sensor_2": 60.2
}

# Publicar
topic = "telemetry/DEVICE001"
payload = json.dumps(data)
client.publish(topic, payload, qos=1)

client.disconnect()
```

#### 3. Publicar desde Arduino/ESP32

```cpp
#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>

WiFiClient espClient;
PubSubClient client(espClient);

void setup() {
    // Conectar WiFi
    WiFi.begin("SSID", "PASSWORD");
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
    }

    // Configurar MQTT
    client.setServer("servidor", 1883);
    client.connect("DEVICE001");
}

void loop() {
    if (!client.connected()) {
        client.connect("DEVICE001");
    }

    // Crear JSON
    StaticJsonDocument<200> doc;
    doc["Identificador"] = "DEVICE001";
    doc["Latitud"] = -34.603722;
    doc["Longitud"] = -58.381592;
    doc["Sensor_1"] = 25.5;

    char buffer[200];
    serializeJson(doc, buffer);

    // Publicar
    client.publish("telemetry/DEVICE001", buffer);

    delay(5000);
}
```

### Verificar Procesamiento

#### Ver Logs del Daemon

```bash
# Si es servicio systemd
sudo journalctl -u mqtt -f

# Ver logs de dispositivo específico
tail -f logs/devices/DEVICE001.log

# Ver logs del sistema
tail -f logs/system.log
```

#### Verificar en Base de Datos

```sql
-- Ver última medición
SELECT * FROM mediciones_telemetria
ORDER BY FechaHora DESC
LIMIT 1;

-- Ver dispositivo
SELECT * FROM equipos_telemetria
WHERE Identificador = 'DEVICE001';
```

#### Verificar en Redis

```bash
redis-cli HGETALL "telemetry:device:DEVICE001"
```

### Manejo de Errores

Los errores se registran en:
- **Base de datos**: Tabla `errores_validacion`
- **Logs**: `logs/errors.log` y `logs/devices/{Identificador}.log`
- **Systemd journal**: `sudo journalctl -u mqtt`

Ejemplos de errores comunes:
- **JSON inválido**: Mensaje no es JSON válido
- **Campos faltantes**: No incluye Identificador, Latitud o Longitud
- **Coordenadas inválidas**: Latitud/Longitud fuera de rango
- **Dispositivo no encontrado**: Identificador no existe en BD
- **Rate limit**: Demasiados mensajes en corto tiempo

## 📁 Estructura del Proyecto

```
telemetria-endpoint-PHP-MQTT/
├── bin/
│   └── mqtt_daemon.php         # Daemon MQTT (ejecutable)
├── config/
│   └── config.php              # Configuración principal
├── database/
│   ├── schema.sql              # Schema de MySQL
│   └── seed.sql                # Datos de prueba
├── logs/                       # Directorio de logs (auto-creado)
│   ├── devices/                # Logs por dispositivo
│   ├── system.log              # Log del sistema
│   ├── errors.log              # Log de errores
│   └── invalid_requests.log    # Requests inválidos
├── public/
│   ├── .htaccess               # Configuración Apache (opcional)
│   └── index.php               # Endpoint HTTP (deprecado/opcional)
├── src/
│   ├── Cache/
│   │   └── RedisCache.php      # Manejador de Redis
│   ├── Database/
│   │   ├── Database.php        # Interfaz abstracta
│   │   └── MySQLDatabase.php   # Implementación MySQL
│   ├── Logger/
│   │   └── Logger.php          # Sistema de logging
│   ├── MQTT/
│   │   └── MQTTSubscriber.php  # Cliente MQTT
│   ├── RateLimit/
│   │   └── RateLimiter.php     # Control de rate limiting
│   ├── Service/
│   │   └── TelemetryService.php # Lógica de negocio
│   ├── Utils/
│   │   └── GeoCalculator.php   # Cálculos geográficos
│   └── Validation/
│       └── Validator.php       # Validación de datos
├── systemd/
│   └── mqtt.service            # Servicio systemd
├── vendor/                     # Dependencias Composer
├── .env.example                # Plantilla de configuración
├── composer.json               # Dependencias PHP
├── composer.lock               # Lock de dependencias
├── MIGRATION_GUIDE.md          # Guía de migración POST → MQTT
└── README.md                   # Este archivo
```

## 🧩 Módulos

### 1. MQTT Subscriber

**Ubicación**: `src/MQTT/MQTTSubscriber.php`

**Propósito**: Maneja la conexión al broker MQTT y la recepción de mensajes.

**Características**:
- Conexión al broker con autenticación opcional
- Suscripción a múltiples topics
- Reconexion automática con exponential backoff
- Callback personalizable para procesamiento de mensajes
- Publicación de mensajes (para respuestas)

### 2. Daemon MQTT

**Ubicación**: `bin/mqtt_daemon.php`

**Propósito**: Script daemon que mantiene el sistema corriendo continuamente.

**Características**:
- Inicialización de todos los componentes del sistema
- Manejo de señales del sistema (SIGTERM, SIGINT, SIGHUP)
- Shutdown graceful
- Procesamiento de mensajes MQTT usando TelemetryService
- Logging completo de operaciones

### 3. Database (Abstracción de Base de Datos)

**Ubicación**: `src/Database/`

**Propósito**: Proporciona una interfaz abstracta para operaciones de base de datos, permitiendo cambiar fácilmente entre diferentes motores (MySQL, PostgreSQL, SQL Server).

**Archivos**:
- `Database.php`: Interfaz que define el contrato
- `MySQLDatabase.php`: Implementación para MySQL usando PDO

**Características**:
- Prepared statements para prevenir inyección SQL
- Manejo de transacciones
- Reconexión automática
- Manejo de errores

### 2. Cache (Redis)

**Ubicación**: `src/Cache/RedisCache.php`

**Propósito**: Gestiona el almacenamiento en caché de datos de dispositivos para reducir consultas a MySQL.

**Características**:
- Almacenamiento de dispositivos como hashes
- TTL configurable
- Operaciones atómicas
- Fallback graceful si Redis falla

### 3. Logger

**Ubicación**: `src/Logger/Logger.php`

**Propósito**: Sistema de logging con múltiples niveles y destinos.

**Características**:
- Niveles: INFO, WARNING, ERROR
- Logs por dispositivo (un archivo por identificador)
- Log de requests inválidos con IP
- Rotación automática de archivos
- Formato estructurado con timestamps

### 4. Validator

**Ubicación**: `src/Validation/Validator.php`

**Propósito**: Valida los datos de entrada del POST.

**Características**:
- Validación de campos requeridos
- Validación de rangos de coordenadas
- Validación de tipos de datos
- Sanitización de datos
- Mensajes de error detallados

### 5. RateLimiter

**Ubicación**: `src/RateLimit/RateLimiter.php`

**Propósito**: Controla la frecuencia de requests para evitar sobrecarga.

**Características**:
- Delay configurable entre requests
- Límite de requests por minuto
- Almacenamiento en Redis
- Estadísticas de uso

### 6. GeoCalculator

**Ubicación**: `src/Utils/GeoCalculator.php`

**Propósito**: Cálculos geográficos precisos.

**Características**:
- Fórmula de Haversine para distancias
- Validación de coordenadas
- Cálculo de punto medio
- Precisión configurable

### 7. TelemetryService

**Ubicación**: `src/Service/TelemetryService.php`

**Propósito**: Orquesta toda la lógica de negocio del sistema.

**Características**:
- Búsqueda de dispositivos (Redis → MySQL)
- Validación de tiempo fuera de línea
- Cálculo de distancia
- Persistencia de datos
- Registro de errores
- Actualización de caché

## 🔄 Migración a Otras Bases de Datos

El sistema está diseñado para facilitar la migración a otros motores de base de datos.

### PostgreSQL

#### 1. Crear Implementación

Crear `src/Database/PostgreSQLDatabase.php`:

```php
<?php
namespace Telemetry\Database;

use PDO;
use Exception;

class PostgreSQLDatabase implements Database
{
    // Implementar todos los métodos de la interfaz Database
    // Similar a MySQLDatabase pero con sintaxis PostgreSQL

    public function connect(): bool
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database']
        );
        // ... resto de la implementación
    }
}
```

#### 2. Adaptar Schema

```sql
-- database/schema_postgresql.sql
CREATE TABLE equipos_telemetria (
    idTelemetria SERIAL PRIMARY KEY,
    Identificador VARCHAR(255) NOT NULL UNIQUE,
    Nombre VARCHAR(255) NOT NULL,
    UltimaConexion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    TiempoFueraLinea TIME DEFAULT '00:00:00'
);

-- Nota: PostgreSQL usa SERIAL en lugar de AUTO_INCREMENT
```

#### 3. Actualizar Configuración

```php
// config/config.php
'database' => [
    'driver' => 'pgsql',
    // ... resto de configuración
],
```

#### 4. Modificar index.php

```php
// public/index.php
use Telemetry\Database\PostgreSQLDatabase;

// Cambiar:
$db = new MySQLDatabase($config['database']);
// Por:
$db = new PostgreSQLDatabase($config['database']);
```

### SQL Server

#### 1. Crear Implementación

```php
<?php
namespace Telemetry\Database;

class SQLServerDatabase implements Database
{
    public function connect(): bool
    {
        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database']
        );
        // ... implementación
    }
}
```

#### 2. Schema para SQL Server

```sql
-- database/schema_sqlserver.sql
CREATE TABLE equipos_telemetria (
    idTelemetria INT IDENTITY(1,1) PRIMARY KEY,
    Identificador NVARCHAR(255) NOT NULL UNIQUE,
    Nombre NVARCHAR(255) NOT NULL,
    UltimaConexion DATETIME DEFAULT GETDATE(),
    TiempoFueraLinea TIME DEFAULT '00:00:00'
);
```

### Pasos Generales para Cualquier Motor

1. **Crear clase que implemente `Database` interface**
2. **Adaptar sintaxis SQL específica del motor**
3. **Ajustar tipos de datos según el motor**
4. **Modificar DSN de conexión PDO**
5. **Actualizar configuración**
6. **Instanciar nueva clase en `index.php`**

## 🐛 Solución de Problemas

### Error: "No se pudo conectar a Redis"

**Solución**:
```bash
# Verificar que Redis esté corriendo
sudo service redis-server status

# Iniciar Redis si no está corriendo
sudo service redis-server start

# Verificar conectividad
redis-cli ping
```

### Error: "Error al conectar con MySQL"

**Solución**:
- Verificar credenciales en `.env`
- Verificar que MySQL esté corriendo
- Verificar que la base de datos exista
- Verificar permisos del usuario

```bash
mysql -u root -p -e "SHOW DATABASES;"
mysql -u root -p -e "GRANT ALL ON telemetria.* TO 'tu_usuario'@'localhost';"
```

### Error: "Permission denied" en logs

**Solución**:
```bash
chmod -R 777 logs/
chown -R www-data:www-data logs/  # Usuario de Apache
```

### Rate Limit Siempre Activo

**Solución**:
- Verificar configuración en `.env`
- Limpiar caché de Redis:

```bash
redis-cli FLUSHDB
```

### Logs No Se Crean

**Solución**:
- Verificar permisos del directorio `logs/`
- Verificar que `LOG_ENABLED=true` en `.env`
- Verificar ruta en `LOG_PATH`

### Distancia Siempre 0

**Causa**: No hay coordenadas previas en caché o base de datos.

**Solución**: Es normal en el primer request de un dispositivo. Los siguientes requests calcularán la distancia correctamente.

## 📝 Notas Adicionales

### Seguridad

- Todos los queries usan prepared statements
- Validación estricta de entrada
- Headers de seguridad en `.htaccess`
- Rate limiting para prevenir abuso
- Logs de requests sospechosos

### Performance

- Caché Redis reduce carga en MySQL
- Índices optimizados en tablas
- Conexiones persistentes
- TTL configurable para caché

### Mantenimiento

- Logs con rotación automática
- Vistas SQL para consultas comunes
- Código documentado
- Arquitectura modular


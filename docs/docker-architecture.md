# Docker Architecture

Локальная инфраструктура ориентирована на production-like разработку без Kubernetes: каждый доменный сервис является отдельным Laravel-приложением на PHP-FPM, Nginx является единственной публичной HTTP-точкой входа, PostgreSQL изолирован по доменам, Redis используется как общий брокер Redis Streams.

## Monorepo Structure

```text
.
├── api-gateway/              # Laravel BFF/proxy layer
│   ├── Dockerfile
│   └── .env.docker.example
├── user/                     # Laravel user-service
│   ├── Dockerfile
│   └── .env.docker.example
├── order/                    # Laravel order-service
│   ├── Dockerfile
│   └── .env.docker.example
├── report/                   # Laravel report-service
│   ├── Dockerfile
│   └── .env.docker.example
├── saga-orchestrator/        # Laravel saga orchestration app
│   ├── Dockerfile
│   └── .env.docker.example
├── docker/
│   ├── env/                  # PostgreSQL env files
│   ├── nginx/default.conf    # Public gateway and internal router
│   └── php/conf.d/           # Development PHP overrides
├── docs/docker-architecture.md
└── docker-compose.yml
```

## Docker Networks

`edge-network`:
публичный край только для `nginx`. На хост опубликован порт `8080`.

`app-network`:
внутренняя сеть приложения. В ней находятся `nginx`, `api-gateway`, `user-service`, `order-service`, `report-service`, `saga-orchestrator`, `saga-orchestrator-worker` и `redis`. Сервисам доступен DNS Docker Compose по именам контейнеров и alias `internal-router`.

`user-db-network`:
только `user-service` и `postgres-user`.

`order-db-network`:
только `order-service` и `postgres-order`.

`report-db-network`:
только `report-service` и `postgres-report`.

PostgreSQL-контейнеры не публикуют порты на хост и не подключены к `app-network`, поэтому другой доменный сервис не может обратиться к чужой базе.

## Request Flow

```text
Client
  |
  | HTTP :8080/api/*
  v
Nginx public listener :80
  |
  | FastCGI
  v
api-gateway Laravel PHP-FPM
  |
  | HTTP через app-network
  | USER_SERVICE_URL=http://internal-router:8081/user
  | ORDER_SERVICE_URL=http://internal-router:8081/order
  | REPORT_SERVICE_URL=http://internal-router:8081/report
  v
Nginx internal listener :8081
  |
  | FastCGI по нужному upstream
  v
user-service / order-service / report-service / saga-orchestrator
  |
  | pgsql только в своей db-network
  v
postgres-user / postgres-order / postgres-report
```

## Redis Streams Flow

```text
user-service   -- XADD user.created ------+
order-service  -- XADD order.created -----+--> Redis Streams
report-service -- XADD report.generated --+        |
                                                  XREADGROUP
                                                     |
                                                     v
                                           saga-orchestrator-worker
                                                     |
                                    coordinates service calls over app-network
```

Рекомендуемые stream names:

```text
user.created
order.created
report.generated
```

`saga-orchestrator-worker` запускает Laravel-команду `php artisan saga:consume-streams`. Команду нужно реализовать в приложении `saga-orchestrator`: создать consumer group `saga-orchestrator`, читать события через `XREADGROUP`, подтверждать обработку через `XACK`, а бизнес-компенсации выполнять через внутренние URLs сервисов.

## Live Reload

Код каждого сервиса монтируется bind mount-ом:

```text
./api-gateway         -> /var/www
./user                -> /var/www
./order               -> /var/www
./report              -> /var/www
./saga-orchestrator   -> /var/www
```

Изменения PHP-кода на хосте сразу видны в контейнере. Для Composer-зависимостей используются named volumes `/var/www/vendor`, чтобы bind mount не затирал зависимости, установленные на build-стадии.

OPcache включён, но настроен для разработки:

```ini
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

## Local Run

После добавления Laravel-приложений в сервисные каталоги:

```bash
docker compose build
docker compose up -d
```

Публичный endpoint:

```text
http://localhost:8080/api
```

Внутренние сервисы напрямую с хоста не публикуются.

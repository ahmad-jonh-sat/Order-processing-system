# Order Processing System

## English

Production-style distributed backend system for an online shop. The project demonstrates Laravel microservices, API Gateway routing, JWT authentication, Redis Streams, Saga orchestration, compensating actions, idempotency, PostgreSQL transactions, and eventual consistency without distributed transactions.

The system is intentionally small enough for learning, but it models real backend problems: partial failures, duplicate events, race conditions, service boundaries, transactional consistency inside each service, and cross-service consistency through orchestration.

## Architecture

This is a monorepo with five independent Laravel applications:

- `api-gateway` - the only public HTTP entry point. It proxies external `/api/*` requests to internal services and forwards the `Authorization` header.
- `user` - owns users, JWT issuing, wallets, balance changes, payment charge/refund operations.
- `order` - owns products, orders, order items, stock reservations, stock commit/release logic.
- `report` - owns receipt and cancellation reports, report metadata, and report file downloads.
- `saga-orchestrator` - coordinates the purchase flow with Redis Streams and stores saga state in PostgreSQL.

Infrastructure:

- `nginx` exposes the public API at `http://localhost:8080/api/*`.
- `internal-router` is available only inside Docker networks for service-to-service HTTP calls.
- PostgreSQL is separated per domain service:
  - `postgres-user`
  - `postgres-order`
  - `postgres-report`
  - `postgres-saga`
- Redis is used as the shared event broker with Redis Streams.
- Docker Compose remains the local development runtime.

More Docker details are described in `docs/docker-architecture.md`.

## Business Flow

The main scenario is a user buying products from the online shop:

1. The user registers or logs in through `api-gateway`.
2. `user` issues an RS256 JWT.
3. The client sends `POST /api/shop/orders` through `api-gateway`.
4. `order` verifies the JWT, creates an order with status `pending`, stores fixed item prices, and publishes `order.created`.
5. `saga-orchestrator` consumes `order.created` from Redis Streams.
6. The saga asks `order` to reserve stock.
7. If stock reservation succeeds, the saga asks `user` to charge the wallet.
8. If payment succeeds, the saga asks `order` to complete the order.
9. `order` commits the stock reservation and publishes `order.completed`.
10. The saga asks `report` to generate a receipt.
11. `report` stores report metadata and creates a downloadable report file.

Cancellation flow:

1. The client sends `POST /api/shop/orders/{id}/cancel`.
2. `api-gateway` forwards the request to `saga-orchestrator`.
3. `saga-orchestrator` verifies JWT ownership of the order.
4. The saga runs compensating actions:
   - refund payment in `user`;
   - release reserved stock or return committed stock in `order`;
   - mark the order as `cancelled`;
   - generate a cancellation report in `report`.

## Consistency Model

There is no distributed transaction between services.

The project uses these rules instead:

- Each service owns its database and wraps local state changes in PostgreSQL transactions.
- Money operations use `decimal(15,2)` and BCMath-style decimal calculations, not floats.
- Wallet balance updates use `lockForUpdate()`.
- Product stock reservation uses `lockForUpdate()` on product rows.
- Cross-service consistency is handled by Saga orchestration and compensating actions.
- External commands are idempotent:
  - wallet operations by `operation_id`;
  - orders by `idempotency_key`;
  - saga steps by `saga_steps.idempotency_key`;
  - reports by `order_id + type`.
- Redis Streams consumer groups are used by `saga-orchestrator`.
- Events are acknowledged with `XACK` only after successful processing.
- Duplicate event delivery should not corrupt state because handlers are idempotent.

## Domain Model

### User Service

Main tables:

- `users`
- `wallets`
- `wallet_operations`

Wallet features:

- get current user's wallet;
- top up wallet for testing;
- charge wallet for an order;
- refund wallet during cancellation;
- validate positive amounts;
- validate matching currency;
- prevent negative balances;
- keep operations idempotent by `operation_id`.

### Order Service

Main tables:

- `products`
- `orders`
- `order_items`
- `stock_reservations`

Order statuses:

- `pending`
- `stock_reserved`
- `payment_reserved`
- `completed`
- `cancelled`
- `failed`

Order features:

- create an order;
- store item price snapshot at order creation time;
- reserve stock transactionally;
- commit stock after successful payment;
- release stock on cancellation;
- restore committed stock when a completed order is cancelled;
- reject reservation when requested quantity is greater than available stock.

### Saga Orchestrator

Main tables:

- `saga_instances`
- `saga_steps`

Command:

```bash
php artisan saga:consume-streams
```

The worker consumes `order.created` and coordinates:

- reserve stock;
- charge payment;
- complete order;
- generate receipt;
- compensate on failure.

### Report Service

Main table:

- `reports`

Report features:

- generate receipt report for completed orders;
- generate cancellation report for cancelled orders;
- get report metadata by `order_id`;
- download report file.

The current implementation creates a simple downloadable `.pdf` file without adding heavy PDF infrastructure. This keeps the project lightweight while preserving the report generation and download flow.

## Redis Streams

The flow uses these stream names:

- `order.created`
- `stock.reserved`
- `stock.reservation.failed`
- `payment.reserved`
- `payment.failed`
- `order.completed`
- `order.cancelled`
- `report.generated`

The orchestrator consumes `order.created` with a consumer group:

- group: `saga-orchestrator`
- default consumer: `saga-orchestrator-1`

## Public API

All public requests go through:

```text
http://localhost:8080/api/*
```

Authentication:

```http
Authorization: Bearer <JWT>
```

Main routes:

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/users/me`
- `GET /api/shop/products`
- `POST /api/shop/orders`
- `GET /api/shop/orders/{id}`
- `POST /api/shop/orders/{id}/cancel`
- `GET /api/shop/reports/{orderId}`
- `GET /api/shop/reports/{orderId}/download`
- `POST /api/shop/wallet/top-up`
- `GET /api/shop/wallet`

Order creation body:

```json
{
  "idempotency_key": "order-uuid-1",
  "items": [
    {
      "product_id": 1,
      "quantity": 2
    }
  ]
}
```

Wallet top-up body:

```json
{
  "amount": "2000.00",
  "currency": "USD",
  "operation_id": "topup-1"
}
```

## Local Setup

Build and start services:

```bash
docker compose up -d --build
```

Run migrations and seeders:

```bash
docker compose exec user-service php artisan migrate --seed
docker compose exec order-service php artisan migrate --seed
docker compose exec report-service php artisan migrate
docker compose exec saga-orchestrator php artisan migrate
```

The saga worker is configured in Docker Compose:

```bash
docker compose logs -f saga-orchestrator-worker
```

If you want to run it manually:

```bash
docker compose exec saga-orchestrator php artisan saga:consume-streams
```

## Seed Data

The order service seeds products:

- Laptop - `1000.00 USD`, stock `5`
- Phone - `500.00 USD`, stock `10`
- Headphones - `100.00 USD`, stock `20`

The user service seeds a test user and wallet for development.

## Curl Scenario

Set base URL:

```bash
BASE=http://localhost:8080/api
```

Register user:

```bash
curl -s -X POST "$BASE/auth/register" \
  -H "Content-Type: application/json" \
  -d '{"name":"Buyer","email":"buyer@example.com","password":"password123"}'
```

Login:

```bash
TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"buyer@example.com","password":"password123"}' | jq -r .access_token)
```

Top up wallet:

```bash
curl -s -X POST "$BASE/shop/wallet/top-up" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount":"2000.00","currency":"USD","operation_id":"topup-1"}'
```

View wallet:

```bash
curl -s "$BASE/shop/wallet" \
  -H "Authorization: Bearer $TOKEN"
```

View products:

```bash
curl -s "$BASE/shop/products"
```

Create order:

```bash
curl -s -X POST "$BASE/shop/orders" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"idempotency_key":"order-uuid-1","items":[{"product_id":1,"quantity":1}]}'
```

Check order:

```bash
curl -s "$BASE/shop/orders/1" \
  -H "Authorization: Bearer $TOKEN"
```

Check wallet after saga processing:

```bash
curl -s "$BASE/shop/wallet" \
  -H "Authorization: Bearer $TOKEN"
```

Check stock:

```bash
curl -s "$BASE/shop/products"
```

Get report metadata:

```bash
curl -s "$BASE/shop/reports/1" \
  -H "Authorization: Bearer $TOKEN"
```

Download report:

```bash
curl -L "$BASE/shop/reports/1/download" \
  -H "Authorization: Bearer $TOKEN" \
  -o order-1.pdf
```

Cancel order:

```bash
curl -s -X POST "$BASE/shop/orders/1/cancel" \
  -H "Authorization: Bearer $TOKEN"
```

Verify compensation:

```bash
curl -s "$BASE/shop/orders/1" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/shop/wallet" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/shop/products"
curl -s "$BASE/shop/reports/1" -H "Authorization: Bearer $TOKEN"
```

## Tests

Run tests per service:

```bash
docker compose exec user-service php artisan test
docker compose exec order-service php artisan test
docker compose exec report-service php artisan test
docker compose exec saga-orchestrator php artisan test
docker compose exec api-gateway php artisan test
```

Covered areas:

- wallet idempotency and insufficient funds;
- stock reservation idempotency;
- report generation idempotency;
- saga purchase flow;
- API Gateway shop proxy routes.

## Security Notes

- `user` issues RS256 JWT tokens.
- `order`, `report`, and `saga-orchestrator` verify JWT with the public key.
- The private key must not be committed.
- `user/storage/keys/jwt_private.pem` is ignored by `.gitignore`.
- For real environments, replace local development keys with secrets mounted through infrastructure or a secret manager.

## Current Scope

This project is intentionally not using Kubernetes, message broker clusters, heavy PDF generation, or external payment providers.

It focuses on backend architecture concepts:

- Laravel microservices;
- API Gateway;
- JWT authentication;
- service-owned databases;
- Redis Streams;
- Saga orchestration;
- idempotency;
- compensating actions;
- transaction boundaries;
- eventual consistency.

---

## Русский

Production-like распределенная backend-система для онлайн-магазина. Проект демонстрирует Laravel microservices, API Gateway, JWT авторизацию, Redis Streams, Saga orchestration, компенсационные действия, идемпотентность, PostgreSQL transactions и eventual consistency без distributed transactions.

Проект специально оставлен достаточно простым для обучения, но показывает реальные backend-проблемы: частичные сбои, повторную доставку событий, race conditions, границы ответственности сервисов, транзакционную консистентность внутри сервиса и межсервисную консистентность через оркестрацию.

## Архитектура

Это monorepo с пятью независимыми Laravel-приложениями:

- `api-gateway` - единственная публичная HTTP-точка входа. Проксирует внешние `/api/*` запросы во внутренние сервисы и передает `Authorization` header.
- `user` - владеет пользователями, выпуском JWT, кошельками, изменениями баланса, списанием и возвратом денег.
- `order` - владеет товарами, заказами, позициями заказа, складскими резервами, commit/release логикой склада.
- `report` - владеет чеками и отчетами об отмене, метаданными отчетов и скачиванием файлов.
- `saga-orchestrator` - координирует процесс покупки через Redis Streams и хранит состояние saga в PostgreSQL.

Инфраструктура:

- `nginx` открывает публичный API на `http://localhost:8080/api/*`.
- `internal-router` доступен только внутри Docker networks для service-to-service HTTP вызовов.
- PostgreSQL разделен по доменным сервисам:
  - `postgres-user`
  - `postgres-order`
  - `postgres-report`
  - `postgres-saga`
- Redis используется как общий event broker через Redis Streams.
- Docker Compose остается runtime для локальной разработки.

Подробнее Docker-архитектура описана в `docs/docker-architecture.md`.

## Бизнес-Сценарий

Основной сценарий - пользователь покупает товары в онлайн-магазине:

1. Пользователь регистрируется или логинится через `api-gateway`.
2. `user` выпускает RS256 JWT.
3. Клиент отправляет `POST /api/shop/orders` через `api-gateway`.
4. `order` проверяет JWT, создает заказ в статусе `pending`, фиксирует цены товаров в заказе и публикует `order.created`.
5. `saga-orchestrator` читает `order.created` из Redis Streams.
6. Saga просит `order` зарезервировать товар на складе.
7. Если резерв склада успешен, saga просит `user` списать деньги с кошелька.
8. Если оплата успешна, saga просит `order` завершить заказ.
9. `order` применяет складской резерв и публикует `order.completed`.
10. Saga просит `report` сгенерировать чек.
11. `report` сохраняет метаданные отчета и создает файл для скачивания.

Сценарий отмены:

1. Клиент отправляет `POST /api/shop/orders/{id}/cancel`.
2. `api-gateway` проксирует запрос в `saga-orchestrator`.
3. `saga-orchestrator` проверяет JWT и принадлежность заказа пользователю.
4. Saga запускает компенсационные действия:
   - возвращает деньги в `user`;
   - снимает резерв или возвращает уже списанный товар на склад в `order`;
   - помечает заказ как `cancelled`;
   - генерирует отчет об отмене в `report`.

## Модель Консистентности

Distributed transaction между сервисами нет.

Вместо этого используются правила:

- Каждый сервис владеет своей БД и выполняет локальные изменения внутри PostgreSQL transaction.
- Денежные операции используют `decimal(15,2)` и decimal-арифметику, не float.
- Обновления баланса используют `lockForUpdate()`.
- Резерв склада использует `lockForUpdate()` на строках товаров.
- Межсервисная консистентность достигается через Saga orchestration и compensating actions.
- Внешние команды идемпотентны:
  - wallet operations по `operation_id`;
  - orders по `idempotency_key`;
  - saga steps по `saga_steps.idempotency_key`;
  - reports по `order_id + type`.
- `saga-orchestrator` использует Redis Streams consumer groups.
- События подтверждаются через `XACK` только после успешной обработки.
- Повторная доставка события не должна ломать состояние, потому что handlers идемпотентны.

## Доменная Модель

### User Service

Основные таблицы:

- `users`
- `wallets`
- `wallet_operations`

Возможности кошелька:

- получить кошелек текущего пользователя;
- пополнить кошелек для тестов;
- списать деньги за заказ;
- вернуть деньги при отмене;
- проверять положительную сумму;
- проверять совпадение валюты;
- не допускать отрицательный баланс;
- сохранять идемпотентность по `operation_id`.

### Order Service

Основные таблицы:

- `products`
- `orders`
- `order_items`
- `stock_reservations`

Статусы заказа:

- `pending`
- `stock_reserved`
- `payment_reserved`
- `completed`
- `cancelled`
- `failed`

Возможности заказа:

- создать заказ;
- зафиксировать цену товара на момент создания заказа;
- транзакционно зарезервировать склад;
- применить складской резерв после успешной оплаты;
- снять резерв при отмене;
- вернуть уже примененный склад при отмене завершенного заказа;
- отклонить резерв, если доступного товара недостаточно.

### Saga Orchestrator

Основные таблицы:

- `saga_instances`
- `saga_steps`

Команда:

```bash
php artisan saga:consume-streams
```

Worker читает `order.created` и координирует:

- резерв склада;
- списание денег;
- завершение заказа;
- генерацию чека;
- компенсации при ошибке.

### Report Service

Основная таблица:

- `reports`

Возможности отчетов:

- создать чек по успешному заказу;
- создать отчет об отмене;
- получить метаданные отчета по `order_id`;
- скачать файл отчета.

Текущая реализация создает простой скачиваемый `.pdf` файл без тяжелой PDF-инфраструктуры. Это сохраняет проект легким, но оставляет полный flow генерации и скачивания отчета.

## Redis Streams

В flow используются streams:

- `order.created`
- `stock.reserved`
- `stock.reservation.failed`
- `payment.reserved`
- `payment.failed`
- `order.completed`
- `order.cancelled`
- `report.generated`

Orchestrator читает `order.created` через consumer group:

- group: `saga-orchestrator`
- consumer по умолчанию: `saga-orchestrator-1`

## Public API

Все публичные запросы идут через:

```text
http://localhost:8080/api/*
```

Авторизация:

```http
Authorization: Bearer <JWT>
```

Основные routes:

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/users/me`
- `GET /api/shop/products`
- `POST /api/shop/orders`
- `GET /api/shop/orders/{id}`
- `POST /api/shop/orders/{id}/cancel`
- `GET /api/shop/reports/{orderId}`
- `GET /api/shop/reports/{orderId}/download`
- `POST /api/shop/wallet/top-up`
- `GET /api/shop/wallet`

Тело создания заказа:

```json
{
  "idempotency_key": "order-uuid-1",
  "items": [
    {
      "product_id": 1,
      "quantity": 2
    }
  ]
}
```

Тело пополнения кошелька:

```json
{
  "amount": "2000.00",
  "currency": "USD",
  "operation_id": "topup-1"
}
```

## Локальный Запуск

Собрать и запустить сервисы:

```bash
docker compose up -d --build
```

Выполнить migrations и seeders:

```bash
docker compose exec user-service php artisan migrate --seed
docker compose exec order-service php artisan migrate --seed
docker compose exec report-service php artisan migrate
docker compose exec saga-orchestrator php artisan migrate
```

Saga worker уже настроен в Docker Compose:

```bash
docker compose logs -f saga-orchestrator-worker
```

Если нужно запустить вручную:

```bash
docker compose exec saga-orchestrator php artisan saga:consume-streams
```

## Seed Data

`order` service создает товары:

- Laptop - `1000.00 USD`, stock `5`
- Phone - `500.00 USD`, stock `10`
- Headphones - `100.00 USD`, stock `20`

`user` service создает тестового пользователя и кошелек для разработки.

## Curl-Сценарий

Задать base URL:

```bash
BASE=http://localhost:8080/api
```

Регистрация:

```bash
curl -s -X POST "$BASE/auth/register" \
  -H "Content-Type: application/json" \
  -d '{"name":"Buyer","email":"buyer@example.com","password":"password123"}'
```

Login:

```bash
TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"buyer@example.com","password":"password123"}' | jq -r .access_token)
```

Пополнить кошелек:

```bash
curl -s -X POST "$BASE/shop/wallet/top-up" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount":"2000.00","currency":"USD","operation_id":"topup-1"}'
```

Посмотреть кошелек:

```bash
curl -s "$BASE/shop/wallet" \
  -H "Authorization: Bearer $TOKEN"
```

Посмотреть товары:

```bash
curl -s "$BASE/shop/products"
```

Создать заказ:

```bash
curl -s -X POST "$BASE/shop/orders" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"idempotency_key":"order-uuid-1","items":[{"product_id":1,"quantity":1}]}'
```

Проверить заказ:

```bash
curl -s "$BASE/shop/orders/1" \
  -H "Authorization: Bearer $TOKEN"
```

Проверить кошелек после обработки saga:

```bash
curl -s "$BASE/shop/wallet" \
  -H "Authorization: Bearer $TOKEN"
```

Проверить склад:

```bash
curl -s "$BASE/shop/products"
```

Получить метаданные отчета:

```bash
curl -s "$BASE/shop/reports/1" \
  -H "Authorization: Bearer $TOKEN"
```

Скачать отчет:

```bash
curl -L "$BASE/shop/reports/1/download" \
  -H "Authorization: Bearer $TOKEN" \
  -o order-1.pdf
```

Отменить заказ:

```bash
curl -s -X POST "$BASE/shop/orders/1/cancel" \
  -H "Authorization: Bearer $TOKEN"
```

Проверить компенсации:

```bash
curl -s "$BASE/shop/orders/1" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/shop/wallet" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/shop/products"
curl -s "$BASE/shop/reports/1" -H "Authorization: Bearer $TOKEN"
```

## Тесты

Запуск тестов по сервисам:

```bash
docker compose exec user-service php artisan test
docker compose exec order-service php artisan test
docker compose exec report-service php artisan test
docker compose exec saga-orchestrator php artisan test
docker compose exec api-gateway php artisan test
```

Покрытые области:

- идемпотентность кошелька и недостаток средств;
- идемпотентность резерва склада;
- идемпотентность генерации отчетов;
- purchase flow в saga;
- shop proxy routes в API Gateway.

## Security Notes

- `user` выпускает RS256 JWT tokens.
- `order`, `report` и `saga-orchestrator` проверяют JWT по публичному ключу.
- Приватный ключ нельзя коммитить.
- `user/storage/keys/jwt_private.pem` добавлен в `.gitignore`.
- Для реального окружения локальные ключи нужно заменить на secrets, подключенные через инфраструктуру или secret manager.

## Текущий Scope

Проект намеренно не использует Kubernetes, broker clusters, тяжелую PDF-генерацию и внешние платежные провайдеры.

Фокус проекта:

- Laravel microservices;
- API Gateway;
- JWT authentication;
- отдельная БД на сервис;
- Redis Streams;
- Saga orchestration;
- идемпотентность;
- компенсационные действия;
- границы транзакций;
- eventual consistency.

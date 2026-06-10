Production-style distributed backend system demonstrating event-driven architecture, microservices communication, and resilient order processing under high concurrency with guarantees for consistency, idempotency, and fault tolerance

Распределенная бэкенд-система, демонстрирующая event-driven архитектуру, взаимодействие микросервисов и отказоустойчивую обработку заказов в условиях высокой конкурентности с гарантиями согласованности, идемпотентности и отказоустойчивости.

## Docker Infrastructure

Docker Compose архитектура для локальной разработки описана в `docs/docker-architecture.md`.

Основные компоненты:

- `api-gateway` как публичный Laravel BFF/proxy слой.
- `user`, `order`, `report`, `saga-orchestrator` как отдельные Laravel PHP-FPM приложения.
- Единый Nginx с публичным listener-ом и внутренним router listener-ом.
- Изолированные PostgreSQL сети и отдельная база для каждого доменного сервиса.
- Redis в `app-network` для Redis Streams событий `user.created`, `order.created`, `report.generated`.

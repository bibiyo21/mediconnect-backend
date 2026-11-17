# mediconnect-backend

Laravel 13 modular monolith for MediConnect: REST API (`/api/v1`), queue workers, scheduler and Reverb.

Run everything through the containers from `mediconnect-infrastructure` (checked out next to this repository). Never use host PHP.

```bash
docker compose run --rm api composer test      # Pest (real PostgreSQL test database)
docker compose run --rm api composer analyse   # Larastan level 8
docker compose run --rm api composer lint      # Pint
docker compose run --rm api composer migrate   # migrations as the schema owner role
```

Modules live in `app/Modules` (boundaries enforced by `tests/Architecture`). Specification: [mediconnect-docs](https://github.com/bibiyo21/mediconnect-docs).

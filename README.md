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

## Real-time (Reverb)

Laravel Reverb is installed; it is off by default (`BROADCAST_CONNECTION=null`, and `POST /api/v1/broadcasting/auth` answers 503). To run it locally, set in `.env`:

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=<random string>
REVERB_APP_SECRET=<random string>
REVERB_HOST=reverb      # compose service name, used by the API and workers to publish
REVERB_PORT=8080
REVERB_SCHEME=http
```

then `docker compose --profile realtime up -d reverb` from `mediconnect-infrastructure` and restart `api` and `worker`. Clients connect to `ws://localhost:8080` and authorize presence channels (`presence-user.{id}` and so on; `private-` names are refused) through `POST /api/v1/broadcasting/auth` (cookie or bearer). Only presence channels exist, so Reverb can tie each connection to a user and terminate it when their authorization ends; see `routes/channels.php`. Clinic channels are for clinic members only (global roles read clinic data through the audited REST API).

Reverb notes:
- `REVERB_ALLOWED_ORIGINS` lists host names (default `localhost,127.0.0.1`). `*` is refused when `APP_ENV=production` with the reverb driver. A client that sends no Origin header (some native stacks) is refused by a strict list: have the proxy or the app send the first-party origin rather than loosening the list.
- Rate limiting is on by default (60 messages per 60 s per connection) and `REVERB_APP_MAX_CONNECTIONS` defaults to 1000.
- Reverb's HTTP API (`/apps/{id}/events`, `/channels`, `/connections`) is for the backend only. Publish only the WebSocket path (`/app/{key}`) through the proxy, and keep the HTTP API on the internal network.


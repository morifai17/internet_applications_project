# NovaCart API

Backend for the NovaCart store: a versioned REST API (Laravel 13, PHP 8.5, PostgreSQL) with
four modules: **users**, **products**, **orders** and **notifications**, plus a separate
**inventory service** (Node.js, gRPC) that owns product stock.

## Project layout

```
internet-applications-project/
├── laravel-app/          Laravel API: users, products, orders, notifications (PHP)
├── nodejs-inventory/     inventory service: owns the stock, serves it over gRPC (Node.js)
├── proto/                the gRPC contract shared by both services
├── docs/                 Postman collections and the race-condition demo
└── docker-compose.yml    runs everything together
```

## Run with Docker

```bash
docker compose up -d --build
```

The API is then served at `http://localhost:8080/api/v1` (`GET /up` is the health check).
The first build compiles the PHP `grpc` extension and takes several minutes; later builds are cached.
On start the container migrates and seeds the database (categories, sample products and an
administrator).

| Variable | Default | Purpose |
|---|---|---|
| `APP_PORT` | `8080` | Host port |
| `APP_URL` | `http://localhost:8080` | Base URL used for image links; keep it in sync with `APP_PORT` |
| `ADMIN_EMAIL` | `admin@example.com` | Seeded administrator |
| `ADMIN_PASSWORD` | *(empty)* | When empty, a random password is generated and printed once: `docker compose logs app` |
| `JWT_TTL` | `60` | Token lifetime in minutes |
| `CORS_ALLOWED_ORIGINS` | `*` | Comma-separated list of allowed origins |
| `INVENTORY_TIMEOUT_MS` | `2000` | Deadline of every gRPC call to the inventory service |
| `INVENTORY_ARTIFICIAL_DELAY_MS` | `0` | Makes the inventory service slow, for the failure demo |

`APP_KEY` and `JWT_SECRET` are generated on first start and kept on the `storage` volume.

```bash
APP_PORT=8091 APP_URL=http://localhost:8091 ADMIN_PASSWORD='S3cure-Admin!' docker compose up -d --build
```

## Try the API

Import [`docs/api/NovaCart.postman_collection.json`](docs/api/NovaCart.postman_collection.json)
into Postman (set `baseUrl` and `adminPassword`), or run it from the command line:

```bash
npx newman run docs/api/NovaCart.postman_collection.json \
  --env-var baseUrl=http://localhost:8080/api/v1 --env-var adminPassword='...'
```

The **Security proofs** folder checks that every unauthorized request is rejected.

## Authentication and roles

The application has one route set, under `/api/v1`, and every client (SPA, mobile app, Postman)
authenticates the same way: with a JWT. There are no session or cookie based routes.

- `POST /register` and `POST /login` return a JWT: `{"token", "token_type": "bearer", "expires_in": 3600}`.
  Send it as `Authorization: Bearer <token>`.
- Tokens expire after `JWT_TTL` minutes. `POST /refresh` exchanges a token for a new one within
  two weeks; `POST /logout` blacklists it.
- **Customer:** browses products, manages their cart, places orders, and sees only their own
  orders and notifications.
- **Admin:** manages products and stock, sees every order, and is the only role that can change
  an order's status.

## Error format

Every error, from validation to authorization to server failures, has the same shape:

```json
{"status": 422, "code": "validation_failed", "message": "...", "errors": {"email": ["..."]}}
```

`errors` is present only for validation failures. Stack traces are never returned.

| Status | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | Missing, invalid, expired or revoked token |
| 403 | `forbidden` | Role or ownership does not allow the action |
| 404 | `not_found` | Unknown resource, or a notification that belongs to someone else |
| 409 | `conflict` | Not enough stock; product still in a pending order; category still has products |
| 422 | `validation_failed` / `unprocessable` | Invalid input / business rule (empty cart, invalid status change) |
| 429 | `too_many_requests` | Rate limit exceeded |
| 503 | `service_unavailable` | Inventory service stopped or too slow (sent with `Retry-After`) |

## Endpoints (`/api/v1`)

| Method | Path | Access |
|---|---|---|
| POST | `/register`, `/login`, `/refresh` | Public (6 requests/minute per email and IP) |
| GET | `/me` · POST `/logout` | Signed in |
| GET | `/?category=&q=&page=` | Public: product list, filter, search, pagination |
| GET | `/products/{id}` | Public |
| GET, POST, PATCH, DELETE | `/cart`, `/cart/{product}` | Signed in |
| GET | `/checkout` | Signed in: order preview |
| GET, POST | `/orders` | Signed in: own orders / create from cart |
| GET | `/orders/{id}` | Owner or admin |
| GET | `/notifications?unread=1` | Signed in: own notifications |
| PATCH | `/notifications/{id}/read` · POST `/notifications/read-all` | Signed in |
| GET | `/admin/dashboard`, `/admin/orders` | Admin |
| GET, POST, PUT, PATCH, DELETE | `/admin/products…` (multipart `image` on create/update) | Admin |
| GET, POST, PUT, DELETE | `/admin/categories…` (`{"name": "..."}`) | Admin |
| PATCH | `/admin/orders/{id}/status` | Admin |

Notifications are stored in the database: the customer is told when an order is placed and
when its status changes, and administrators are told about new orders.

## Security

- JWT authentication with expiry, refresh and a revocation blacklist.
- Authorization through role middleware and an ownership policy for orders.
- Security headers on every response: `Content-Security-Policy`, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS over HTTPS.
- Rate limiting: 60 requests/minute per user or IP; sign-in endpoints 6/minute per email and IP.
- Restricted CORS methods and headers; configurable origins.
- Prices are always recomputed on the server; stock is reserved atomically by the inventory service.
- Production container runs with `APP_DEBUG=false`.

## Inventory service (requirement 2)

Stock is owned by a separate service written in another language, with its own database. The
application never reads that database; it only calls the service through one shared contract.

```
 Laravel API (PHP) ──── gRPC ────► inventory service (Node.js)
        │          proto/inventory/v1/inventory.proto      │
        ▼                                                  ▼
   app database (PostgreSQL)                  inventory database (PostgreSQL)
```

| Path | What it is |
|---|---|
| `proto/inventory/v1/inventory.proto` | The contract: `GetStock`, `Reserve`, `Release`, and `SetStock` for administrators |
| `nodejs-inventory/` | The Node.js service (`@grpc/grpc-js`, `pg`) and its tests |
| `laravel-app/app/Modules/Inventory/` | The PHP side: `InventoryGateway` interface, gRPC client, generated message classes |

**Flows**

- *Product details and lists:* one `GetStock` call per page; the stock is returned with the
  products. If the service cannot answer, products are still shown with `stock_quantity: null`
  and `stock_status: "unknown"`.
- *Creating an order:* one `Reserve` call for every line of the cart, chosen with a UUID
  reservation id. The service reserves all lines or none. Approved: the order is saved with its
  reservation id. Rejected: no order, `409` with a clear message.
- *Cancelling an order:* `Release` of that order's reservation.

**Failure cases**

| Case | Behaviour |
|---|---|
| Not enough stock | `409`, e.g. `Only 1 unit(s) of "Keyboard" are available; you requested 3.` Nothing is reserved. |
| Service stopped or slow | Every call has a deadline (`INVENTORY_TIMEOUT_MS`, 2 s). The user gets `503 service_unavailable` with `Retry-After: 5` instead of waiting; the cart is kept. |
| Saving the order fails after the reservation | The reservation is released again, so no unit is lost. |
| A `Reserve` times out but succeeded on the service | The application releases the same reservation id. `Release` is idempotent and leaves a marker, so a late `Reserve` with that id can no longer take stock. |

**Race condition.** The service locks the stock rows inside one transaction
(`SELECT … FOR UPDATE`, in product order to avoid deadlocks) and the table has
`CHECK (quantity >= 0)`. When many orders want the last unit at once, exactly one gets it.

**Demos**

```bash
# Many customers order the last unit at the same moment: one 201, the rest 409.
ADMIN_PASSWORD='...' node docs/demo/race-condition.mjs

# Service stopped / slow: follow docs/api/NovaCart-inventory-outage.postman_collection.json
docker compose stop inventory        # then: docker compose start inventory
INVENTORY_ARTIFICIAL_DELAY_MS=5000 docker compose up -d inventory   # slow variant

# The inventory service's own tests (incl. the race tests), inside the running stack:
docker compose exec inventory npm test
docker compose logs -f inventory     # one line per gRPC call
```

After changing the contract, regenerate the PHP classes with
`sh nodejs-inventory/scripts/generate-php.sh` (the Node.js side loads the `.proto` at runtime).

## Tests

```bash
cd laravel-app && php artisan test
```

The Laravel tests use an in-memory stand-in for the inventory service
(`laravel-app/tests/Fakes/FakeInventory.php`), so they need neither the service nor the `grpc` extension.
The inventory service is tested over real gRPC against PostgreSQL:

```bash
cd nodejs-inventory && npm install
TEST_DATABASE_URL=postgres://user:pass@localhost:5432/anydb npm test
```

The tests work in a throw-away schema and never touch existing data.

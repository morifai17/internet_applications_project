# NovaCart API

Backend for the NovaCart store: a REST API (Laravel 13, PHP 8.5, PostgreSQL) with
four modules: **users**, **products**, **orders** and **notifications**.

## Run with Docker

```bash
docker compose up -d --build
```

The API is then served at `http://localhost:8080/api` (`GET /up` is the health check).
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

`APP_KEY` and `JWT_SECRET` are generated on first start and kept on the `storage` volume.

```bash
APP_PORT=8091 APP_URL=http://localhost:8091 ADMIN_PASSWORD='S3cure-Admin!' docker compose up -d --build
```

## Try the API

Import [`docs/api/NovaCart.postman_collection.json`](docs/api/NovaCart.postman_collection.json)
into Postman (set `baseUrl` and `adminPassword`), or run it from the command line:

```bash
npx newman run docs/api/NovaCart.postman_collection.json \
  --env-var baseUrl=http://localhost:8080/api --env-var adminPassword='...'
```

The **Security proofs** folder checks that every unauthorized request is rejected.

## Authentication and roles

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
| 409 | `conflict` | Not enough stock; product still referenced by orders |
| 422 | `validation_failed` / `unprocessable` | Invalid input / business rule (empty cart, invalid status change) |
| 429 | `too_many_requests` | Rate limit exceeded |

## Endpoints (`/api`)

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
- Prices and stock are always recomputed on the server inside a locked transaction.
- Production container runs with `APP_DEBUG=false`.

## Tests

```bash
php artisan test
```

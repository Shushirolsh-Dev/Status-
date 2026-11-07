# Status

A self-hosted status page for products, APIs, and online services. Built with **PHP 8.3, SQLite, PDO, HTML, and CSS** — no framework, Node.js, build step, or JavaScript dependency.

Status gives teams one clear place to communicate service health, publish incidents, and keep customers informed.

## Features

- Public status page with an overall service summary.
- Service directory with operational, degraded, partial outage, and major outage states.
- Incident timeline with investigation, identified, monitoring, and resolved states.
- Maintenance announcements.
- Private admin area with service management and incident publishing.
- JSON status endpoint at `/api/status`.
- SQLite persistence with automatic schema setup and seeded example services.
- CSRF protection, password hashing verification, session hardening, prepared SQL statements, and security headers.
- Responsive interface, accessible labels, and reduced-motion support.
- Docker image and Render Blueprint.

## Requirements

- PHP 8.3+
- PDO SQLite extension
- A web server (Apache via the included Docker image, or PHP's built-in server)

## Quick start

```bash
git clone https://github.com/Shushirolsh-Dev/Status-.git
cd Status-
cp .env.example .env
```

Set an admin password and database path in your environment. The app reads environment variables directly; the `.env.example` file is a reference, not an automatically loaded dotenv file.

For a local development server:

```bash
export STATUS_ADMIN_PASSWORD='change-this-to-a-long-random-password'
export STATUS_DB_PATH="$(pwd)/storage/status.sqlite"
php -S 127.0.0.1:8000 -t public public/router.php
```

Open `http://127.0.0.1:8000`. Visit `/admin` to sign in.

> The admin password must be configured before deployment. There is no public registration or default password.

## Docker

```bash
docker build -t status .
docker run --rm -p 8080:80 \
  -e STATUS_ADMIN_PASSWORD='use-a-long-random-password' \
  -e STATUS_DB_PATH=/var/www/html/storage/status.sqlite \
  -v status-data:/var/www/html/storage \
  status
```

Open `http://localhost:8080`.

## Configuration

| Variable | Required | Description |
| --- | --- | --- |
| `STATUS_ADMIN_PASSWORD` | Yes | Password for the private admin account. |
| `STATUS_DB_PATH` | No | SQLite database file. Defaults to `storage/status.sqlite`. |
| `STATUS_TIMEZONE` | No | PHP timezone. Defaults to `UTC`. |
| `STATUS_APP_NAME` | No | Product name shown in the page header. Defaults to `Status`. |

Use a persistent disk for the SQLite directory in production. Back up the database regularly.

## Admin workflow

1. Open `/admin` and sign in with `STATUS_ADMIN_PASSWORD`.
2. Add services and set their current state.
3. Publish an incident when something goes wrong.
4. Update its status as the team investigates.
5. Resolve it when service is restored.

Incident states: `investigating`, `identified`, `monitoring`, and `resolved`.

Service states: `operational`, `degraded`, `partial_outage`, and `major_outage`.

## JSON API

`GET /api/status` returns the current overall state, services, and recent incidents.

Example response:

```json
{
  "name": "Status",
  "status": "operational",
  "updated_at": "2026-01-01T12:00:00+00:00",
  "services": [],
  "incidents": []
}
```

The endpoint is public and read-only. Admin changes are performed through the authenticated web dashboard.

## Project structure

```
public/
  index.php       Front controller and rendered pages
  router.php      PHP built-in server routing
  .htaccess       Apache rewrite and security rules
  assets/
    styles.css
    favicon.svg
src/
  app.php         Database, sessions, helpers, and shared logic
storage/
  .gitkeep
Dockerfile
render.yaml
.env.example
```

## Security notes

- Set a strong admin password through the environment; never commit secrets.
- Serve the application over HTTPS in production.
- Keep the SQLite database on a persistent, non-public disk.
- The application uses prepared statements, output escaping, CSRF tokens, and secure session cookie settings.
- This starter intentionally uses a single environment-configured administrator. Add role management only if your deployment needs it.

## Development

Run PHP's built-in server using the command above. Check syntax with:

```bash
php -l src/app.php
php -l public/index.php
php -l public/router.php
```

## License

MIT. See [LICENSE](LICENSE).

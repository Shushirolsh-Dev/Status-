# Contributing

Thanks for considering a contribution.

## Local setup

1. Install PHP 8.3+ with PDO SQLite.
2. Configure `STATUS_ADMIN_PASSWORD`.
3. Start the app with `php -S 127.0.0.1:8000 -t public public/router.php`.

## Guidelines

- Keep the runtime PHP-only; do not add a JavaScript or Node build dependency.
- Prefer small, focused changes and prepared SQL statements.
- Escape all user-controlled output.
- Include clear reproduction steps for bug reports.
- Run `php -l` on changed PHP files before opening a pull request.
- Update the README when configuration or behavior changes.

## Pull requests

Describe the problem, the solution, and how you tested it. Include screenshots for meaningful UI changes.

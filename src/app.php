<?php
declare(strict_types=1);

date_default_timezone_set(getenv('STATUS_TIMEZONE') ?: 'UTC');

function config(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function app_name(): string {
    return config('STATUS_APP_NAME', 'Status') ?? 'Status';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $path = config('STATUS_DB_PATH', dirname(__DIR__) . '/storage/status.sqlite');
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the database directory.');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'operational',
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'investigating',
        impact TEXT NOT NULL DEFAULT 'minor',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        resolved_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
        body TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at TEXT NOT NULL
    )");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
    if ($count === 0) {
        $insert = $pdo->prepare('INSERT INTO services (name, description, status, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $now = date(DATE_ATOM);
        foreach ([
            ['Website', 'Main website and customer dashboard', 'operational', 1],
            ['API', 'Public API and developer endpoints', 'operational', 2],
            ['Payments', 'Payment processing and transactions', 'operational', 3],
            ['Notifications', 'Email and product notifications', 'operational', 4],
        ] as $service) {
            $insert->execute([...$service, $now, $now]);
        }
    }
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string { return date(DATE_ATOM); }

function status_label(string $status): string {
    return match ($status) {
        'operational' => 'Operational',
        'degraded' => 'Degraded performance',
        'partial_outage' => 'Partial outage',
        'major_outage' => 'Major outage',
        'investigating' => 'Investigating',
        'identified' => 'Identified',
        'monitoring' => 'Monitoring',
        'resolved' => 'Resolved',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function overall_status(array $services): string {
    $statuses = array_column($services, 'status');
    foreach (['major_outage', 'partial_outage', 'degraded'] as $state) {
        if (in_array($state, $statuses, true)) return $state;
    }
    return 'operational';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

function is_admin(): bool { return !empty($_SESSION['is_admin']); }

function require_admin(): void {
    if (!is_admin()) {
        header('Location: /admin');
        exit;
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array {
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function redirect(string $path): never {
    header('Location: ' . $path, true, 303);
    exit;
}

function services(): array {
    return db()->query('SELECT * FROM services ORDER BY position ASC, id ASC')->fetchAll();
}

function recent_incidents(int $limit = 8): array {
    $statement = db()->prepare('SELECT * FROM incidents ORDER BY created_at DESC LIMIT :limit');
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function record_incident_update(int $id, string $body, string $status): void {
    $statement = db()->prepare('INSERT INTO incident_updates (incident_id, body, status, created_at) VALUES (?, ?, ?, ?)');
    $statement->execute([$id, $body, $status, now()]);
}

function start_app_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('status_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
}

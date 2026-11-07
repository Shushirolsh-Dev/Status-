<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/app.php';
start_app_session();
security_headers();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();

if ($path === '/api/status') {
    header('Content-Type: application/json; charset=utf-8');
    $items = services();
    $incidents = recent_incidents(10);
    echo json_encode([
        'name' => app_name(),
        'status' => overall_status($items),
        'updated_at' => now(),
        'services' => array_map(static fn($s) => [
            'id' => (int)$s['id'], 'name' => $s['name'], 'description' => $s['description'],
            'status' => $s['status'], 'status_label' => status_label($s['status']),
        ], $items),
        'incidents' => array_map(static fn($i) => [
            'id' => (int)$i['id'], 'title' => $i['title'], 'body' => $i['body'],
            'status' => $i['status'], 'impact' => $i['impact'],
            'created_at' => $i['created_at'], 'updated_at' => $i['updated_at'],
            'resolved_at' => $i['resolved_at'],
        ], $incidents),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $password = (string)($_POST['password'] ?? '');
        $configured = config('STATUS_ADMIN_PASSWORD');
        if (!$configured) {
            flash('Admin access is disabled. Configure STATUS_ADMIN_PASSWORD first.', 'error');
            redirect('/admin');
        }
        if (hash_equals($configured, $password)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            flash('You are signed in.');
            redirect('/admin');
        }
        usleep(250000);
        flash('That password did not match.', 'error');
        redirect('/admin');
    }

    require_admin();

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('You have signed out.');
        redirect('/admin');
    }

    if ($action === 'add_service') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($name === '' || strlen($name) > 80 || strlen($description) > 180) {
            flash('Enter a service name (max 80 characters) and description (max 180).', 'error');
        } else {
            $stmt = $pdo->prepare('INSERT INTO services (name, description, status, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $description, 'operational', (int)$pdo->query('SELECT COALESCE(MAX(position),0)+1 FROM services')->fetchColumn(), now(), now()]);
            flash('Service added.');
        }
        redirect('/admin');
    }

    if ($action === 'service_status') {
        $id = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($_POST['status'] ?? '');
        if ($id && in_array($status, ['operational','degraded','partial_outage','major_outage'], true)) {
            $stmt = $pdo->prepare('UPDATE services SET status = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$status, now(), $id]);
            flash('Service status updated.');
        } else flash('Invalid service update.', 'error');
        redirect('/admin');
    }

    if ($action === 'delete_service') {
        $id = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare('DELETE FROM services WHERE id = ?');
            $stmt->execute([$id]);
            flash('Service removed.');
        }
        redirect('/admin');
    }

    if ($action === 'create_incident') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $impact = (string)($_POST['impact'] ?? 'minor');
        if ($title === '' || $body === '' || strlen($title) > 120 || strlen($body) > 2000 || !in_array($impact, ['minor','major','critical','maintenance'], true)) {
            flash('Add a valid title, update, and impact level.', 'error');
        } else {
            $stmt = $pdo->prepare('INSERT INTO incidents (title, body, status, impact, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $body, 'investigating', $impact, now(), now()]);
            $id = (int)$pdo->lastInsertId();
            flash('Incident published to the status page.');
        }
        redirect('/admin');
    }

    if ($action === 'update_incident') {
        $id = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($_POST['status'] ?? '');
        $body = trim((string)($_POST['body'] ?? ''));
        if ($id && in_array($status, ['investigating','identified','monitoring','resolved'], true) && $body !== '' && strlen($body) <= 2000) {
            $stmt = $pdo->prepare('UPDATE incidents SET status = ?, updated_at = ?, resolved_at = ? WHERE id = ?');
            $stmt->execute([$status, now(), $status === 'resolved' ? now() : null, $id]);
            record_incident_update($id, $body, $status);
            flash('Incident update published.');
        } else flash('Check the incident update and try again.', 'error');
        redirect('/admin');
    }

    flash('Unknown action.', 'error');
    redirect('/admin');
}

$flash = take_flash();
$items = services();
$overall = overall_status($items);
$incidents = recent_incidents(12);
$appName = app_name();
$activeIncidents = array_values(array_filter($incidents, static fn($i) => $i['status'] !== 'resolved'));

function page_start(string $title, bool $admin = false): void {
    $name = e(app_name());
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f9f6"><title>' . e($title) . ' · ' . $name . '</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/styles.css"></head><body><header class="topbar"><div class="wrap nav"><a class="brand" href="/"><span class="brand-mark">✓</span>' . $name . '</a><a class="navlink" href="' . ($admin ? '/' : '/admin') . '">' . ($admin ? '← Public status page' : 'System information') . '</a></div></header>';
}
function page_end(): void {
    echo '<footer class="wrap footer"><span>Powered by <strong>Status</strong></span><span>Clear updates. Better trust.</span></footer></body></html>';
}
function show_flash(?array $flash): void {
    if ($flash) echo '<div class="notice ' . ($flash['type'] === 'error' ? 'error' : '') . '">' . e($flash['message']) . '</div>';
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }

if ($path === '/admin') {
    page_start('Admin', true);
    if (!is_admin()) {
        echo '<main class="wrap login"><div class="eyebrow">Private workspace</div><h1>Welcome back.</h1><p class="muted">Sign in to manage your services and publish updates.</p><section class="panel">';
        show_flash($flash);
        if (!config('STATUS_ADMIN_PASSWORD')) echo '<div class="notice error">Admin login is disabled. Set STATUS_ADMIN_PASSWORD in your environment.</div>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="login"><div class="field"><label for="password">Admin password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="btn" type="submit">Sign in →</button></form></section></main>';
        page_end(); exit;
    }

    echo '<main class="wrap"><section class="admin-hero"><div class="eyebrow">Control room</div><h1>Manage your status.</h1><p class="muted">Keep customers informed with accurate service health and incident updates.</p>';
    show_flash($flash);
    echo '<form method="post" class="btn-row">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="btn secondary" type="submit">Sign out</button></form></section>';
    echo '<div class="admin-grid"><section class="panel"><h2>Add a service</h2><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="add_service"><div class="field"><label for="name">Service name</label><input id="name" name="name" maxlength="80" placeholder="e.g. Customer dashboard" required></div><div class="field"><label for="description">Description</label><input id="description" name="description" maxlength="180" placeholder="What this service does"></div><button class="btn" type="submit">Add service +</button></form></section>';
    echo '<section class="panel"><h2>Publish an incident</h2><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create_incident"><div class="field"><label for="title">Incident title</label><input id="title" name="title" maxlength="120" placeholder="e.g. Elevated API errors" required></div><div class="field"><label for="impact">Impact</label><select id="impact" name="impact"><option value="minor">Minor</option><option value="major">Major</option><option value="critical">Critical</option><option value="maintenance">Maintenance</option></select></div><div class="field"><label for="body">Public update</label><textarea id="body" name="body" maxlength="2000" placeholder="What is happening? What are you doing about it?" required></textarea></div><button class="btn" type="submit">Publish incident ↗</button></form></section></div>';

    echo '<section class="panel"><h2>Services <span class="mono">· ' . count($items) . ' total</span></h2>';
    foreach ($items as $service) {
        echo '<div class="service-admin"><div><strong>' . e($service['name']) . '</strong><small>' . e($service['description']) . '</small></div><div class="btn-row"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="service_status"><input type="hidden" name="service_id" value="' . (int)$service['id'] . '"><select name="status" aria-label="Status for ' . e($service['name']) . '" class="field"><option value="operational"' . ($service['status']==='operational'?' selected':'') . '>Operational</option><option value="degraded"' . ($service['status']==='degraded'?' selected':'') . '>Degraded</option><option value="partial_outage"' . ($service['status']==='partial_outage'?' selected':'') . '>Partial outage</option><option value="major_outage"' . ($service['status']==='major_outage'?' selected':'') . '>Major outage</option></select><button class="btn secondary" type="submit">Save</button></form><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="delete_service"><input type="hidden" name="service_id" value="' . (int)$service['id'] . '"><button class="btn danger" type="submit">Remove</button></form></div></div>';
    }
    if (!$items) echo '<p class="muted">No services yet. Add your first service above.</p>';
    echo '</section>';

    echo '<section class="panel"><h2>Incident history</h2>';
    foreach ($incidents as $incident) {
        echo '<div class="incident-admin"><h3>' . e($incident['title']) . ' <span class="pill ' . e($incident['status']) . '">' . e(status_label($incident['status'])) . '</span></h3><p>' . e($incident['body']) . '</p>';
        if ($incident['status'] !== 'resolved') {
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="update_incident"><input type="hidden" name="incident_id" value="' . (int)$incident['id'] . '"><div class="field"><label>New public update</label><textarea name="body" maxlength="2000" placeholder="Share the latest progress..." required></textarea></div><div class="btn-row"><select name="status" aria-label="Incident status"><option value="investigating">Investigating</option><option value="identified">Identified</option><option value="monitoring">Monitoring</option><option value="resolved">Resolved</option></select><button class="btn" type="submit">Post update</button></div></form>';
        }
        echo '</div>';
    }
    if (!$incidents) echo '<p class="muted">No incidents have been published.</p>';
    echo '</section></main>';
    page_end(); exit;
}

page_start('System status');
echo '<main class="wrap"><section class="hero"><div class="eyebrow">Live system report</div><h1>All systems,<br>clearly reported.</h1><p>A transparent view of service health and incident history. We will keep this page updated as things change.</p></section>';
$summaryText = $overall === 'operational' ? 'All systems operational' : status_label($overall);
echo '<section class="summary"><span class="summary-dot" style="background:' . ($overall==='operational'?'#39a66e':($overall==='degraded'?'#d89a35':'#c84e4e')) . '"></span><div><strong>' . e($summaryText) . '</strong><small>' . ($overall==='operational'?'All monitored services are running smoothly.':'Some services are experiencing issues.') . '</small></div><time>UPDATED ' . e(date('H:i') . ' ' . date('T')) . '</time></section>';
echo '<div class="section-head"><h2>Services</h2><span>' . count($items) . ' components</span></div><section class="service-list">';
foreach ($items as $service) {
    echo '<div class="service"><span class="dot" style="background:' . ($service['status']==='operational'?'#39a66e':($service['status']==='degraded'?'#d89a35':'#c84e4e')) . '"></span><div class="service-info"><div class="service-name">' . e($service['name']) . '</div><div class="service-desc">' . e($service['description']) . '</div></div><span class="pill ' . e($service['status']) . '">' . e(status_label($service['status'])) . '</span></div>';
}
if (!$items) echo '<div class="empty">No services have been added yet.</div>';
echo '</section>';

echo '<div class="section-head"><h2>Incident history</h2><span>Recent updates</span></div>';
if (!$incidents) echo '<div class="empty"><strong>No incidents reported.</strong><br>Everything is looking good. Check back here for future updates.</div>';
foreach ($incidents as $incident) {
    $updates = $pdo->prepare('SELECT * FROM incident_updates WHERE incident_id = ? ORDER BY created_at ASC');
    $updates->execute([(int)$incident['id']]);
    echo '<article class="incident"><div class="incident-top"><div><h3>' . e($incident['title']) . '</h3><time>' . e(date('M j, Y · H:i', strtotime($incident['created_at']))) . '</time></div><span class="pill ' . e($incident['status']) . '">' . e(status_label($incident['status'])) . '</span></div><p>' . nl2br(e($incident['body'])) . '</p>';
    foreach ($updates->fetchAll() as $update) {
        echo '<div class="update"><strong>' . e(status_label($update['status'])) . '</strong> · ' . e(date('M j, H:i', strtotime($update['created_at']))) . '<br>' . nl2br(e($update['body'])) . '</div>';
    }
    echo '</article>';
}
echo '<div class="section-head"><h2>About this page</h2></div><p class="muted" style="font-size:13px;max-width:650px">This page reflects the latest information shared by our operations team. For automated checks, connect your monitoring system to your own workflow and publish confirmed updates here.</p></main>';
page_end();

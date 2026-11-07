<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/app.php';

$cases = [
    [status_label('operational') === 'Operational', 'Operational label'],
    [status_label('partial_outage') === 'Partial outage', 'Partial outage label'],
    [overall_status([['status' => 'operational'], ['status' => 'degraded']]) === 'degraded', 'Degraded overall status'],
    [overall_status([['status' => 'major_outage'], ['status' => 'degraded']]) === 'major_outage', 'Major outage takes priority'],
    [overall_status([]) === 'operational', 'Empty service list defaults to operational'],
    [e('<script>') === '&lt;script&gt;', 'HTML escaping'],
];

$failed = 0;
foreach ($cases as [$passed, $label]) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_permission('security_logs.view');
$logs = db()->query(
    'SELECT sl.event_type, sl.ip_address, sl.details, sl.created_at, u.username
     FROM security_logs sl LEFT JOIN users u ON u.id = sl.user_id
     ORDER BY sl.created_at DESC LIMIT 200'
)->fetchAll();
render_header('Security logs');
page_title('ADMINISTRATION', 'Security events', 'Most recent 200 events. Passwords, OTP values, and authentication secrets are excluded.');
?>
<div class="table-controls" data-table-controls="security-events">
    <label class="table-search"><span>Search security events</span><input type="search" data-table-search placeholder="Event, account, IP address or details"></label>
    <div class="table-pagination"><span data-table-summary aria-live="polite"></span><button class="button button-quiet button-small" type="button" data-table-previous>Previous</button><button class="button button-quiet button-small" type="button" data-table-next>Next</button></div>
</div>
<section class="panel table-panel"><div class="table-scroll"><table id="security-events">
    <thead><tr><th>Time (UTC)</th><th>Event</th><th>Account</th><th>IP address</th><th>Details</th></tr></thead>
    <tbody><?php foreach ($logs as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><strong><?= e($log['event_type']) ?></strong></td><td><?= e($log['username'] ?? '—') ?></td><td><?= e($log['ip_address'] ?? '—') ?></td><td><?= e($log['details'] ?? '') ?></td></tr><?php endforeach; ?></tbody>
</table></div></section>
<?php render_footer(); ?>

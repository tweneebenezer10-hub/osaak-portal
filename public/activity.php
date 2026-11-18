<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_platform_super_admin();
$stmt = db()->prepare(
    'SELECT event_type, ip_address, details, created_at
     FROM security_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
);
$stmt->execute([(int) $user['id']]);
$events = $stmt->fetchAll();
render_header('Account activity');
page_title('PLATFORM ADMINISTRATION', 'Account activity', 'Your latest 100 recorded account-security events.');
?>
<div class="table-controls" data-table-controls="account-activity">
    <label class="table-search"><span>Search account activity</span><input type="search" data-table-search placeholder="Event, IP address or details"></label>
    <div class="table-pagination"><span data-table-summary aria-live="polite"></span><button class="button button-quiet button-small" type="button" data-table-previous>Previous</button><button class="button button-quiet button-small" type="button" data-table-next>Next</button></div>
</div>
<section class="panel table-panel"><div class="table-scroll"><table id="account-activity">
    <thead><tr><th>Time (UTC)</th><th>Event</th><th>IP address</th><th>Details</th></tr></thead>
    <tbody><?php foreach ($events as $event): ?><tr><td><?= e($event['created_at']) ?></td><td><strong><?= e($event['event_type']) ?></strong></td><td><?= e($event['ip_address'] ?? '—') ?></td><td><?= e($event['details'] ?? '') ?></td></tr><?php endforeach; ?></tbody>
</table></div></section>
<?php render_footer(); ?>

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'school.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    audit((int) $user['id'], 'school_lifecycle_billing_edit_denied', 'school_id=' . $schoolId);
    http_response_code(403);
    exit('Only the Platform Super Admin can edit school lifecycle or billing settings.');
}

$schoolDetails = db()->prepare(
    'SELECT id, name, short_name, school_type, status, subscription_status,
            subscription_term_fee, subscription_expires_at, contact_email, contact_phone,
            region, district, address
     FROM schools WHERE id = ? LIMIT 1'
);
$schoolDetails->execute([$schoolId]);
$schoolData = $schoolDetails->fetch();

render_header('School admin');
?>
<section class="panel">
    <p class="eyebrow">ADMIN</p>
    <h1>School administration</h1>
</section>

<section class="panel panel-top-gap">
    <h2>Lifecycle and billing status</h2>
    <p class="muted">These settings are managed by the Platform Super Admin. Contact platform support if a correction is needed.</p>
    <dl class="school-admin-summary">
        <div><dt>School status</dt><dd><?= e((string) ($schoolData['status'] ?? 'Unknown')) ?></dd></div>
        <div><dt>Subscription status</dt><dd><?= e((string) ($schoolData['subscription_status'] ?? 'Unknown')) ?></dd></div>
        <div><dt>Subscription fee per term (GH₵)</dt><dd><?= e((string) number_format((float) ($schoolData['subscription_term_fee'] ?? 0.0), 2, '.', '')) ?></dd></div>
        <div><dt>Subscription expiry</dt><dd><?= e((string) ($schoolData['subscription_expires_at'] ? date('Y-m-d', strtotime((string) $schoolData['subscription_expires_at'])) : 'Not set')) ?></dd></div>
    </dl>
</section>

<section class="panel panel-top-gap">
    <h2>School profile summary</h2>
    <p class="muted">Name: <?= e((string) ($schoolData['name'] ?? '')) ?> · Short name: <?= e((string) ($schoolData['short_name'] ?? '')) ?></p>
    <p class="muted">Region: <?= e((string) ($schoolData['region'] ?: 'Not set')) ?> · District: <?= e((string) ($schoolData['district'] ?: 'Not set')) ?></p>
    <p class="muted">Contact: <?= e((string) ($schoolData['contact_email'] ?: 'Not set')) ?> · <?= e((string) ($schoolData['contact_phone'] ?: 'Not set')) ?></p>
</section>
<?php render_footer(); ?>

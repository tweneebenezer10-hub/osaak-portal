<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_permission('dashboard.manage');
$stats = [];
if (user_has_permission($admin, 'members.view')) {
    $stats['users'] = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['verified'] = (int) db()->query('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL')->fetchColumn();
    $stats['disabled'] = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'disabled'")->fetchColumn();
}
if (user_has_permission($admin, 'security_logs.view')) {
    $stats['events'] = (int) db()->query('SELECT COUNT(*) FROM security_logs WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR')->fetchColumn();
}
if (user_has_permission($admin, 'events.view')) {
    $stats['upcoming_events'] = (int) db()->query("SELECT COUNT(*) FROM association_events WHERE status='published' AND starts_at >= UTC_TIMESTAMP()")->fetchColumn();
}
if (user_has_permission($admin, 'finance.view')) {
    $stats['pending_payments'] = (int) db()->query("SELECT COUNT(*) FROM payment_records WHERE status='pending'")->fetchColumn();
}
$dashboardType = match ($admin['active_role']) {
    'it_manager' => 'IT Manager',
    'administrator' => 'System administrator',
    'association_super_admin' => 'Association super administrator',
    'executive_member' => 'Executive Member',
    'president', 'vice_president', 'patron' => 'Association leadership',
    default => 'Association administrator',
};
render_header($dashboardType . ' dashboard');
page_title('ASSOCIATION PORTAL', $dashboardType . ' dashboard', 'Review the association information and management actions permitted for your role.');
?>
<section class="stat-grid">
    <?php if (isset($stats['users'])): ?>
    <article class="panel stat-card"><?php dashboard_section_heading('members', 'Registered accounts'); ?><strong><?= $stats['users'] ?></strong></article>
    <article class="panel stat-card"><?php dashboard_section_heading('security', 'Verified email addresses'); ?><strong><?= $stats['verified'] ?></strong></article>
    <article class="panel stat-card"><?php dashboard_section_heading('lock', 'Disabled accounts'); ?><strong><?= $stats['disabled'] ?></strong></article>
    <?php endif; ?>
    <?php if (isset($stats['events'])): ?><article class="panel stat-card"><?php dashboard_section_heading('activity', 'Security events · 24h'); ?><strong><?= $stats['events'] ?></strong></article><?php endif; ?>
    <?php if (isset($stats['upcoming_events'])): ?><article class="panel stat-card"><?php dashboard_section_heading('calendar', 'Upcoming events'); ?><strong><?= $stats['upcoming_events'] ?></strong></article><?php endif; ?>
    <?php if (isset($stats['pending_payments'])): ?><article class="panel stat-card"><?php dashboard_section_heading('finance', 'Payments awaiting review'); ?><strong><?= $stats['pending_payments'] ?></strong></article><?php endif; ?>
</section>
<section class="dashboard-grid">
    <?php if (user_has_permission($admin, 'members.manage')): ?>
    <article class="panel"><span class="eyebrow">MEMBER ACCESS</span><h2>Manage accounts</h2><p class="muted">Review registered accounts, update access roles, and manage account availability.</p><a class="text-link" href="users.php">Open member management →</a></article>
    <?php endif; ?>
    <?php if (user_has_permission($admin, 'security_logs.view')): ?>
    <article class="panel"><span class="eyebrow">AUDIT</span><h2>Review security events</h2><p class="muted">Inspect safe event metadata without authentication secrets.</p><a class="text-link" href="security_logs.php">View security logs →</a></article>
    <?php endif; ?>
    <?php if (user_has_permission($admin, 'events.view')): ?>
    <article class="panel"><span class="eyebrow">PROGRAMME</span><h2>Events and attendance</h2><p class="muted">Publish association events, manage registrations, and record attendance.</p><a class="text-link" href="school_services.php?module=events">Open events →</a></article>
    <?php endif; ?>
    <?php if (user_has_permission($admin, 'finance.view')): ?>
    <article class="panel"><span class="eyebrow">FINANCE</span><h2>Dues and payment records</h2><p class="muted">Review recorded payments and manage membership dues.</p><a class="text-link" href="school_services.php?module=finance">Open finance →</a></article>
    <?php endif; ?>
    <?php if (user_has_permission($admin, 'reports.view')): ?>
    <article class="panel"><span class="eyebrow">REPORTS</span><h2>Association reports</h2><p class="muted">Review current association totals and export member, event, and payment reports.</p><a class="text-link" href="school_services.php?module=reports">Open reports →</a></article>
    <?php endif; ?>
</section>
<?php render_footer(); ?>

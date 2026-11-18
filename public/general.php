<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_login();
if (!user_has_permission($user, 'dashboard.view')) {
    redirect(role_home($user));
}
$stmt = db()->prepare(
    'SELECT mp.membership_number, mp.membership_status, mp.phone, mp.occupation,
            mp.employer, mp.location, mp.biography, mp.graduation_year, mp.class_group
     FROM member_profiles mp
     WHERE mp.user_id = ?'
);
$stmt->execute([(int) $user['id']]);
$profile = $stmt->fetch() ?: [];
$profileFields = [
    (string) ($user['full_name'] ?? ''),
    (string) ($profile['phone'] ?? ''),
    (string) ($profile['occupation'] ?? ''),
    (string) ($profile['employer'] ?? ''),
    (string) ($profile['location'] ?? ''),
    (string) ($profile['biography'] ?? ''),
];
$completedFields = count(array_filter($profileFields, static fn(string $value): bool => trim($value) !== ''));
$profileCompletion = (int) round(($completedFields / count($profileFields)) * 100);

$yearGroups = [];
if (!empty($profile['graduation_year']) || !empty($profile['class_group'])) {
    $yearGroups[] = trim(implode(' · ', array_filter([
        (string) ($profile['graduation_year'] ?? ''),
        (string) ($profile['class_group'] ?? ''),
    ])));
}
$stmt = db()->prepare(
    "SELECT g.name
     FROM graduation_year_groups g
     JOIN member_year_group_assignments a ON a.group_id = g.id
     WHERE a.user_id = ? AND g.status = 'active'
     ORDER BY g.graduation_year DESC, g.name
     LIMIT 10"
);
$stmt->execute([(int) $user['id']]);
$yearGroups = array_values(array_unique(array_merge($yearGroups, array_column($stmt->fetchAll(), 'name'))));

$upcomingEvents = [];
if (user_has_permission($user, 'events.view')) {
    $stmt = db()->prepare(
        "SELECT e.title, e.starts_at, e.location, er.registration_status
         FROM association_events e
         LEFT JOIN event_registrations er
           ON er.event_id = e.id AND er.user_id = ?
         WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()
         ORDER BY e.starts_at
         LIMIT 5"
    );
    $stmt->execute([(int) $user['id']]);
    $upcomingEvents = $stmt->fetchAll();
}

$recentAnnouncements = [];
if (user_has_permission($user, 'announcements.view')) {
    $audiences = ['all'];
    if (($profile['membership_status'] ?? '') === 'active') {
        $audiences[] = 'members';
    }
    if (in_array($user['active_role'], [
        'administrator', 'association_super_admin', 'president',
        'vice_president', 'patron', 'secretary', 'assistant_secretary', 'treasurer',
        'assistant_treasurer', 'organising_secretary', 'public_relations_officer',
        'executive_member', 'electoral_officer', 'auditor', 'welfare_officer',
        'committee_chairperson', 'class_representative', 'branch_coordinator',
    ], true)) {
        $audiences[] = 'officials';
    }
    $audiencePlaceholders = implode(',', array_fill(0, count($audiences), '?'));
    $stmt = db()->prepare(
        "SELECT title, body, publish_at
         FROM association_announcements
         WHERE status = 'published'
           AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())
           AND audience IN ($audiencePlaceholders)
         ORDER BY COALESCE(publish_at, created_at) DESC
         LIMIT 5"
    );
    $stmt->execute($audiences);
    $recentAnnouncements = $stmt->fetchAll();
}

$paymentTotals = [];
$recentPayments = [];
$outstandingDues = [];
if (user_has_permission($user, 'dues.view_self')) {
    $stmt = db()->prepare(
        "SELECT p.currency, COUNT(*) AS payment_count,
                SUM(p.amount - COALESCE(a.amount, 0)) AS confirmed_total
         FROM payment_records p
         LEFT JOIN payment_adjustments a
           ON a.payment_id = p.id AND a.adjustment_type = 'refund'
         WHERE p.user_id = ? AND p.status = 'confirmed'
         GROUP BY p.currency
         HAVING SUM(p.amount - COALESCE(a.amount, 0)) > 0
         ORDER BY p.currency"
    );
    $stmt->execute([(int) $user['id']]);
    $paymentTotals = $stmt->fetchAll();
    $stmt = db()->prepare(
        "SELECT d.currency,
                SUM(GREATEST(CASE WHEN COALESCE(adjustments.is_exempt,0)=1 THEN 0
                                  ELSE d.amount-COALESCE(adjustments.credit_amount,0) END
                             -COALESCE(paid.paid_amount,0),0)) AS outstanding_amount,
                SUM(CASE WHEN d.due_date IS NOT NULL AND d.due_date < UTC_DATE()
                    THEN GREATEST(CASE WHEN COALESCE(adjustments.is_exempt,0)=1 THEN 0
                                       ELSE d.amount-COALESCE(adjustments.credit_amount,0) END
                                  -COALESCE(paid.paid_amount,0),0) ELSE 0 END) AS overdue_amount,
                COUNT(CASE WHEN GREATEST(CASE WHEN COALESCE(adjustments.is_exempt,0)=1 THEN 0
                                              ELSE d.amount-COALESCE(adjustments.credit_amount,0) END
                                         -COALESCE(paid.paid_amount,0),0)>0 THEN 1 END) AS outstanding_items
         FROM membership_dues d
         LEFT JOIN (
             SELECT due_id,user_id,SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END) AS credit_amount,
                    MAX(adjustment_type='exemption') AS is_exempt
             FROM membership_due_adjustments GROUP BY due_id,user_id
         ) adjustments ON adjustments.due_id=d.id AND adjustments.user_id=?
         LEFT JOIN (
             SELECT p.due_id,SUM(p.amount-COALESCE(a.amount,0)) AS paid_amount
             FROM payment_records p
             LEFT JOIN payment_adjustments a
               ON a.payment_id=p.id AND a.adjustment_type='refund'
             WHERE p.user_id=? AND p.status='confirmed' AND p.due_id IS NOT NULL
             GROUP BY p.due_id
         ) paid ON paid.due_id=d.id
         WHERE d.status='open' AND (d.target_year IS NULL OR d.target_year=?)
           AND (d.target_category IS NULL OR d.target_category=?)
         GROUP BY d.currency
         HAVING SUM(GREATEST(CASE WHEN COALESCE(adjustments.is_exempt,0)=1 THEN 0
                                  ELSE d.amount-COALESCE(adjustments.credit_amount,0) END
                             -COALESCE(paid.paid_amount,0),0))>0
         ORDER BY d.currency"
    );
    $stmt->execute([
        (int) $user['id'],
        (int) $user['id'],
        (int) ($profile['graduation_year'] ?? 0),
        (string) ($profile['membership_category'] ?? 'ordinary'),
    ]);
    $outstandingDues = $stmt->fetchAll();
    $stmt = db()->prepare(
        "SELECT p.amount, p.currency, p.payment_method, p.status, p.paid_at,
                COALESCE(a.amount, 0) AS refunded_amount
         FROM payment_records p
         LEFT JOIN payment_adjustments a
           ON a.payment_id = p.id AND a.adjustment_type = 'refund'
         WHERE p.user_id = ?
         ORDER BY p.paid_at DESC, p.id DESC
         LIMIT 5"
    );
    $stmt->execute([(int) $user['id']]);
    $recentPayments = $stmt->fetchAll();
}

render_header('General dashboard');
page_title('ASSOCIATION PORTAL', 'Welcome, ' . $user['full_name'], 'Your personal association updates and member services.');
?>
<section class="stat-grid">
    <article class="panel stat-card"><?php dashboard_section_heading('members', 'Membership status'); ?><strong><?= e(ucfirst((string) ($profile['membership_status'] ?? 'active'))) ?></strong></article>
    <article class="panel stat-card"><?php dashboard_section_heading('profile', 'Membership number'); ?><strong><?= e((string) ($profile['membership_number'] ?? 'Pending')) ?></strong></article>
    <article class="panel stat-card"><?php dashboard_section_heading('reports', 'Profile completion'); ?><strong><?= $profileCompletion ?>%</strong></article>
    <?php foreach ($outstandingDues as $dueBalance): ?><article class="panel stat-card"><?php dashboard_section_heading('finance', ((float) $dueBalance['overdue_amount'] > 0 ? 'Overdue dues' : 'Outstanding dues') . ' · ' . (string) $dueBalance['currency']); ?><strong><?= e($dueBalance['currency']) ?> <?= e(number_format((float) $dueBalance['outstanding_amount'], 2)) ?></strong><span class="muted"><?= (int) $dueBalance['outstanding_items'] ?> open obligation(s)<?= (float) $dueBalance['overdue_amount'] > 0 ? ' · overdue ' . e($dueBalance['currency']) . ' ' . e(number_format((float) $dueBalance['overdue_amount'], 2)) : '' ?></span></article><?php endforeach; ?>
</section>
<?php if ($yearGroups): ?>
<section class="panel top-gap"><span class="eyebrow">YOUR YEAR GROUP</span><p><?= e(implode(', ', $yearGroups)) ?></p></section>
<?php endif; ?>
<?php render_association_shortcuts($user); ?>
<section class="dashboard-grid top-gap">
    <?php if (user_has_permission($user, 'events.view')): ?>
    <article class="panel"><span class="eyebrow">UPCOMING</span><h2>Association events</h2>
        <?php if (!$upcomingEvents): ?><p class="muted">There are no upcoming published events.</p><?php else: ?>
        <ul class="activity-list" data-show-more><?php foreach ($upcomingEvents as $event): ?><li><strong><?= e($event['title']) ?></strong><span><?= e((string) $event['starts_at']) ?> UTC<?= $event['location'] ? ' · ' . e($event['location']) : '' ?></span><?php if ($event['registration_status'] === 'registered'): ?><span class="status-pill">Registered</span><?php endif; ?></li><?php endforeach; ?></ul>
        <a class="text-link" href="school_services.php?module=events">View all events →</a>
        <?php endif; ?>
    </article>
    <?php endif; ?>
    <?php if (user_has_permission($user, 'announcements.view')): ?>
    <article class="panel"><span class="eyebrow">ASSOCIATION NEWS</span><h2>Recent announcements</h2>
        <?php if (!$recentAnnouncements): ?><p class="muted">There are no current announcements for your account.</p><?php else: ?>
        <ul class="activity-list" data-show-more><?php foreach ($recentAnnouncements as $announcement): ?><li><strong><?= e($announcement['title']) ?></strong><?php render_dashboard_read_more((string) $announcement['body'], 220); ?></li><?php endforeach; ?></ul>
        <a class="text-link" href="school_services.php?module=announcements">View all announcements →</a>
        <?php endif; ?>
    </article>
    <?php endif; ?>
    <?php if (user_has_permission($user, 'dues.view_self')): ?>
    <article class="panel"><span class="eyebrow">MY PAYMENTS</span><h2>Confirmed contributions</h2>
        <?php if (!$paymentTotals): ?><p class="muted">No confirmed payments are recorded yet.</p><?php else: ?><ul class="activity-list"><?php foreach ($paymentTotals as $total): ?><li><strong><?= e((string) $total['currency']) ?> <?= e(number_format((float) $total['confirmed_total'], 2)) ?></strong><span><?= (int) $total['payment_count'] ?> confirmed payment record(s)</span></li><?php endforeach; ?></ul><?php endif; ?>
        <a class="text-link" href="school_services.php?module=finance">View my dues and payments →</a>
    </article>
    <?php endif; ?>
    <?php if ($recentPayments): ?>
    <article class="panel"><span class="eyebrow">PAYMENT HISTORY</span><h2>Recent records</h2><ul class="activity-list" data-show-more><?php foreach ($recentPayments as $payment): ?><li><strong><?= e((string) $payment['currency']) ?> <?= e(number_format((float) $payment['amount'], 2)) ?> · <?= e((float) $payment['refunded_amount'] > 0 ? 'Refunded' : ucfirst((string) $payment['status'])) ?></strong><span><?= e((string) $payment['payment_method']) ?> · <?= e((string) $payment['paid_at']) ?> UTC</span></li><?php endforeach; ?></ul><a class="text-link" href="school_services.php?module=finance">View all payments →</a></article>
    <?php endif; ?>
</section>
<?php render_footer(); ?>

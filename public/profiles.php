<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'staff.view');

$profileStmt = db()->prepare(
    'SELECT ss.id, ss.user_id, ss.role_name,
            GROUP_CONCAT(DISTINCT sm.role_name ORDER BY sm.role_name SEPARATOR ", ") AS role_names,
            ss.status, ss.employee_number, u.full_name, u.email, u.username
     FROM school_staff ss
     JOIN users u ON u.id = ss.user_id
     LEFT JOIN school_memberships sm
       ON sm.school_id = ss.school_id AND sm.user_id = ss.user_id AND sm.status = "active"
      AND sm.role_name NOT IN ("school_parent", "school_student")
     WHERE ss.school_id = ? GROUP BY ss.id, ss.user_id, ss.role_name, ss.status, ss.employee_number,
            u.full_name, u.email, u.username
     ORDER BY u.full_name ASC'
);
$profileStmt->execute([$schoolId]);
$profiles = $profileStmt->fetchAll();

render_header('School profiles');
?>
<section class="panel">
    <p class="eyebrow">PEOPLE</p>
    <h1>School profiles</h1>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <?php if ($profiles === []): ?>
        <p class="muted">No staff profiles are linked to this school yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Employee no.</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($profiles as $profile): ?>
                    <tr>
                        <td><?= e((string) $profile['full_name']) ?></td>
                        <td><?= e((string) $profile['username']) ?></td>
                        <td><?= e((string) $profile['email']) ?></td>
                        <td><?= e((string) ($profile['role_names'] ?: $profile['role_name'])) ?></td>
                        <td><?= e((string) ($profile['employee_number'] ?: '—')) ?></td>
                        <td><?= e((string) $profile['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_footer(); ?>

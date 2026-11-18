<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_login();
$canManageAccounts = user_has_permission($admin, 'members.manage');
if (!$canManageAccounts) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account cannot manage member accounts.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$userId || (int) $userId === (int) $admin['id']) {
        flash('error', 'The requested account change is not permitted.');
        redirect('users.php');
    }

    if ($action === 'delete') {
        db()->beginTransaction();
        try {
            $stmt = db()->prepare(
                'SELECT u.id, u.full_name, mp.membership_number
                 FROM users u
                 JOIN member_profiles mp ON mp.user_id = u.id
                 WHERE u.id = ?
                 FOR UPDATE'
            );
            $stmt->execute([(int) $userId]);
            $target = $stmt->fetch();
            if (!$target) {
                db()->rollBack();
                flash('error', 'That member account does not exist.');
                redirect('users.php');
            }

            $stmt = db()->prepare(
                'SELECT r.name
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([(int) $userId]);
            $targetRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (array_intersect($targetRoles, ['administrator', 'it_manager'])) {
                db()->rollBack();
                flash('error', 'Administrator and IT Manager accounts cannot be deleted from member management.');
                redirect('users.php');
            }

            $staffProfileStmt = db()->prepare(
                'SELECT COUNT(*) FROM school_staff WHERE user_id = ?'
            );
            $staffProfileStmt->execute([(int) $userId]);
            if ((int) $staffProfileStmt->fetchColumn() > 0) {
                db()->rollBack();
                flash('error', 'This account has a school staff record. Disable the account instead so its staff attendance history remains available to school administrators.');
                redirect('users.php');
            }

            audit(
                (int) $admin['id'],
                'admin_member_deleted',
                'target_user_id=' . (int) $userId . '; membership_number=' . (string) ($target['membership_number'] ?? '')
            );
            $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([(int) $userId]);
            db()->commit();
            flash('success', 'Member account and its associated profile data were permanently deleted.');
        } catch (PDOException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception->getCode() === '23000') {
                flash('error', 'This member has association records that must be retained, so the account cannot be permanently deleted. Disable the account instead.');
                redirect('users.php');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        redirect('users.php');
    }

    $status = (string) ($_POST['status'] ?? '');
    if ($action !== 'status' || !in_array($status, ['active', 'disabled'], true)) {
        flash('error', 'The requested account change is not permitted.');
        redirect('users.php');
    }
    $stmt = db()->prepare(
        'SELECT u.id, u.email_verified_at, mp.membership_status
         FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id WHERE u.id = ?'
    );
    $stmt->execute([(int) $userId]);
    $target = $stmt->fetch();
    if (!$target) {
        flash('error', 'That account does not exist.');
        redirect('users.php');
    }
    if ($status === 'active' && ($target['email_verified_at'] === null || $target['membership_status'] !== 'active')) {
        flash('error', 'Only an email-verified member with active membership can be activated.');
        redirect('users.php');
    }
    db()->beginTransaction();
    $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
    $stmt->execute([$status, (int) $userId]);
    if ($status === 'disabled') {
        $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->execute([(int) $userId]);
    }
    db()->commit();
    audit((int) $admin['id'], 'admin_user_status_updated', 'target_user_id=' . (int) $userId . '; status=' . $status);
    flash('success', 'Account status updated. Role assignments are managed separately.');
    redirect('users.php');
}

$users = db()->query(
    'SELECT u.id, u.full_name, u.username, u.email, u.status, u.email_verified_at,
            mp.membership_number, mp.membership_status, mp.phone, mp.graduation_year,
            mp.user_id AS member_profile_user_id, mp.membership_id_review_required,
            GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR \',\') AS roles
     FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
     LEFT JOIN member_profiles mp ON mp.user_id = u.id
     GROUP BY u.id ORDER BY u.created_at DESC LIMIT 200'
)->fetchAll();
render_header('User management');
page_title('ASSOCIATION PORTAL', 'Member accounts', 'Manage account roles and availability, or delete member accounts when necessary.');
?>
<p class="notice notice-error" role="note">Deleting a member is permanent and removes their account and linked personal profile data. The system blocks deletion when association records must be retained; disable those accounts instead.</p>
<div class="table-controls" data-table-controls="member-accounts">
    <a class="button button-quiet button-small" href="member_import.php">Import member profile CSV</a>
    <label class="table-search"><span>Search accounts</span><input type="search" data-table-search placeholder="Name, username, email or role"></label>
    <div class="table-pagination"><span data-table-summary aria-live="polite"></span><button class="button button-quiet button-small" type="button" data-table-previous>Previous</button><button class="button button-quiet button-small" type="button" data-table-next>Next</button></div>
</div>
<section class="panel table-panel"><div class="table-scroll"><table id="member-accounts">
    <thead><tr><th>Member</th><th>Email</th><th>Membership</th><th>Access</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $item): ?>
        <tr><td><strong><?= e($item['full_name']) ?></strong><br><span class="muted"><?= e($item['membership_number'] ?? 'No membership number') ?> · @<?= e($item['username']) ?></span></td>
        <td><?= e($item['email']) ?><br><span class="status-pill <?= $item['email_verified_at'] ? 'status-good' : '' ?>"><?= $item['email_verified_at'] ? 'Verified' : 'Pending verification' ?></span></td>
        <td><span class="status-pill <?= $item['membership_status'] === 'active' ? 'status-good' : '' ?>"><?= e(ucfirst((string) ($item['membership_status'] ?? 'active'))) ?></span><br><span class="muted">Graduation year: <?= e((string) ($item['graduation_year'] ?? 'Not recorded')) ?></span><?php if ($item['membership_id_review_required']): ?><br><span class="status-pill">ID/year review required</span><?php endif; ?></td>
        <td><?= e(str_replace(',', ', ', (string) $item['roles'])) ?><br><span class="status-pill <?= $item['status'] === 'active' ? 'status-good' : '' ?>"><?= e(ucfirst((string) $item['status'])) ?></span></td>
        <td><?php if ((int) $item['id'] === (int) $admin['id']): ?><span class="muted">Current administrator</span><?php else: ?>
            <?php if ($item['membership_status'] === 'active'): ?><form method="post" action="users.php" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int) $item['id'] ?>">
                <label class="visually-hidden" for="status-<?= (int) $item['id'] ?>">Status</label><select id="status-<?= (int) $item['id'] ?>" name="status"><option value="active" <?= $item['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $item['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option></select>
                <button class="button button-small" type="submit">Save</button>
            </form><a class="text-link" href="roles.php">Manage roles</a><?php else: ?><span class="muted">Membership status: <?= e((string) $item['membership_status']) ?></span><?php endif; ?>
            <?php if ($item['member_profile_user_id'] !== null && !in_array('administrator', explode(',', (string) $item['roles']), true) && !in_array('it_manager', explode(',', (string) $item['roles']), true)): ?>
                <form method="post" action="users.php" class="inline-form" onsubmit="return confirm('Permanently delete this member account? The account, membership profile, role assignments, and linked personal data will be removed. This cannot be undone. Members with association records that must be retained cannot be deleted.');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $item['id'] ?>">
                    <button class="button button-small button-danger" type="submit">Delete member</button>
                </form>
            <?php endif; ?>
            <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></section>
<?php render_footer(); ?>

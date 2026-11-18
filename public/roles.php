<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$manager = require_permission('roles.manage');
$assignableRoles = [
    'it_manager' => 'IT Manager',
    'president' => 'President',
    'vice_president' => 'Vice President',
    'patron' => 'Patron',
    'secretary' => 'Secretary',
    'assistant_secretary' => 'Assistant Secretary',
    'treasurer' => 'Treasurer',
    'assistant_treasurer' => 'Assistant Treasurer',
    'organising_secretary' => 'Organising Secretary',
    'public_relations_officer' => 'Public Relations Officer',
    'executive_member' => 'Executive Member',
    'electoral_officer' => 'Electoral Officer',
    'auditor' => 'Auditor',
    'welfare_officer' => 'Welfare Officer',
    'committee_chairperson' => 'Committee Chairperson',
    'class_representative' => 'Class Representative',
    'branch_coordinator' => 'Branch Coordinator',
    'ordinary_member' => 'Ordinary Member',
];
$restrictedCustomPermissions = [
    'dashboard.manage',
    'members.manage',
    'members.approve',
    'roles.manage',
    'security.manage',
    'security_logs.view',
    'system.settings.manage',
];
$customPermissions = db()->query(
    'SELECT name, description FROM permissions WHERE name NOT IN (
        \'dashboard.manage\', \'members.manage\', \'members.approve\', \'roles.manage\',
        \'security.manage\', \'security_logs.view\', \'system.settings.manage\'
     ) ORDER BY name'
)->fetchAll();
$customPermissionNames = array_column($customPermissions, 'name');
$customRoles = db()->query("SELECT id,name FROM roles WHERE LEFT(name,7)='custom_' ORDER BY name")->fetchAll();
foreach ($customRoles as $customRole) {
    $assignableRoles[$customRole['name']] = ucwords(str_replace('_', ' ', substr($customRole['name'], 7)));
}
if (($manager['active_role'] ?? null) !== 'administrator'
    || !user_has_permission($manager, 'security.manage')) {
    unset($assignableRoles['it_manager']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_custom_role') {
        $slug = (string) ($_POST['role_name'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_]{0,23}$/', $slug)) {
            flash('error', 'Use 1–24 lowercase letters, numbers, or underscores for the role name.');
            redirect('roles.php');
        }
        $roleName = 'custom_' . $slug;
        $stmt = db()->prepare('SELECT COUNT(*) FROM roles WHERE name=?');
        $stmt->execute([$roleName]);
        if ((int) $stmt->fetchColumn() > 0 || (int) db()->query('SELECT COUNT(*) FROM roles')->fetchColumn() >= 255) {
            flash('error', 'That role already exists or the role catalog is full.');
            redirect('roles.php');
        }
        $stmt = db()->prepare('INSERT INTO roles (name) VALUES (?)');
        $stmt->execute([$roleName]);
        audit((int) $manager['id'], 'custom_role_created', 'role=' . $roleName);
        flash('success', 'Custom role created. Configure its permissions before assigning it.');
        redirect('roles.php');
    }
    if ($action === 'save_custom_permissions') {
        $roleName = (string) ($_POST['role'] ?? '');
        $selectedPermissions = $_POST['permissions'] ?? [];
        if (!preg_match('/^custom_[a-z0-9][a-z0-9_]{0,23}$/', $roleName)
            || !is_array($selectedPermissions)
            || array_filter($selectedPermissions, static fn(mixed $permission): bool => !is_string($permission) || !in_array($permission, $customPermissionNames, true))) {
            flash('error', 'Choose a custom role and only permissions available for association roles.');
            redirect('roles.php');
        }
        $stmt = db()->prepare('SELECT id FROM roles WHERE name=?');
        $stmt->execute([$roleName]);
        $customRoleId = $stmt->fetchColumn();
        if (!$customRoleId) {
            flash('error', 'That custom role no longer exists.');
            redirect('roles.php');
        }
        db()->beginTransaction();
        try {
            $stmt = db()->prepare('DELETE FROM role_permissions WHERE role_id=?');
            $stmt->execute([(int) $customRoleId]);
            $stmt = db()->prepare('INSERT INTO role_permissions (role_id,permission_name) VALUES (?,?)');
            foreach (array_unique($selectedPermissions) as $permission) {
                if (in_array($permission, $restrictedCustomPermissions, true)) {
                    throw new InvalidArgumentException('Technical or account-administration permissions cannot be granted to custom roles.');
                }
                $stmt->execute([(int) $customRoleId, $permission]);
            }
            db()->commit();
        } catch (PDOException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect('roles.php');
        }
        audit((int) $manager['id'], 'custom_role_permissions_updated', 'role=' . $roleName);
        flash('success', 'Custom role permissions updated.');
        redirect('roles.php');
    }
    if ($action === 'create_executive_request') {
        $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!$targetId || (int) $targetId === (int) $manager['id']
            || preg_match('/^.{5,500}$/us', $reason) !== 1) {
            flash('error', 'Choose another member and provide an appointment reason of 5 to 500 characters.');
            redirect('roles.php');
        }
        db()->beginTransaction();
        try {
            $stmt = db()->prepare(
                "SELECT u.id,u.full_name,u.status,u.email_verified_at,mp.membership_status,mp.membership_number
                 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
                 WHERE u.id=? FOR UPDATE"
            );
            $stmt->execute([(int) $targetId]);
            $target = $stmt->fetch();
            if (!$target || $target['status'] !== 'active' || $target['email_verified_at'] === null
                || $target['membership_status'] !== 'active') {
                throw new InvalidArgumentException('Executive appointment requests require an active, verified member.');
            }
            $stmt = db()->prepare(
                "SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                 WHERE ur.user_id=? AND r.name='executive_member'"
            );
            $stmt->execute([(int) $targetId]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('That member already has the Executive Member role.');
            }
            $stmt = db()->prepare("SELECT 1 FROM member_role_requests WHERE user_id=? AND status='pending' FOR UPDATE");
            $stmt->execute([(int) $targetId]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('That member already has a pending role request.');
            }
            $stmt = db()->prepare(
                "INSERT INTO member_role_requests (user_id,requested_role,request_reason)
                 VALUES (?,'executive_member',?)"
            );
            $stmt->execute([(int) $targetId, $reason]);
            audit((int) $manager['id'], 'executive_role_request_created', 'target_user_id=' . (int) $targetId . '; reason=' . $reason);
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof InvalidArgumentException) {
                flash('error', $exception->getMessage());
                redirect('roles.php');
            }
            throw $exception;
        }
        flash('success', 'Executive appointment request created. It must be reviewed separately before the role is active.');
        redirect('roles.php');
    }
    if ($action === 'review_executive_request') {
        if (!in_array(($manager['active_role'] ?? ''), ['administrator', 'it_manager'], true)) {
            http_response_code(403);
            exit('Only an IT Manager or system administrator can review Executive Member appointments.');
        }
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string) ($_POST['decision'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!$requestId || !in_array($decision, ['approve', 'reject'], true)
            || preg_match('/^.{5,500}$/us', $reason) !== 1) {
            flash('error', 'Choose a pending appointment and provide a review reason of 5 to 500 characters.');
            redirect('roles.php');
        }
        db()->beginTransaction();
        try {
            $stmt = db()->prepare(
                "SELECT q.*,u.full_name,u.status AS account_status,u.email_verified_at,
                        mp.membership_status,mp.membership_number
                 FROM member_role_requests q
                 JOIN users u ON u.id=q.user_id
                 JOIN member_profiles mp ON mp.user_id=u.id
                 WHERE q.id=? FOR UPDATE"
            );
            $stmt->execute([(int) $requestId]);
            $request = $stmt->fetch();
            if (!$request || $request['status'] !== 'pending') {
                throw new InvalidArgumentException('That appointment request is no longer pending.');
            }
            if ($decision === 'approve') {
                if ($request['account_status'] !== 'active' || $request['email_verified_at'] === null
                    || $request['membership_status'] !== 'active') {
                    throw new InvalidArgumentException('Only an active, verified member can be appointed.');
                }
                $stmt = db()->prepare(
                    "SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                     WHERE ur.user_id=? AND r.name='executive_member'"
                );
                $stmt->execute([(int) $request['user_id']]);
                if ($stmt->fetchColumn()) {
                    throw new InvalidArgumentException('This member already has the Executive Member role; the request was not changed.');
                }
                $stmt = db()->prepare(
                    "INSERT INTO user_roles (user_id,role_id)
                     SELECT ?,id FROM roles WHERE name='executive_member'"
                );
                $stmt->execute([(int) $request['user_id']]);
                $stmt = db()->prepare(
                    "INSERT INTO member_role_history
                     (target_user_id,member_name,membership_number,role_name,action,changed_by,changed_by_name,reason)
                     VALUES (?,?,?,'executive_member','assigned',?, ?, ?)"
                );
                $stmt->execute([
                    (int) $request['user_id'],
                    (string) $request['full_name'],
                    $request['membership_number'],
                    (int) $manager['id'],
                    (string) $manager['full_name'],
                    $reason,
                ]);
            }
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $stmt = db()->prepare(
                'UPDATE member_role_requests
                 SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),review_reason=?
                 WHERE id=? AND status=\'pending\''
            );
            $stmt->execute([$status, (int) $manager['id'], $reason, (int) $requestId]);
            audit(
                (int) $manager['id'],
                'executive_role_request_' . $status,
                'request_id=' . (int) $requestId . '; target_user_id=' . (int) $request['user_id'] . '; reason=' . $reason
            );
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof InvalidArgumentException) {
                flash('error', $exception->getMessage());
                redirect('roles.php');
            }
            throw $exception;
        }
        flash('success', $decision === 'approve' ? 'Executive Member appointment approved.' : 'Executive Member appointment rejected.');
        redirect('roles.php');
    }

    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $role = (string) ($_POST['role'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (!$userId || !isset($assignableRoles[$role]) || !in_array($action, ['assign', 'remove'], true)
        || (int) $userId === (int) $manager['id']
        || preg_match('/^.{5,500}$/us', $reason) !== 1) {
        flash('error', 'Select another user and an assignable association role, and provide a reason of 5 to 500 characters.');
        redirect('roles.php');
    }
    if ($role === 'it_manager'
        && (($manager['active_role'] ?? null) !== 'administrator'
            || !user_has_permission($manager, 'security.manage'))) {
        http_response_code(403);
        exit('Only a system administrator can assign or remove the IT Manager role.');
    }
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name, u.status, u.email_verified_at,
                mp.membership_status, mp.membership_number
         FROM users u LEFT JOIN member_profiles mp ON mp.user_id=u.id WHERE u.id=?'
    );
    $stmt->execute([(int) $userId]);
    $target = $stmt->fetch();
    if (!$target) {
        flash('error', 'That account does not exist.');
        redirect('roles.php');
    }
    if ($action === 'assign' && $role === 'executive_member') {
        flash('error', 'Executive Member appointments must be approved from a pending request.');
        redirect('roles.php');
    }
    if ($action === 'assign'
        && ($target['status'] !== 'active' || $target['email_verified_at'] === null || $target['membership_status'] !== 'active')) {
        flash('error', 'Association roles can only be assigned to active, email-verified members.');
        redirect('roles.php');
    }
    db()->beginTransaction();
    try {
        if ($action === 'assign') {
            $stmt = db()->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name=?');
            $stmt->execute([(int) $userId, $role]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('That account already has this role; no role change was made.');
            }
            $historyAction = 'assigned';
        } else {
            $stmt = db()->prepare('DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name=?');
            $stmt->execute([(int) $userId, $role]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('That role assignment was not found.');
            }
            $historyAction = 'removed';
            if ($role === 'executive_member') {
                $stmt = db()->prepare(
                    "UPDATE member_role_requests
                     SET status='revoked',reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),review_reason=?
                     WHERE user_id=? AND status='approved'
                     ORDER BY reviewed_at DESC,id DESC LIMIT 1"
                );
                $stmt->execute([(int) $manager['id'], $reason, (int) $userId]);
            }
        }
        audit(
            (int) $manager['id'],
            'association_role_' . $historyAction,
            'target_user_id=' . (int) $userId . '; role=' . $role . '; reason=' . $reason
        );
        $stmt = db()->prepare(
            'INSERT INTO member_role_history
             (target_user_id, member_name, membership_number, role_name, action,
              changed_by, changed_by_name, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $userId,
            (string) $target['full_name'],
            $target['membership_number'],
            $role,
            $historyAction,
            (int) $manager['id'],
            (string) $manager['full_name'],
            $reason,
        ]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception instanceof InvalidArgumentException) {
            flash('error', $exception->getMessage());
            redirect('roles.php');
        }
        throw $exception;
    }
    flash('success', $action === 'assign' ? 'Association role assigned.' : 'Association role removed.');
    redirect('roles.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$memberSql =
    "SELECT u.id,u.full_name,u.username,u.email,u.status,u.email_verified_at,
            mp.membership_number,mp.graduation_year,
            GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ',') AS roles
     FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
     LEFT JOIN member_profiles mp ON mp.user_id=u.id";
$parameters = [];
if ($search !== '') {
    $memberSql .= " WHERE u.full_name LIKE ? OR u.email LIKE ? OR mp.membership_number LIKE ?
                    OR CAST(mp.graduation_year AS CHAR) LIKE ? OR r.name LIKE ?";
    $term = '%' . $search . '%';
    $parameters = [$term, $term, $term, $term, $term];
}
$memberSql .= ' GROUP BY u.id ORDER BY u.full_name LIMIT 500';
$stmt = db()->prepare($memberSql);
$stmt->execute($parameters);
$members = $stmt->fetchAll();
$pendingExecutiveRequests = db()->query(
    "SELECT q.id,q.user_id,q.request_reason,q.created_at,u.full_name,u.email,mp.membership_number
     FROM member_role_requests q
     JOIN users u ON u.id=q.user_id
     JOIN member_profiles mp ON mp.user_id=u.id
     WHERE q.status='pending'
     ORDER BY q.created_at,q.id LIMIT 200"
)->fetchAll();
$pendingExecutiveUserIds = array_fill_keys(array_map(
    static fn(array $request): int => (int) $request['user_id'],
    $pendingExecutiveRequests
), true);
$roleHistory = db()->query(
    'SELECT target_user_id,member_name,membership_number,role_name,action,
            changed_by_name,reason,created_at
     FROM member_role_history ORDER BY created_at DESC,id DESC LIMIT 100'
)->fetchAll();
$customRolePermissions = [];
if ($customRoles) {
    $permissionRows = db()->query(
        "SELECT rp.role_id,rp.permission_name
         FROM role_permissions rp
         JOIN roles r ON r.id=rp.role_id
         WHERE LEFT(r.name,7)='custom_'"
    )->fetchAll();
    foreach ($permissionRows as $permissionRow) {
        $customRolePermissions[(int) $permissionRow['role_id']][] = $permissionRow['permission_name'];
    }
}
render_header('Role assignments');
page_title('ACCESS CONTROL', 'Role assignments', 'Assign authorised association roles to active, verified members. Only a system administrator can assign the IT Manager role.');
?>
<section class="panel role-warning"><strong>Permission notice:</strong> Roles grant all permissions mapped to that role. Technical and account-administration permissions cannot be granted to custom roles. Do not assign a role unless the association has authorized the appointment.</section>
<section class="panel table-panel module-table"><h2>Pending Executive Member appointments</h2><div class="table-scroll"><table><thead><tr><th>Member</th><th>Request reason</th><th>Requested (UTC)</th><th>Decision</th></tr></thead><tbody>
<?php foreach ($pendingExecutiveRequests as $request): ?><tr><td><strong><?= e($request['full_name']) ?></strong><br><span class="muted"><?= e($request['email']) ?> · <?= e($request['membership_number'] ?: 'No membership ID') ?></span></td><td><?= e($request['request_reason'] ?: 'No reason supplied') ?></td><td><?= e($request['created_at']) ?></td><td><?php if (in_array(($manager['active_role'] ?? ''), ['administrator','it_manager'], true)): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="review_executive_request"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><select name="decision" aria-label="Appointment decision"><option value="approve">Approve</option><option value="reject">Reject</option></select><input name="reason" minlength="5" maxlength="500" placeholder="Review reason (required)" required><button class="button button-small" type="submit">Save decision</button></form><?php else: ?><span class="muted">Only an IT Manager can review this appointment.</span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$pendingExecutiveRequests): ?><tr><td colspan="4" class="muted">No Executive Member appointments are waiting for review.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel module-form-panel"><h2>Create custom association role</h2><p class="muted">Custom role names are prefixed with “custom_”. Configure permissions before assigning the role.</p><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_custom_role"><label for="custom-role-name">Role name</label><input id="custom-role-name" name="role_name" minlength="1" maxlength="24" pattern="[a-z0-9][a-z0-9_]{0,23}" required><button class="button button-primary" type="submit">Create role</button></form></section>
<?php foreach ($customRoles as $customRole): ?>
<section class="panel module-form-panel"><h2><?= e($assignableRoles[$customRole['name']]) ?></h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_custom_permissions"><input type="hidden" name="role" value="<?= e($customRole['name']) ?>">
    <?php foreach ($customPermissions as $permission): ?><label class="check-label"><input type="checkbox" name="permissions[]" value="<?= e($permission['name']) ?>" <?= in_array($permission['name'], $customRolePermissions[(int) $customRole['id']] ?? [], true) ? 'checked' : '' ?>> <span><strong><?= e($permission['name']) ?></strong><br><small class="muted"><?= e($permission['description']) ?></small></span></label><?php endforeach; ?>
    <button class="button button-primary" type="submit">Save permissions</button>
</form></section>
<?php endforeach; ?>
<section class="panel module-form-panel"><h2>Find a member</h2><form method="get" action="roles.php" class="inline-form"><label for="role-search">Name, email, membership ID, graduation year, or role</label><input id="role-search" name="q" value="<?= e($search) ?>" maxlength="160"><button class="button button-small" type="submit">Search</button><?php if ($search !== ''): ?><a class="text-link" href="roles.php">Clear</a><?php endif; ?></form></section>
<section class="panel table-panel module-table"><div class="table-scroll"><table><thead><tr><th>Account</th><th>Assigned roles</th><th>Assign association role</th></tr></thead><tbody>
<?php foreach ($members as $member): $memberRoles = explode(',', (string) $member['roles']); ?>
    <tr><td><strong><?= e($member['full_name']) ?></strong><br><span class="muted"><?= e($member['email']) ?> · <?= e((string) ($member['membership_number'] ?? 'No membership ID')) ?> · <?= e((string) ($member['graduation_year'] ?? 'Year unknown')) ?><br>@<?= e($member['username']) ?> · <?= e(ucfirst($member['status'])) ?><?= $member['email_verified_at'] ? ' · Verified' : ' · Unverified' ?></span></td>
        <td><?php foreach ($memberRoles as $role): ?><span class="status-pill"><?= e(str_replace('_', ' ', $role)) ?></span>
            <?php if (isset($assignableRoles[$role]) && (int) $member['id'] !== (int) $manager['id']): ?><form method="post" class="inline-form role-remove-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>"><input type="hidden" name="role" value="<?= e($role) ?>"><input type="hidden" name="action" value="remove"><label class="visually-hidden" for="remove-reason-<?= (int) $member['id'] ?>-<?= e($role) ?>">Reason for removing <?= e($assignableRoles[$role]) ?></label><input id="remove-reason-<?= (int) $member['id'] ?>-<?= e($role) ?>" name="reason" minlength="5" maxlength="500" placeholder="Reason (required)" required><button class="button button-quiet button-small" type="submit" aria-label="Remove <?= e($assignableRoles[$role]) ?> role from <?= e($member['full_name']) ?>">Remove</button></form><?php endif; ?>
        <?php endforeach; ?></td>
        <td><?php if ((int) $member['id'] !== (int) $manager['id']): ?><form method="post" class="inline-form role-assign-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>"><input type="hidden" name="action" value="assign"><label class="visually-hidden" for="association-role-<?= (int) $member['id'] ?>">Association role</label><select id="association-role-<?= (int) $member['id'] ?>" name="role"><?php foreach ($assignableRoles as $name => $label): if ($name === 'executive_member') continue; ?><option value="<?= e($name) ?>"><?= e($label) ?></option><?php endforeach; ?></select><label class="visually-hidden" for="assign-reason-<?= (int) $member['id'] ?>">Reason for assigning role</label><input id="assign-reason-<?= (int) $member['id'] ?>" name="reason" minlength="5" maxlength="500" placeholder="Reason (required)" required><button class="button button-small" type="submit">Assign</button></form><?php if (!in_array('executive_member', $memberRoles, true) && !isset($pendingExecutiveUserIds[(int) $member['id']])): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_executive_request"><input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>"><input name="reason" minlength="5" maxlength="500" placeholder="Executive appointment reason" required><button class="button button-quiet button-small" type="submit">Request Executive appointment</button></form><?php endif; ?><?php else: ?><span class="muted">Your own roles cannot be changed here.</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<section class="panel table-panel module-table"><h2>Role assignment history</h2><div class="table-scroll"><table><thead><tr><th>Member</th><th>Role change</th><th>Administrator</th><th>Reason</th><th>When (UTC)</th></tr></thead><tbody>
<?php foreach ($roleHistory as $entry): ?><tr><td><?= e($entry['member_name']) ?><br><span class="muted"><?= e((string) ($entry['membership_number'] ?? 'No membership ID')) ?> · account <?= (int) $entry['target_user_id'] ?></span></td><td><?= e(ucfirst($entry['action'])) ?> · <?= e(str_replace('_', ' ', $entry['role_name'])) ?></td><td><?= e($entry['changed_by_name']) ?></td><td><?= e($entry['reason']) ?></td><td><?= e($entry['created_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$roleHistory): ?><tr><td colspan="5" class="muted">No role changes have been recorded since role history was enabled.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php render_footer(); ?>

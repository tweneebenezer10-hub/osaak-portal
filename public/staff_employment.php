<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'staff.manage');
if (active_it_support_context($user) !== null) {
    http_response_code(403);
    render_header('Employment history unavailable');
    echo '<section class="panel"><h1>Employment history unavailable</h1><p>Staff employment records cannot be managed during IT Support Mode.</p><a class="button" href="school_dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}
if (!school_staff_employment_schema_ready()) {
    http_response_code(503);
    render_header('Staff employment update required');
    echo '<section class="panel"><h1>Staff employment history is not configured</h1><p>Back up the school database, apply migration 107_staff_employment_history.sql after migration 106, then reload this page. No staff records were changed.</p><a class="button" href="staff.php">Return to staff register</a></section>';
    render_footer();
    exit;
}

$today = date('Y-m-d');
$validDate = static function (mixed $value) use ($today): string {
    $dateText = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $dateText
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $dateText < $today) {
        throw new InvalidArgumentException('Choose a valid effective date that is today or later.');
    }
    return $dateText;
};
$validateText = static function (mixed $value, int $maximum, string $label, bool $required = true): string {
    $text = trim((string) $value);
    if (($required && $text === '') || preg_match('//u', $text) !== 1 || strlen($text) > $maximum) {
        throw new InvalidArgumentException('Enter valid ' . $label . ' (maximum ' . $maximum . ' characters).');
    }
    return $text;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
    if ($staffId === false || $staffId < 1) {
        flash('error', 'Choose a valid school staff record.');
        redirect('staff_employment.php');
    }
    try {
        if ($action === 'save_position') {
            $position = $validateText($_POST['position_title'] ?? '', 120, 'a position title', false);
            $reason = $validateText($_POST['reason'] ?? '', 1000, 'a reason');
            db()->beginTransaction();
            $staffStmt = db()->prepare(
                'SELECT id, employment_status, position_title FROM school_staff
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $staffStmt->execute([$staffId, $schoolId]);
            $staff = $staffStmt->fetch();
            if (!is_array($staff)) {
                throw new RuntimeException('The staff record was not found in this school.');
            }
            if ((string) $staff['employment_status'] !== 'active') {
                throw new RuntimeException('Only active employees can have their position changed.');
            }
            $newPosition = $position === '' ? null : $position;
            if ($newPosition === ($staff['position_title'] === null ? null : (string) $staff['position_title'])) {
                throw new InvalidArgumentException('Enter a position title that differs from the current title.');
            }
            $event = db()->prepare(
                'INSERT INTO school_staff_employment_events
                 (school_id, staff_id, staff_name_snapshot, employee_number_snapshot,
                  position_title_snapshot, previous_position_title, new_position_title,
                  event_type, previous_status, new_status, effective_on,
                  reason, remarks, event_state, authorized_by, processed_at)
                 SELECT ?, staff.id, account.full_name, staff.employee_number, staff.position_title,
                        staff.position_title, ?, "position_changed",
                        staff.employment_status, staff.employment_status, ?, ?, ?,
                        "applied", ?, UTC_TIMESTAMP()
                 FROM school_staff staff JOIN users account ON account.id = staff.user_id
                 WHERE staff.id = ? AND staff.school_id = ?'
            );
            $event->execute([$schoolId, $newPosition, $today, $reason, null,
                (int) $user['id'], $staffId, $schoolId]);
            db()->prepare(
                'UPDATE school_staff SET position_title = ? WHERE id = ? AND school_id = ?'
            )->execute([$newPosition, $staffId, $schoolId]);
            audit((int) $user['id'], 'school_staff_position_changed', 'school_id=' . $schoolId . ';staff_id=' . $staffId);
            db()->commit();
            flash('success', 'Position change saved to the permanent employment history.');
        } elseif ($action === 'change_status') {
            $newStatus = (string) ($_POST['new_status'] ?? '');
            if (!in_array($newStatus, ['suspended', 'terminated', 'resigned', 'retired', 'transferred'], true)) {
                throw new InvalidArgumentException('Choose a valid employment status.');
            }
            $effectiveOn = $validDate($_POST['effective_on'] ?? '');
            $transition = school_staff_employment_transition($newStatus, $effectiveOn, $today);
            $reason = $validateText($_POST['reason'] ?? '', 1000, 'a reason');
            $remarks = $validateText($_POST['remarks'] ?? '', 2000, 'remarks', false);
            $future = $transition['current_status'] === 'termination_scheduled';
            $submittedPlan = $_POST['replacement'] ?? [];
            if (!is_array($submittedPlan)) {
                throw new InvalidArgumentException('The replacement selections are invalid.');
            }
            $replacementPlan = ['class_teacher' => [], 'subject_teacher' => [], 'administrative' => []];

            db()->beginTransaction();
            $staffStmt = db()->prepare(
                'SELECT staff.id, staff.user_id, staff.status, staff.employment_status,
                        staff.employee_number, staff.position_title, account.full_name
                 FROM school_staff staff
                 JOIN users account ON account.id = staff.user_id
                 WHERE staff.id = ? AND staff.school_id = ? FOR UPDATE'
            );
            $staffStmt->execute([$staffId, $schoolId]);
            $staff = $staffStmt->fetch();
            if (!is_array($staff) || (string) $staff['status'] !== 'active'
                || (string) $staff['employment_status'] !== 'active') {
                throw new RuntimeException('Only a currently active employee can be suspended or exited.');
            }
            $responsibilities = school_staff_employment_responsibilities($schoolId, $staff);
            $activeRolesStmt = db()->prepare(
                'SELECT role_name FROM school_memberships
                 WHERE school_id = ? AND user_id = ? AND status = "active"
                   AND role_name NOT IN ("school_parent", "school_student")
                 ORDER BY role_name'
            );
            $activeRolesStmt->execute([$schoolId, (int) $staff['user_id']]);
            $activeRoles = array_map('strval', $activeRolesStmt->fetchAll(PDO::FETCH_COLUMN));
            if (in_array('school_proprietor', $activeRoles, true)) {
                throw new RuntimeException('The designated Proprietor cannot be removed through staff employment management. Transfer school ownership through the existing ownership workflow first.');
            }
            $pending = db()->prepare(
                'SELECT id FROM school_staff_employment_events
                 WHERE school_id = ? AND staff_id = ? AND event_state = "scheduled"
                 LIMIT 1 FOR UPDATE'
            );
            $pending->execute([$schoolId, $staffId]);
            if ($pending->fetchColumn()) {
                throw new RuntimeException('This employee already has a scheduled employment change. Cancel it before creating another.');
            }

            foreach ($responsibilities as $responsibility) {
                $type = (string) $responsibility['type'];
                if (!isset($replacementPlan[$type])) {
                    continue;
                }
                $group = $submittedPlan[$type] ?? [];
                if (!is_array($group)) {
                    throw new InvalidArgumentException('Replacement selections must be valid lists.');
                }
                $value = $group[(string) $responsibility['key']] ?? null;
                if ($value === null || $value === '' || $value === '0' || $value === 0) {
                    $replacementPlan[$type][(string) $responsibility['key']] = null;
                    continue;
                }
                $replacement = school_staff_employment_replacement(
                    db(),
                    $schoolId,
                    $value,
                    $type === 'class_teacher' ? 'class_teacher'
                        : ($type === 'administrative' ? 'administrative' : 'subject_teacher')
                );
                if (!$replacement || (int) $replacement['id'] === $staffId) {
                    throw new InvalidArgumentException('Choose an active, eligible replacement from this school.');
                }
                $replacementPlan[$type][(string) $responsibility['key']] = (int) $replacement['id'];
            }

            $eventType = $transition['event_type'];
            $eventStmt = db()->prepare(
                'INSERT INTO school_staff_employment_events
                 (school_id, staff_id, staff_name_snapshot, employee_number_snapshot,
                  position_title_snapshot, event_type, previous_status, new_status, effective_on,
                  reason, remarks, prior_roles_json, replacement_plan_json, event_state, authorized_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "scheduled", ?)'
            );
            $eventStmt->execute([
                $schoolId,
                $staffId,
                (string) $staff['full_name'],
                $staff['employee_number'],
                $staff['position_title'],
                $eventType,
                (string) $staff['employment_status'],
                $transition['new_status'],
                $effectiveOn,
                $reason,
                $remarks === '' ? null : $remarks,
                json_encode($activeRoles, JSON_THROW_ON_ERROR),
                json_encode($replacementPlan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                (int) $user['id'],
            ]);
            $eventId = (int) db()->lastInsertId();
            if ($future) {
                db()->prepare(
                    'UPDATE school_staff SET employment_status = ?, employment_effective_on = ?
                     WHERE id = ? AND school_id = ?'
                )->execute([$transition['current_status'], $effectiveOn, $staffId, $schoolId]);
                audit((int) $user['id'], 'school_staff_employment_scheduled',
                    'school_id=' . $schoolId . ';staff_id=' . $staffId . ';event_id=' . $eventId . ';effective_on=' . $effectiveOn);
            } else {
                school_staff_employment_apply_event(db(), $eventId);
            }
            db()->commit();
            flash('success', $future
                ? 'The employment change is scheduled. School access and role changes take effect on the selected date.'
                : 'The employment change took effect today. Previous attendance, payroll, and employment history were retained.');
        } elseif ($action === 'cancel_scheduled') {
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            if ($eventId === false || $eventId < 1) {
                throw new InvalidArgumentException('Choose a valid scheduled event.');
            }
            db()->beginTransaction();
            $eventStmt = db()->prepare(
                'SELECT id, new_status FROM school_staff_employment_events
                 WHERE id = ? AND school_id = ? AND staff_id = ? AND event_state = "scheduled"
                 FOR UPDATE'
            );
            $eventStmt->execute([$eventId, $schoolId, $staffId]);
            $event = $eventStmt->fetch();
            if (!is_array($event)) {
                throw new RuntimeException('The scheduled employment event was not found or was already applied.');
            }
            db()->prepare(
                'UPDATE school_staff_employment_events
                 SET event_state = "cancelled", processed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND event_state = "scheduled"'
            )->execute([$eventId]);
            db()->prepare(
                'UPDATE school_staff SET employment_status = "active", employment_effective_on = NULL
                 WHERE id = ? AND school_id = ? AND employment_status = "termination_scheduled"'
            )->execute([$staffId, $schoolId]);
            $cancelEvent = db()->prepare(
                'INSERT INTO school_staff_employment_events
                 (school_id, staff_id, staff_name_snapshot, employee_number_snapshot,
                  position_title_snapshot, event_type, previous_status, new_status, effective_on,
                  reason, remarks, event_state, authorized_by, processed_at)
                 SELECT ?, staff.id, account.full_name, staff.employee_number, staff.position_title,
                        "schedule_cancelled", "termination_scheduled", "active", ?, ?,
                        ?, "applied", ?, UTC_TIMESTAMP()
                 FROM school_staff staff JOIN users account ON account.id = staff.user_id
                 WHERE staff.id = ? AND staff.school_id = ?'
            );
            $cancelEvent->execute([$schoolId, $today, 'Scheduled ' . (string) $event['new_status'] . ' employment change cancelled.',
                null, (int) $user['id'], $staffId, $schoolId]);
            audit((int) $user['id'], 'school_staff_employment_schedule_cancelled',
                'school_id=' . $schoolId . ';staff_id=' . $staffId . ';event_id=' . $eventId);
            db()->commit();
            flash('success', 'The scheduled employment change was cancelled and retained in history.');
        } elseif ($action === 'reinstate') {
            $reason = $validateText($_POST['reason'] ?? '', 1000, 'a reinstatement reason');
            db()->beginTransaction();
            $staffStmt = db()->prepare(
                'SELECT staff.id, staff.user_id, staff.status, staff.employment_status,
                        staff.employee_number, staff.position_title, account.full_name
                 FROM school_staff staff
                 JOIN users account ON account.id = staff.user_id
                 WHERE staff.id = ? AND staff.school_id = ? FOR UPDATE'
            );
            $staffStmt->execute([$staffId, $schoolId]);
            $staff = $staffStmt->fetch();
            if (!is_array($staff) || (string) $staff['employment_status'] !== 'suspended') {
                throw new RuntimeException('Only a suspended employee can be reinstated. Ended employment requires a separately reviewed correction.');
            }
            $rolesStmt = db()->prepare(
                'SELECT prior_roles_json FROM school_staff_employment_events
                 WHERE school_id = ? AND staff_id = ? AND new_status = "suspended"
                   AND event_state = "applied"
                 ORDER BY effective_on DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $rolesStmt->execute([$schoolId, $staffId]);
            $roles = json_decode((string) ($rolesStmt->fetchColumn() ?: '[]'), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($roles)) {
                throw new RuntimeException('The previous school roles could not be read safely.');
            }
            $roles = array_values(array_filter($roles, static fn(mixed $role): bool =>
                is_string($role) && preg_match('/^[a-z_]{3,50}$/D', $role) === 1
                && !in_array($role, ['school_parent', 'school_student', 'school_bursar'], true)
            ));
            if ($roles !== []) {
                $crossSchool = db()->prepare(
                    'SELECT 1 FROM school_memberships
                     WHERE user_id = ? AND status = "active" AND school_id <> ? LIMIT 1 FOR UPDATE'
                );
                $crossSchool->execute([(int) $staff['user_id'], $schoolId]);
                if ($crossSchool->fetchColumn()) {
                    throw new RuntimeException('The employee now has an active assignment elsewhere. Review school membership before reinstating access.');
                }
                $placeholders = implode(',', array_fill(0, count($roles), '?'));
                db()->prepare(
                    'UPDATE school_memberships SET status = "active"
                     WHERE school_id = ? AND user_id = ? AND status = "disabled"
                       AND role_name IN (' . $placeholders . ')'
                )->execute(array_merge([$schoolId, (int) $staff['user_id']], $roles));
            }
            db()->prepare(
                'UPDATE school_staff SET status = "active", employment_status = "active",
                    employment_effective_on = NULL
                 WHERE id = ? AND school_id = ?'
            )->execute([$staffId, $schoolId]);
            $event = db()->prepare(
                'INSERT INTO school_staff_employment_events
                 (school_id, staff_id, staff_name_snapshot, employee_number_snapshot,
                  position_title_snapshot, event_type, previous_status, new_status, effective_on,
                  reason, prior_roles_json, event_state, authorized_by, processed_at)
                 VALUES (?, ?, ?, ?, ?, "reinstated", "suspended", "active", ?, ?, ?, "applied", ?, UTC_TIMESTAMP())'
            );
            $event->execute([
                $schoolId, $staffId, (string) $staff['full_name'], $staff['employee_number'],
                $staff['position_title'], $today, $reason, json_encode($roles, JSON_THROW_ON_ERROR), (int) $user['id'],
            ]);
            audit((int) $user['id'], 'school_staff_reinstated', 'school_id=' . $schoolId . ';staff_id=' . $staffId);
            db()->commit();
            flash('success', 'The employee was reinstated with their prior eligible school roles. Their existing personal and employment records were retained.');
        } else {
            throw new InvalidArgumentException('Choose a valid employment action.');
        }
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Staff employment action failed: ' . $exception->getMessage());
        flash('error', 'The employment action could not be completed. No uncommitted changes were retained.');
    }
    redirect('staff_employment.php?staff_id=' . (int) $staffId);
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = substr($search, 0, 100);
$staffSql = 'SELECT staff.id, staff.user_id, staff.employee_number, staff.appointed_on,
                    staff.position_title, staff.status, staff.employment_status,
                    staff.employment_effective_on, account.full_name, account.email,
                    account.status AS account_status,
                    GROUP_CONCAT(DISTINCT membership.role_name ORDER BY membership.role_name SEPARATOR ", ") AS roles
             FROM school_staff staff
             JOIN users account ON account.id = staff.user_id
             LEFT JOIN school_memberships membership
               ON membership.school_id = staff.school_id AND membership.user_id = staff.user_id
             WHERE staff.school_id = ?';
$staffParams = [$schoolId];
if ($search !== '') {
    $staffSql .= ' AND (account.full_name LIKE ? OR account.email LIKE ? OR staff.employee_number LIKE ? OR staff.position_title LIKE ?)';
    array_push($staffParams, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}
$staffSql .= ' GROUP BY staff.id, staff.user_id, staff.employee_number, staff.appointed_on,
               staff.position_title, staff.status, staff.employment_status, staff.employment_effective_on,
               account.full_name, account.email, account.status
               ORDER BY CASE WHEN staff.employment_status IN ("active","termination_scheduled") THEN 0 ELSE 1 END,
                        account.full_name';
$staffStmt = db()->prepare($staffSql);
$staffStmt->execute($staffParams);
$staffRows = $staffStmt->fetchAll();

$selectedStaffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT);
$selectedStaff = null;
if ($selectedStaffId !== false && $selectedStaffId > 0) {
    foreach ($staffRows as $staffRow) {
        if ((int) $staffRow['id'] === (int) $selectedStaffId) {
            $selectedStaff = $staffRow;
            break;
        }
    }
}
$responsibilities = $selectedStaff ? school_staff_employment_responsibilities($schoolId, $selectedStaff) : [];
$replacementCandidatesStmt = db()->prepare(
    'SELECT staff.id, staff.position_title, account.full_name,
            GROUP_CONCAT(DISTINCT membership.role_name ORDER BY membership.role_name SEPARATOR ", ") AS roles
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
     JOIN school_memberships membership
       ON membership.school_id = staff.school_id AND membership.user_id = staff.user_id
      AND membership.status = "active"
     WHERE staff.school_id = ? AND staff.status = "active" AND staff.employment_status = "active"
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
       AND membership.role_name NOT IN ("school_parent", "school_student", "school_bursar")
     GROUP BY staff.id, staff.position_title, account.full_name
     ORDER BY account.full_name'
);
$replacementCandidatesStmt->execute([$schoolId]);
$replacementCandidates = $replacementCandidatesStmt->fetchAll();

$employmentHistory = [];
$responsibilityHistory = [];
$scheduledEvents = [];
if ($selectedStaff) {
    $historyStmt = db()->prepare(
        'SELECT event.*, actor.full_name AS authorizing_name
         FROM school_staff_employment_events event
         LEFT JOIN users actor ON actor.id = event.authorized_by
         WHERE event.school_id = ? AND event.staff_id = ?
         ORDER BY event.effective_on DESC, event.id DESC LIMIT 100'
    );
    $historyStmt->execute([$schoolId, (int) $selectedStaff['id']]);
    $employmentHistory = $historyStmt->fetchAll();
    $responsibilityStmt = db()->prepare(
        'SELECT history.*, event.event_type, event.new_status, event.staff_name_snapshot
         FROM school_staff_responsibility_history history
         JOIN school_staff_employment_events event
           ON event.id = history.employment_event_id AND event.school_id = history.school_id
         WHERE history.school_id = ? AND history.former_staff_id = ?
         ORDER BY history.effective_on DESC, history.id DESC LIMIT 100'
    );
    $responsibilityStmt->execute([$schoolId, (int) $selectedStaff['id']]);
    $responsibilityHistory = $responsibilityStmt->fetchAll();
    $scheduledStmt = db()->prepare(
        'SELECT id, event_type, new_status, effective_on, reason
         FROM school_staff_employment_events
         WHERE school_id = ? AND staff_id = ? AND event_state = "scheduled"
         ORDER BY effective_on, id'
    );
    $scheduledStmt->execute([$schoolId, (int) $selectedStaff['id']]);
    $scheduledEvents = $scheduledStmt->fetchAll();
}

render_header('Staff employment and history');
?>
<section class="panel">
    <p class="eyebrow">SCHOOL PERSONNEL</p>
    <h1>Employment management and history</h1>
    <p class="muted">Employment actions retain the staff record, attendance, payroll, and prior responsibility history. End dates may be scheduled; the school applies due changes before the next authenticated school request.</p>
    <a class="button button-quiet" href="staff.php">Back to Staff Register</a>
</section>

<section class="panel top-gap">
    <h2>Staff employment register</h2>
    <form method="get" class="inline-form">
        <label for="employment-search">Search by name, email, employee number, or position</label>
        <input id="employment-search" name="q" value="<?= e($search) ?>" maxlength="100">
        <button class="button" type="submit">Search</button>
    </form>
    <?php if ($staffRows === []): ?>
        <p class="muted">No matching staff employment records were found.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Staff</th><th>Employee no.</th><th>Position / roles</th><th>Employment</th><th>Effective</th><th>History</th></tr></thead>
            <tbody><?php foreach ($staffRows as $staffRow): ?>
                <tr>
                    <td><strong><?= e((string) $staffRow['full_name']) ?></strong><br><small><?= e((string) $staffRow['email']) ?></small></td>
                    <td><?= e((string) ($staffRow['employee_number'] ?: '—')) ?></td>
                    <td><?= e((string) ($staffRow['position_title'] ?: 'Position not recorded')) ?><br><small><?= e((string) ($staffRow['roles'] ?: 'No active school roles')) ?></small></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $staffRow['employment_status']))) ?></td>
                    <td><?= e((string) ($staffRow['employment_effective_on'] ?: '—')) ?></td>
                    <td><a class="button button-small" href="staff_employment.php?staff_id=<?= (int) $staffRow['id'] ?>">View / manage</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php if ($selectedStaff): ?>
<section class="panel top-gap" id="selected-employment">
    <h2><?= e((string) $selectedStaff['full_name']) ?> — employment record</h2>
    <p class="muted">Staff ID <?= (int) $selectedStaff['id'] ?> · <?= e((string) ($selectedStaff['employee_number'] ?: 'Employee number not recorded')) ?> · Appointed <?= e((string) ($selectedStaff['appointed_on'] ?: 'date not recorded')) ?></p>
    <?php if ($selectedStaff['employment_status'] === 'active'): ?>
        <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_position"><input type="hidden" name="staff_id" value="<?= (int) $selectedStaff['id'] ?>">
            <label for="position-title">Position title</label>
            <input id="position-title" name="position_title" value="<?= e((string) ($selectedStaff['position_title'] ?? '')) ?>" maxlength="120">
            <label for="position-reason">Reason</label><input id="position-reason" name="reason" maxlength="1000" required>
            <button class="button" type="submit">Save position history</button>
        </form>
    <?php endif; ?>
    <?php if ($scheduledEvents !== []): ?>
        <h3>Scheduled employment changes</h3>
        <?php foreach ($scheduledEvents as $scheduled): ?>
            <div class="notice notice-warning">
                <p><?= e(ucwords(str_replace('_', ' ', (string) $scheduled['new_status']))) ?> effective <?= e((string) $scheduled['effective_on']) ?>: <?= e((string) $scheduled['reason']) ?></p>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="cancel_scheduled"><input type="hidden" name="staff_id" value="<?= (int) $selectedStaff['id'] ?>"><input type="hidden" name="event_id" value="<?= (int) $scheduled['id'] ?>">
                    <button class="button" type="submit" data-confirm="Cancel this scheduled employment change? The cancellation will remain in history.">Cancel scheduled change</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($selectedStaff['employment_status'] === 'suspended'): ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="reinstate"><input type="hidden" name="staff_id" value="<?= (int) $selectedStaff['id'] ?>">
            <label for="reinstate-reason">Reinstatement reason</label><textarea id="reinstate-reason" name="reason" minlength="5" maxlength="1000" required></textarea>
            <button class="button button-primary" type="submit" data-confirm="Reinstate the suspended employee and restore the previously active school roles?">Reinstate employee</button>
        </form>
    <?php elseif ($selectedStaff['employment_status'] === 'active'): ?>
        <h3>Current responsibilities</h3>
        <?php if ($responsibilities === []): ?><p class="muted">No current class, subject, or administrative assignments were found.</p>
        <?php else: ?>
            <p class="muted">Choose an active replacement for each responsibility, or leave it unassigned. Unassigned responsibilities are retained in the attention list. Published timetable versions are not edited in place; a replacement timetable must be published for the effective date.</p>
            <ul><?php foreach ($responsibilities as $responsibility): ?>
                <li><?= e((string) $responsibility['label']) ?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="staff_id" value="<?= (int) $selectedStaff['id'] ?>">
            <label for="employment-status">Employment action</label>
            <select id="employment-status" name="new_status" required>
                <option value="">Choose action</option><option value="suspended">Suspend</option><option value="terminated">Terminate</option><option value="resigned">Record resignation</option><option value="retired">Record retirement</option><option value="transferred">Record transfer out</option>
            </select>
            <label for="employment-effective">Effective date</label><input id="employment-effective" type="date" name="effective_on" min="<?= e($today) ?>" value="<?= e($today) ?>" required>
            <label for="employment-reason">Reason for action</label><textarea id="employment-reason" name="reason" minlength="5" maxlength="1000" required></textarea>
            <label for="employment-remarks">Remarks or supporting-document reference (optional)</label><textarea id="employment-remarks" name="remarks" maxlength="2000"></textarea>
            <?php if ($responsibilities !== []): ?>
                <h3>Replacement selection</h3>
                <?php foreach ($responsibilities as $responsibility): ?>
                    <?php if ($responsibility['type'] === 'timetable'): ?>
                        <p class="notice notice-warning"><?= e((string) $responsibility['label']) ?> — resolve through a new effective-dated timetable version.</p>
                        <?php continue; ?>
                    <?php endif; ?>
                    <label>
                        <?= e((string) $responsibility['label']) ?>
                        <select name="replacement[<?= e((string) $responsibility['type']) ?>][<?= e((string) $responsibility['key']) ?>]">
                            <option value="0">Leave unassigned — flag for follow-up</option>
                            <?php foreach ($replacementCandidates as $candidate): ?>
                                <?php
                                $roleMatch = $responsibility['type'] === 'class_teacher'
                                    ? str_contains((string) $candidate['roles'], 'class_teacher')
                                    : ($responsibility['type'] === 'subject_teacher'
                                        ? (str_contains((string) $candidate['roles'], 'class_teacher') || str_contains((string) $candidate['roles'], 'subject_teacher'))
                                        : true);
                                ?>
                                <?php if ($roleMatch && (int) $candidate['id'] !== (int) $selectedStaff['id']): ?>
                                    <option value="<?= (int) $candidate['id'] ?>"><?= e((string) $candidate['full_name']) ?><?= $candidate['position_title'] ? ' · ' . e((string) $candidate['position_title']) : '' ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
            <button class="button button-danger" type="submit" data-confirm="Record this employment action? Access will be restricted on its effective date. Existing attendance, payroll, and academic records will be retained.">Record employment action</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel top-gap">
    <h2>Employment history</h2>
    <?php if ($employmentHistory === []): ?><p class="muted">No employment events have been recorded.</p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Event</th><th>Previous → new status</th><th>Position title</th><th>Effective</th><th>Reason / remarks</th><th>Authorized by / recorded</th></tr></thead>
        <tbody><?php foreach ($employmentHistory as $event): ?>
            <tr>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $event['event_type']))) ?></td>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $event['previous_status']))) ?> → <?= e(ucwords(str_replace('_', ' ', (string) $event['new_status']))) ?></td>
                <td><?= e((string) ($event['previous_position_title'] ?: '—')) ?> → <?= e((string) ($event['new_position_title'] ?: '—')) ?></td>
                <td><?= e((string) $event['effective_on']) ?></td>
                <td><?= nl2br(e((string) $event['reason'])) ?><?= $event['remarks'] ? '<br>' . nl2br(e((string) $event['remarks'])) : '' ?></td>
                <td><?= e((string) ($event['authorizing_name'] ?: 'Unavailable')) ?><br><small><?= e((string) $event['authorized_at']) ?></small></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</section>

<section class="panel top-gap">
    <h2>Responsibility history and follow-up</h2>
    <?php if ($responsibilityHistory === []): ?><p class="muted">No responsibility transitions have been recorded.</p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Responsibility</th><th>Details</th><th>Effective</th><th>Follow-up</th></tr></thead>
        <tbody><?php foreach ($responsibilityHistory as $history): $details = json_decode((string) $history['responsibility_json'], true); ?>
            <tr>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $history['responsibility_type']))) ?><br><small><?= e((string) $history['responsibility_key']) ?></small></td>
                <td><?= e(is_array($details) ? implode(' · ', array_filter(array_map(
                    static fn(mixed $value): string => is_scalar($value) ? (string) $value : '',
                    $details
                ))) : 'Details unavailable') ?></td>
                <td><?= e((string) $history['effective_on']) ?></td>
                <td><?= (int) $history['attention_required'] === 1 ? 'Needs administrative follow-up' : 'Reassigned' ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php render_footer(); ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/school_staff_employment.php';

function school_subscription_trial_expiration(DateTimeImmutable $activatedAt): DateTimeImmutable
{
    return $activatedAt
        ->setTimezone(new DateTimeZone('UTC'))
        ->add(new DateInterval('P14D'));
}

function school_class_levels(int $schoolId, bool $includeArchived = false): array
{
    if ($schoolId < 1) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT class_level.name FROM school_class_levels class_level
         WHERE class_level.school_id = ?'
            . ($includeArchived ? '' : ' AND (class_level.status = "active" OR EXISTS (
                SELECT 1 FROM school_classes class
                WHERE class.school_id = class_level.school_id
                  AND class.class_level_id = class_level.id AND class.status = "active"
            ))') . '
         ORDER BY class_level.display_order, class_level.name'
    );
    $stmt->execute([$schoolId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function school_class_label(array $class): string
{
    $level = trim((string) ($class['level'] ?? ''));
    if ($level === '') {
        return 'Class level needs review';
    }

    $section = trim((string) ($class['section'] ?? ''));
    return $section === '' ? $level : $level . ' · ' . $section;
}

function school_class_level_name_is_valid(string $name): bool
{
    return preg_match('/^.{1,80}$/us', $name) === 1
        && preg_match('/[\x00-\x1F\x7F]/u', $name) !== 1;
}

function school_class_progression_would_cycle(array $nextLevelById, int $sourceLevelId, int $targetLevelId): bool
{
    if ($sourceLevelId < 1 || $targetLevelId < 1) {
        throw new InvalidArgumentException('Choose valid class levels for progression.');
    }

    $visited = [];
    $currentLevelId = $targetLevelId;
    while ($currentLevelId > 0) {
        if ($currentLevelId === $sourceLevelId || isset($visited[$currentLevelId])) {
            return true;
        }
        $visited[$currentLevelId] = true;
        $currentLevelId = (int) ($nextLevelById[$currentLevelId] ?? 0);
    }

    return false;
}

function school_class_category_is_valid(string $category): bool
{
    return preg_match('/^.{1,60}$/us', $category) === 1
        && preg_match('/[\x00-\x1F\x7F]/u', $category) !== 1;
}

function school_class_academic_stage_is_valid(string $stage): bool
{
    return in_array($stage, ['primary', 'secondary', 'other'], true);
}

function school_class_capacity_allows(?int $capacity, int $activeStudentCount): bool
{
    if ($activeStudentCount < 0 || ($capacity !== null && $capacity < 1)) {
        throw new InvalidArgumentException('A configured capacity must be positive and student counts must be non-negative.');
    }

    return $capacity === null || $activeStudentCount < $capacity;
}

function school_class_lock_for_student_assignment(
    PDO $pdo,
    int $schoolId,
    int $classId,
    ?int $excludeStudentId = null
): array {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Class capacity must be checked inside the assignment transaction.');
    }

    $classStmt = $pdo->prepare(
        'SELECT class.id, class.level, class.section, class.academic_year,
                class.capacity, class.status, class_level.status AS level_status
         FROM school_classes class
         JOIN school_class_levels class_level
           ON class_level.id = class.class_level_id AND class_level.school_id = class.school_id
         WHERE class.id = ? AND class.school_id = ?
         FOR UPDATE'
    );
    $classStmt->execute([$classId, $schoolId]);
    $class = $classStmt->fetch();
    if (!is_array($class) || (string) $class['status'] !== 'active'
        || (string) $class['level_status'] !== 'active') {
        throw new InvalidArgumentException('Choose an active class belonging to this school.');
    }

    $countSql = 'SELECT COUNT(*) FROM school_students
                 WHERE school_id = ? AND class_id = ? AND school_status = "active"';
    $countParams = [$schoolId, $classId];
    if ($excludeStudentId !== null && $excludeStudentId > 0) {
        $countSql .= ' AND id <> ?';
        $countParams[] = $excludeStudentId;
    }
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $activeStudents = (int) $countStmt->fetchColumn();
    $capacity = $class['capacity'] === null ? null : (int) $class['capacity'];
    if (!school_class_capacity_allows($capacity, $activeStudents)) {
        throw new RuntimeException('The selected class has reached its configured capacity.');
    }

    return $class;
}

function school_class_has_references(PDO $pdo, int $classId): bool
{
    if ($classId < 1) {
        throw new InvalidArgumentException('Choose a valid school class.');
    }

    $referenceStmt = $pdo->query(
        'SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = "school_classes"
         ORDER BY TABLE_NAME, COLUMN_NAME'
    );
    foreach ($referenceStmt->fetchAll(PDO::FETCH_ASSOC) as $reference) {
        $table = (string) ($reference['TABLE_NAME'] ?? '');
        $column = (string) ($reference['COLUMN_NAME'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            throw new RuntimeException('A class reference could not be checked safely.');
        }
        $countStmt = $pdo->prepare(
            'SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` = ? LIMIT 1'
        );
        $countStmt->execute([$classId]);
        if ($countStmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

function school_has_proprietor_email_column(): bool
{
    static $available;
    if ($available !== null) {
        return $available;
    }

    $column = db()->query("SHOW COLUMNS FROM schools LIKE 'proprietor_email'")->fetch();
    $available = $column !== false;
    return $available;
}

function school_has_proprietor_phone_column(): bool
{
    static $available;
    if ($available !== null) {
        return $available;
    }

    $column = db()->query("SHOW COLUMNS FROM schools LIKE 'proprietor_phone'")->fetch();
    $available = $column !== false;
    return $available;
}

function school_academic_year_label(string $academicYear): string
{
    if (preg_match('/^(\d{4})$/', $academicYear, $matches) === 1) {
        return $matches[1] . '/' . ((int) $matches[1] + 1);
    }

    return $academicYear;
}

function school_user_memberships(int $userId = 0): array
{
    $userId = $userId > 0 ? $userId : (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT sm.id, sm.school_id, sm.user_id, sm.role_name, sm.status,
                s.name, s.short_name, s.logo_path, s.status AS school_status,
                s.region, s.district
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id
         WHERE sm.user_id = ? AND sm.status IN ('active', 'pending')
           AND sm.role_name <> 'school_bursar' AND s.deleted_at IS NULL
         UNION ALL
         SELECT srr.id, srr.school_id, srr.user_id, srr.role_name, 'pending' AS status,
                s.name, s.short_name, s.logo_path, s.status AS school_status,
                s.region, s.district
         FROM school_role_requests srr
         JOIN schools s ON s.id = srr.school_id
         WHERE srr.user_id = ? AND srr.status = 'pending'
           AND srr.role_name <> 'school_bursar' AND s.deleted_at IS NULL
         ORDER BY name ASC, role_name ASC"
    );
    $stmt->execute([$userId, $userId]);

    $rows = $stmt->fetchAll();
    $memberships = [];
    foreach ($rows as $row) {
        $memberships[] = [
            'id' => (int) $row['id'],
            'school_id' => (int) $row['school_id'],
            'user_id' => (int) $row['user_id'],
            'role_name' => (string) $row['role_name'],
            'status' => (string) $row['status'],
            'name' => (string) $row['name'],
            'short_name' => (string) $row['short_name'],
            'logo_path' => (string) ($row['logo_path'] ?? ''),
            'school_status' => (string) $row['school_status'],
            'region' => (string) $row['region'],
            'district' => (string) $row['district'],
        ];
    }

    return $memberships;
}

function school_user_has_access(int $userId, int $schoolId, string $roleName): bool
{
    if ($roleName === 'school_bursar') {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1
         FROM school_memberships sm
         WHERE sm.user_id = ? AND sm.school_id = ? AND sm.role_name = ? AND sm.status = ?
           AND (SELECT COUNT(DISTINCT school_id) FROM school_memberships
                WHERE user_id = sm.user_id AND status = ? AND role_name <> ?) = 1
         LIMIT 1'
    );
    $stmt->execute([$userId, $schoolId, $roleName, 'active', 'active', 'school_bursar']);
    return (bool) $stmt->fetchColumn();
}

function school_assign_designated_proprietor(PDO $pdo, int $schoolId, int $userId, ?int $actorUserId = null): bool
{
    $schoolStmt = $pdo->prepare(
        'SELECT proprietor_email FROM schools
         WHERE id = ? AND deleted_at IS NULL
         FOR UPDATE'
    );
    $schoolStmt->execute([$schoolId]);
    $proprietorEmail = $schoolStmt->fetchColumn();
    if (!is_string($proprietorEmail) || $proprietorEmail === '') {
        return false;
    }

    $userStmt = $pdo->prepare(
        'SELECT email, status, email_verified_at
         FROM users WHERE id = ? FOR UPDATE'
    );
    $userStmt->execute([$userId]);
    $designatedUser = $userStmt->fetch();
    if (!$designatedUser
        || $designatedUser['status'] !== 'active'
        || $designatedUser['email_verified_at'] === null
        || !hash_equals(strtolower($proprietorEmail), strtolower(trim((string) $designatedUser['email'])))) {
        return false;
    }

    $existingProprietorStmt = $pdo->prepare(
        'SELECT 1 FROM school_memberships
         WHERE school_id = ? AND user_id = ? AND role_name = "school_proprietor" AND status = "active"
         LIMIT 1'
    );
    $existingProprietorStmt->execute([$schoolId, $userId]);
    if ($existingProprietorStmt->fetchColumn()) {
        return true;
    }

    $authorityStmt = $pdo->prepare(
        'SELECT 1 FROM school_memberships
         WHERE school_id = ? AND status = "active"
           AND role_name IN ("school_proprietor", "school_headmaster")
         LIMIT 1 FOR UPDATE'
    );
    $authorityStmt->execute([$schoolId]);
    if ($authorityStmt->fetchColumn()) {
        throw new RuntimeException('This school already has an active school authority.');
    }

    $otherSchoolStmt = $pdo->prepare(
        'SELECT 1 FROM school_memberships
         WHERE user_id = ? AND status = "active" AND school_id <> ?
           AND role_name <> "school_bursar"
         LIMIT 1 FOR UPDATE'
    );
    $otherSchoolStmt->execute([$userId, $schoolId]);
    if ($otherSchoolStmt->fetchColumn()) {
        throw new RuntimeException('The designated account already has active access to another school. Transfer that assignment before designating this account.');
    }

    $membershipStmt = $pdo->prepare(
        'INSERT INTO school_memberships (school_id, user_id, role_name, status, approved_by)
         VALUES (?, ?, "school_proprietor", "active", NULL)
         ON DUPLICATE KEY UPDATE status = "active", approved_by = NULL'
    );
    $membershipStmt->execute([$schoolId, $userId]);
    $staffStmt = $pdo->prepare(
        'INSERT INTO school_staff (school_id, user_id, role_name, status)
         VALUES (?, ?, "school_proprietor", "active")
         ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), status = "active"'
    );
    $staffStmt->execute([$schoolId, $userId]);
    audit($actorUserId ?? $userId, 'school_first_proprietor_activated',
        'school_id=' . $schoolId . ';proprietor_user_id=' . $userId);
    return true;
}

function school_designate_proprietor_account(
    PDO $pdo,
    int $schoolId,
    string $fullName,
    string $email,
    string $phone,
    int $actorUserId
): array {
    if (!school_has_proprietor_email_column() || !school_has_proprietor_phone_column()) {
        throw new RuntimeException('Apply the school Proprietor setup migrations before creating the school portal.');
    }

    $schoolStmt = $pdo->prepare(
        'SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
    );
    $schoolStmt->execute([$schoolId]);
    if (!$schoolStmt->fetchColumn()) {
        throw new RuntimeException('The school is no longer available for Proprietor setup.');
    }

    $otherDesignationStmt = $pdo->prepare(
        'SELECT id FROM schools
         WHERE LOWER(TRIM(proprietor_email)) = ? AND id <> ?
           AND deleted_at IS NULL AND status IN ("pending", "active")
         LIMIT 1 FOR UPDATE'
    );
    $otherDesignationStmt->execute([$email, $schoolId]);
    if ($otherDesignationStmt->fetchColumn()) {
        throw new RuntimeException('This email is already designated as the Proprietor of another school.');
    }

    $authorityStmt = $pdo->prepare(
        'SELECT 1 FROM school_memberships
         WHERE school_id = ? AND status = "active"
           AND role_name IN ("school_proprietor", "school_headmaster")
         LIMIT 1 FOR UPDATE'
    );
    $authorityStmt->execute([$schoolId]);
    if ($authorityStmt->fetchColumn()) {
        throw new RuntimeException('This school already has an active school authority.');
    }

    $userStmt = $pdo->prepare(
        'SELECT id, email, status, email_verified_at
         FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1 FOR UPDATE'
    );
    $userStmt->execute([$email]);
    $account = $userStmt->fetch();
    $created = false;
    if (!$account) {
        $username = 'prop' . bin2hex(random_bytes(12));
        $placeholderPassword = bin2hex(random_bytes(32));
        $createUserStmt = $pdo->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, status)
             VALUES (?, ?, ?, ?, "active")'
        );
        $createUserStmt->execute([
            $fullName,
            $username,
            $email,
            make_password_hash($placeholderPassword),
        ]);
        $userId = (int) $pdo->lastInsertId();
        $created = true;
    } else {
        if ($account['status'] !== 'active') {
            throw new RuntimeException('The designated email belongs to a disabled account. Enable that account before assigning it as Proprietor.');
        }
        $userId = (int) $account['id'];
    }

    $pendingMembershipStmt = $pdo->prepare(
        'SELECT id, user_id FROM school_memberships
         WHERE school_id = ? AND role_name = "school_proprietor" AND status = "pending"
         LIMIT 1 FOR UPDATE'
    );
    $pendingMembershipStmt->execute([$schoolId]);
    $pendingMembership = $pendingMembershipStmt->fetch();
    if ($pendingMembership && (int) $pendingMembership['user_id'] !== $userId) {
        throw new RuntimeException('A different Proprietor account is already being set up for this school.');
    }

    $pdo->prepare(
        'UPDATE schools SET proprietor_name = ?, proprietor_email = ?, proprietor_phone = ? WHERE id = ?'
    )->execute([$fullName, $email, $phone, $schoolId]);

    if (!$pendingMembership) {
        $membershipStmt = $pdo->prepare(
            'INSERT INTO school_memberships (school_id, user_id, role_name, status)
             VALUES (?, ?, "school_proprietor", "pending")'
        );
        $membershipStmt->execute([$schoolId, $userId]);
    }

    $activated = false;
    if (!$created && $account['email_verified_at'] !== null) {
        $activated = school_assign_designated_proprietor($pdo, $schoolId, $userId, $actorUserId);
    }

    audit($actorUserId, 'school_first_proprietor_designated',
        'school_id=' . $schoolId . ';proprietor_user_id=' . $userId . ';new_account=' . ($created ? '1' : '0'));

    return [
        'user_id' => $userId,
        'created' => $created,
        'activated' => $activated,
        'needs_setup' => !$activated,
    ];
}

function school_user_roles_for_school(int $userId, int $schoolId): array
{
    $employmentFilter = '';
    $params = [$userId, $schoolId];
    if (school_staff_employment_schema_ready()) {
        $employmentFilter = ' AND (
            school_memberships.role_name IN ("school_parent", "school_student")
            OR NOT EXISTS (
                SELECT 1 FROM school_staff employment_staff
                WHERE employment_staff.school_id = school_memberships.school_id
                  AND employment_staff.user_id = school_memberships.user_id
                  AND employment_staff.employment_status = "termination_scheduled"
                  AND employment_staff.employment_effective_on <= ?
            )
        )';
        $params[] = date('Y-m-d');
    }
    $stmt = db()->prepare(
        'SELECT role_name FROM school_memberships
         WHERE user_id = ? AND school_id = ? AND status = "active"
           AND role_name <> "school_bursar"' . $employmentFilter . '
         ORDER BY id'
    );
    $stmt->execute($params);
    return array_values(array_filter(
        array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        static fn(string $role): bool => $role !== ''
    ));
}

function notify_school_role_reviewers(int $schoolId, int $requesterId, string $roleName): string
{
    $stmt = db()->prepare(
        'SELECT DISTINCT sm.user_id, u.email, u.full_name
         FROM school_memberships sm
         JOIN users u ON u.id = sm.user_id
         WHERE sm.school_id = ? AND sm.status = "active"
           AND sm.role_name IN ("school_proprietor", "school_headmaster")
           AND sm.user_id <> ? AND u.status = "active" AND u.email_verified_at IS NOT NULL'
    );
    $stmt->execute([$schoolId, $requesterId]);
    $reviewers = $stmt->fetchAll();
    if ($reviewers === [] && $roleName === 'school_proprietor') {
        $reviewers = [['email' => PLATFORM_SUPER_ADMIN_EMAIL, 'full_name' => 'Platform Super Admin']];
    }
    if ($reviewers === []) {
        return 'no_reviewer';
    }

    global $config;
    $reviewUrl = rtrim((string) ($config['app_url'] ?? ''), '/') . '/school_role_requests.php';
    $schoolStmt = db()->prepare('SELECT name FROM schools WHERE id = ?');
    $schoolStmt->execute([$schoolId]);
    $schoolName = (string) ($schoolStmt->fetchColumn() ?: 'the school');
    $message = 'A school account has requested the ' . role_display_name($roleName)
        . ' role at ' . $schoolName . '. Confirm the applicant email is verified, then review the applicant information and supporting evidence before making a decision.';
    if ($reviewUrl !== '') {
        $message .= ' Sign in to the school portal and open: ' . $reviewUrl;
    }

    $delivered = false;
    $requestStmt = db()->prepare(
        'SELECT id FROM school_role_requests
         WHERE school_id = ? AND user_id = ? AND role_name = ? AND status = "pending"
         ORDER BY id DESC LIMIT 1'
    );
    $requestStmt->execute([$schoolId, $requesterId, $roleName]);
    $requestId = (int) ($requestStmt->fetchColumn() ?: 0);
    foreach ($reviewers as $reviewer) {
        if ($requestId > 0 && isset($reviewer['user_id'])) {
            try {
                school_notification_create(
                    $schoolId,
                    (int) $reviewer['user_id'],
                    $requesterId,
                    'school-role-request:' . $requestId,
                    'other',
                    'School role request needs review',
                    'A new ' . role_display_name($roleName) . ' request is waiting for review at ' . $schoolName . '.',
                    'school_role_requests.php'
                );
            } catch (Throwable $exception) {
                error_log('School role review notification creation failed: ' . $exception->getMessage());
            }
        }
        $sent = send_mail(
            (string) $reviewer['email'],
            'School role request needs review',
            '<p>Hello ' . e((string) $reviewer['full_name']) . ',</p><p>' . e($message) . '</p>',
            'Hello ' . (string) $reviewer['full_name'] . ",\n\n" . $message
        );
        $delivered = $delivered || $sent;
    }
    return $delivered ? 'sent' : 'delivery_failed';
}

function school_default_role_for_school(array $roles): string
{
    if ($roles === []) {
        return '';
    }

    $preferredOrder = [
        'class_teacher',
        'subject_teacher',
        'school_headmaster',
        'school_assistant_headmaster',
        'school_proprietor',
        'school_secretary',
        'school_accountant',
        'school_parent',
        'school_student',
    ];

    foreach ($preferredOrder as $preferredRole) {
        if (in_array($preferredRole, $roles, true)) {
            return $preferredRole;
        }
    }

    return $roles[0];
}

function set_active_school(int $schoolId, ?string $roleName = null): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        unset($_SESSION['active_school_id']);
        return;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(DISTINCT sm.school_id) AS school_count,
                COUNT(DISTINCT CASE WHEN sm.school_id = ? THEN sm.school_id END) AS selected_count
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id AND s.deleted_at IS NULL
         WHERE sm.user_id = ? AND sm.status = ? AND sm.role_name <> ?'
    );
    $stmt->execute([$schoolId, $userId, 'active', 'school_bursar']);
    $membership = $stmt->fetch();

    if ($membership && (int) $membership['school_count'] === 1
        && (int) $membership['selected_count'] === 1) {
        $roles = school_user_roles_for_school($userId, $schoolId);
        if ($roles === []) {
            unset($_SESSION['active_school_id'], $_SESSION['active_school_role']);
            return;
        }
        if ($roleName === null || !in_array($roleName, $roles, true)) {
            $currentRole = (string) ($_SESSION['active_school_role'] ?? '');
            $roleName = in_array($currentRole, $roles, true) ? $currentRole : school_default_role_for_school($roles);
        }
        $_SESSION['active_school_id'] = $schoolId;
        $_SESSION['active_school_role'] = $roleName;
        return;
    }

    unset($_SESSION['active_school_id'], $_SESSION['active_school_role']);
}

function active_school_id(): ?int
{
    $supportContext = active_it_support_context();
    if ($supportContext !== null) {
        return (int) $supportContext['school_id'];
    }

    $schoolId = $_SESSION['active_school_id'] ?? null;
    if (!is_numeric($schoolId)) {
        $activeMemberships = array_values(array_filter(
            school_user_memberships(),
            static fn (array $membership): bool => $membership['status'] === 'active'
        ));
        $schoolIds = array_values(array_unique(array_column($activeMemberships, 'school_id')));
        if (count($schoolIds) === 1) {
            $schoolId = $schoolIds[0];
            $_SESSION['active_school_id'] = $schoolId;
            return (int) $schoolId;
        }
        return null;
    }

    $schoolId = (int) $schoolId;
    $stmt = db()->prepare(
        'SELECT COUNT(DISTINCT sm.school_id) AS school_count,
                COUNT(DISTINCT CASE WHEN sm.school_id = ? THEN sm.school_id END) AS selected_count
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id AND s.deleted_at IS NULL
         WHERE sm.user_id = ? AND sm.status = ? AND sm.role_name <> ?'
    );
    $stmt->execute([$schoolId, (int) ($_SESSION['user_id'] ?? 0), 'active', 'school_bursar']);
    $membership = $stmt->fetch();

    if ($membership && (int) $membership['school_count'] === 1
        && (int) $membership['selected_count'] === 1) {
        return $schoolId;
    }

    unset($_SESSION['active_school_id']);
    return null;
}

function school_access_is_available(int $schoolId): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM schools
         WHERE id = ? AND deleted_at IS NULL AND status = ?
           AND subscription_status IN (?, ?)
           AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
         LIMIT 1'
    );
    $stmt->execute([$schoolId, 'active', 'trial', 'active']);
    return (bool) $stmt->fetchColumn();
}

function active_school_context(): ?array
{
    $user = current_user();
    $supportContext = $user === null ? null : active_it_support_context($user);
    if ($supportContext !== null) {
        $stmt = db()->prepare(
            'SELECT id, name, short_name, school_type, logo_path, address, region, district,
                    contact_email, contact_phone, proprietor_name, status, subscription_status,
                    annual_fee, subscription_expires_at
             FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([(int) $supportContext['school_id']]);
        $school = $stmt->fetch();
        if (!$school) {
            return null;
        }
        $school['membership_role'] = (string) $supportContext['preview_role'];
        return $school;
    }

    $schoolId = active_school_id();
    if ($schoolId === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, short_name, school_type, logo_path, address, region, district,
                contact_email, contact_phone, proprietor_name, status, subscription_status,
                annual_fee, subscription_expires_at
         FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    if (!$school) {
        unset($_SESSION['active_school_id']);
        return null;
    }

    if (!school_access_is_available((int) $school['id'])) {
        unset($_SESSION['active_school_id']);
        return null;
    }

    $stmt = db()->prepare(
        'SELECT role_name, status FROM school_memberships
         WHERE school_id = ? AND user_id = ? AND status = "active"
           AND role_name <> "school_bursar" AND role_name = ? LIMIT 1'
    );
    $stmt->execute([
        $schoolId,
        (int) ($_SESSION['user_id'] ?? 0),
        (string) ($_SESSION['active_school_role'] ?? ''),
    ]);
    $membership = $stmt->fetch();
    if (!$membership) {
        unset($_SESSION['active_school_id']);
        return null;
    }

    $school['membership_role'] = (string) $membership['role_name'];
    return $school;
}

function school_role_permissions(): array
{
    return [
        'school_proprietor' => ['school.manage', 'school.view', 'school.settings.edit', 'school.branding.manage', 'students.manage', 'students.lifecycle.manage', 'staff.manage', 'staff.view', 'classes.manage', 'attendance.manage', 'results.manage', 'learning.manage', 'timetable.manage', 'timetable.view', 'fees.manage', 'payments.manage', 'payroll.manage', 'support.manage', 'announcements.manage', 'reports.view', 'report_cards.view', 'parent_links.manage'],
        'school_headmaster' => ['school.view', 'school.settings.edit', 'school.branding.manage', 'students.manage', 'students.lifecycle.manage', 'staff.view', 'classes.manage', 'attendance.manage', 'results.manage', 'learning.manage', 'timetable.manage', 'timetable.view', 'fees.manage', 'announcements.manage', 'reports.view', 'report_cards.view', 'parent_links.manage'],
        'school_assistant_headmaster' => ['school.view', 'students.manage', 'students.lifecycle.manage', 'staff.view', 'classes.manage', 'attendance.manage', 'results.manage', 'learning.manage', 'timetable.manage', 'timetable.view', 'announcements.manage', 'reports.view', 'report_cards.view', 'parent_links.manage'],
        'school_secretary' => ['school.view', 'students.manage', 'staff.view', 'attendance.view', 'timetable.view', 'announcements.manage', 'reports.view'],
        'class_teacher' => ['school.view', 'students.view', 'attendance.manage', 'classes.view', 'results.manage', 'learning.manage', 'timetable.view', 'announcements.view', 'report_cards.view'],
        'subject_teacher' => ['school.view', 'students.view', 'results.manage', 'learning.manage', 'timetable.view', 'attendance.view'],
        'school_accountant' => ['fees.manage', 'payments.manage', 'payroll.manage', 'reports.view', 'school.view'],
        'school_parent' => ['students.view_own', 'fees.view_own', 'announcements.view', 'report_cards.view_own', 'learning.view_own'],
        'school_student' => ['student.portal.view'],
    ];
}

function school_user_role_for_school(int $userId, int $schoolId): ?string
{
    $supportContext = active_it_support_context();
    if ($supportContext !== null
        && (int) $supportContext['admin_user_id'] === $userId
        && (int) $supportContext['school_id'] === $schoolId) {
        return (string) $supportContext['preview_role'];
    }

    if (active_school_id() !== $schoolId || (int) ($_SESSION['user_id'] ?? 0) !== $userId) {
        return null;
    }
    $roles = school_user_roles_for_school($userId, $schoolId);
    $selectedRole = (string) ($_SESSION['active_school_role'] ?? '');
    if (!in_array($selectedRole, $roles, true)) {
        $selectedRole = school_default_role_for_school($roles);
        if ($selectedRole !== '') {
            $_SESSION['active_school_role'] = $selectedRole;
        }
    }
    return $selectedRole === '' ? null : $selectedRole;
}

function school_has_permission(array $user, int $schoolId, string $permission): bool
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId < 1 || $schoolId < 1) {
        return false;
    }

    $supportContext = active_it_support_context($user);
    if ($supportContext !== null && (int) $supportContext['school_id'] !== $schoolId) {
        return false;
    }
    $role = school_user_role_for_school($userId, $schoolId);
    if ($role === null) {
        return false;
    }

    $permissions = school_role_permissions();
    $allowed = $permissions[$role] ?? [];
    if (in_array($permission, $allowed, true)) {
        return true;
    }
    if ($role === 'class_teacher' && $permission === 'fees.view_assigned') {
        return school_teacher_has_class_assignment($userId, $schoolId);
    }
    return in_array($role, ['class_teacher', 'subject_teacher'], true)
        && in_array($permission, ['classes.view', 'students.view', 'attendance.manage'], true)
        && school_teacher_has_class_assignment($userId, $schoolId);
}

function school_teacher_has_class_assignment(
    int $userId,
    int $schoolId,
    ?int $classId = null,
    ?string $academicYear = null
): bool {
    if ($userId < 1 || $schoolId < 1) {
        return false;
    }
    $sql = 'SELECT 1 FROM teacher_class_assignments tca
            JOIN school_classes sc ON sc.id = tca.class_id AND sc.school_id = tca.school_id
            WHERE tca.school_id = ? AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year';
    $params = [$schoolId, $userId];
    if ($classId !== null) {
        $sql .= ' AND tca.class_id = ?';
        $params[] = $classId;
    }
    if ($academicYear !== null) {
        $sql .= ' AND tca.academic_year = ?';
        $params[] = $academicYear;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function school_teacher_has_subject_assignment(
    int $userId,
    int $schoolId,
    ?int $classId = null,
    ?int $subjectId = null,
    ?string $academicYear = null
): bool {
    if ($userId < 1 || $schoolId < 1) {
        return false;
    }
    $sql = 'SELECT 1 FROM class_subjects cs
            JOIN school_classes sc ON sc.id = cs.class_id AND sc.school_id = cs.school_id
            WHERE cs.school_id = ? AND cs.teacher_user_id = ?';
    $params = [$schoolId, $userId];
    if ($classId !== null) {
        $sql .= ' AND cs.class_id = ?';
        $params[] = $classId;
    }
    if ($subjectId !== null) {
        $sql .= ' AND cs.subject_id = ?';
        $params[] = $subjectId;
    }
    if ($academicYear !== null) {
        $sql .= ' AND sc.academic_year = ?';
        $params[] = $academicYear;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function require_school_permission(int $schoolId, string $permission): array
{
    $user = require_login();
    if (!school_has_permission($user, $schoolId, $permission)) {
        http_response_code(403);
        render_header('School permission denied');
        echo '<section class="panel"><h1>School permission denied</h1><p>Your role does not allow this action in the selected school.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
        render_footer();
        exit;
    }
    return $user;
}

function platform_super_admin_user(array $user): bool
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId < 1) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT 1
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id AND r.name = 'platform_super_admin'
         WHERE u.id = ? AND u.status = 'active' AND u.email_verified_at IS NOT NULL
           AND LOWER(TRIM(u.email)) = ?
         LIMIT 1"
    );
    $stmt->execute([$userId, PLATFORM_SUPER_ADMIN_EMAIL]);
    return (bool) $stmt->fetchColumn();
}

const PLATFORM_SUPER_ADMIN_EMAIL = 'tweneebenezer897@gmail.com';

function platform_super_admin_contact_details(): array
{
    return [
        'phone_numbers' => ['0533785133'],
        'phone_link' => 'tel:+233533785133',
        'whatsapp' => '0533785133',
        'whatsapp_link' => 'https://wa.me/233533785133',
        'email' => 'connezeredutech@gmail.com',
        'email_link' => 'mailto:connezeredutech@gmail.com',
    ];
}

function active_it_support_context(?array $user = null): ?array
{
    $user ??= current_user();
    $supportId = filter_var($_SESSION['it_support_session_id'] ?? null, FILTER_VALIDATE_INT);
    if ($user === null || $supportId === false || $supportId < 1 || !platform_super_admin_user($user)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, admin_user_id, school_id, preview_role, access_mode, reason, started_at
         FROM it_support_sessions
         WHERE id = ? AND admin_user_id = ? AND session_hash = ? AND ended_at IS NULL
          AND EXISTS (SELECT 1 FROM schools WHERE schools.id = it_support_sessions.school_id AND schools.deleted_at IS NULL)
         LIMIT 1'
    );
    $stmt->execute([
        $supportId,
        (int) $user['id'],
        hash('sha256', session_id()),
    ]);
    $context = $stmt->fetch();
    if (!$context || $context['preview_role'] === 'school_bursar'
        || !in_array($context['access_mode'], ['read_only', 'administrative'], true)) {
        return null;
    }

    return $context;
}

function it_support_write_route_allowed(string $route): bool
{
    static $routes = [
        'admissions.php',
        'announcements.php',
        'assessments.php',
        'attendance.php',
        'classes.php',
        'fee_management.php',
        'fees.php',
        'former_students.php',
        'graduation.php',
        'graduation_certificates.php',
        'invoices.php',
        'learning.php',
        'parent_links.php',
        'profiles.php',
        'report_cards.php',
        'school_admin.php',
        'school_administration_roster.php',
        'school_calendar.php',
        'school_certificate.php',
        'school_certificate_logo.php',
        'school_role_requests.php',
        'school_settings.php',
        'school_support.php',
        'select_academic_period.php',
        'settings.php',
        'staff.php',
        'student_profile.php',
        'students.php',
        'subjects.php',
        'term_setup.php',
    ];

    return in_array($route, $routes, true);
}

function it_support_write_allowed(?array $context, string $route): bool
{
    return $context !== null
        && $context['access_mode'] === 'administrative'
        && !in_array($context['preview_role'], ['school_student', 'school_parent'], true)
        && it_support_write_route_allowed($route);
}

function require_platform_super_admin(): array
{
    $user = require_login();
    if (!platform_super_admin_user($user)) {
        audit((int) $user['id'], 'platform_access_denied');
        http_response_code(403);
        render_header('Platform access denied');
        echo '<section class="panel"><h1>Platform access denied</h1><p>You do not have access to platform administration.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
        render_footer();
        exit;
    }
    return $user;
}

function require_school_context(?int $schoolId = null, ?string $requiredRole = null): array
{
    $user = require_login();
    $contextSchoolId = $schoolId ?? active_school_id();
    if ($contextSchoolId === null) {
        flash('error', 'Select a school before continuing.');
        redirect('schools.php');
    }

    $supportContext = active_it_support_context($user);
    if ($supportContext !== null) {
        if ((int) $supportContext['school_id'] !== $contextSchoolId
            || ($requiredRole !== null && $supportContext['preview_role'] !== $requiredRole)) {
            http_response_code(403);
            exit('The active IT Support Mode does not authorize this school or role.');
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
            && !it_support_write_allowed(
                $supportContext,
                basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))
            )) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('This IT Support session does not authorize changes through this route.');
        }

        $schoolStmt = db()->prepare('SELECT id, name FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $schoolStmt->execute([$contextSchoolId]);
        $school = $schoolStmt->fetch();
        if (!$school) {
            http_response_code(404);
            exit('The selected school no longer exists.');
        }
        set_active_school($contextSchoolId);
        $user['active_school_id'] = $contextSchoolId;
        $user['active_school_role'] = (string) $supportContext['preview_role'];
        $user['active_school_name'] = (string) $school['name'];
        $user['it_support_mode'] = true;
        $user['it_support_access_mode'] = (string) $supportContext['access_mode'];
        $user['student_preview_mode'] = $supportContext['preview_role'] === 'school_student';
        return $user;
    }

    school_staff_employment_process_due($contextSchoolId);

    $activeAssignments = db()->prepare(
        'SELECT COUNT(DISTINCT sm.school_id) FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id AND s.deleted_at IS NULL
         WHERE sm.user_id = ? AND sm.status = ?'
    );
    $activeAssignments->execute([(int) $user['id'], 'active']);
    if ((int) $activeAssignments->fetchColumn() !== 1) {
        http_response_code(403);
        render_header('School assignment required');
        echo '<section class="panel"><h1>School assignment required</h1><p>Your account must have active access to exactly one school. Contact the platform administrator to resolve or transfer an assignment.</p><a class="button" href="schools.php">View school status</a></section>';
        render_footer();
        exit;
    }

    $stmt = db()->prepare(
        '        SELECT sm.role_name, sm.status, s.name, s.status AS school_status, s.subscription_status,
               s.subscription_expires_at
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id AND s.deleted_at IS NULL
         WHERE sm.user_id = ? AND sm.school_id = ? AND sm.status = "active"
           AND sm.role_name = ? AND s.deleted_at IS NULL LIMIT 1'
    );
    $requestedRole = school_user_role_for_school((int) $user['id'], $contextSchoolId) ?? '';
    $stmt->execute([(int) $user['id'], $contextSchoolId, $requestedRole]);
    $membership = $stmt->fetch();

    if (!$membership
        || $membership['school_status'] !== 'active'
        || !in_array((string) $membership['subscription_status'], ['trial', 'active'], true)
        || ($membership['subscription_expires_at'] !== null
            && strtotime((string) $membership['subscription_expires_at']) <= time())) {
        http_response_code(403);
        render_header('School access denied');
        echo '<section class="panel"><h1>School access denied</h1><p>Your account is not active in this school, or the school subscription is not currently active.</p><a class="button" href="schools.php">Choose another school</a></section>';
        render_footer();
        exit;
    }

    if ($requiredRole !== null && !in_array($requiredRole, school_user_roles_for_school((int) $user['id'], $contextSchoolId), true)) {
        http_response_code(403);
        render_header('Permission denied');
        echo '<section class="panel"><h1>Permission denied</h1><p>This school workspace requires the ' . e($requiredRole) . ' role.</p><a class="button" href="schools.php">Back to school selection</a></section>';
        render_footer();
        exit;
    }

    set_active_school($contextSchoolId, (string) $membership['role_name']);
    $user['active_school_id'] = $contextSchoolId;
    $user['active_school_role'] = (string) $membership['role_name'];
    $user['active_school_name'] = (string) $membership['name'];
    return $user;
}

function school_student_fee_clearance(int $studentId, int $schoolId, string $academicYear): array
{
    $studentStmt = db()->prepare(
        'SELECT ss.id, sc.level AS class_level
         FROM school_students ss
         LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         WHERE ss.id = ? AND ss.school_id = ? LIMIT 1'
    );
    $studentStmt->execute([$studentId, $schoolId]);
    $student = $studentStmt->fetch();
    if (!$student || !$student['class_level']) {
        return ['status' => 'Pending Financial Review', 'reason' => 'Student or class-level records are missing.'];
    }

    $termStmt = db()->prepare(
        'SELECT term.term_name FROM school_terms term
         JOIN school_academic_years academic_year
           ON academic_year.id = term.academic_year_id
          AND academic_year.school_id = term.school_id
         WHERE term.school_id = ? AND academic_year.academic_year = ?'
    );
    $termStmt->execute([$schoolId, $academicYear]);
    $terms = $termStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($terms === []) {
        return ['status' => 'Pending Financial Review', 'reason' => 'No academic periods are configured for this year.'];
    }

    $hasPendingBalance = false;
    foreach ($terms as $periodName) {
        $feeStmt = db()->prepare(
            'SELECT COUNT(*) FROM school_fee_structures
             WHERE school_id = ? AND class_level = ? AND academic_year = ? AND period_name = ? AND is_active = 1'
        );
        $feeStmt->execute([$schoolId, $student['class_level'], $academicYear, $periodName]);
        if ((int) $feeStmt->fetchColumn() === 0) {
            return ['status' => 'Pending Financial Review', 'reason' => 'Fee schedule is missing for ' . $periodName . '.'];
        }
        $invoiceStmt = db()->prepare(
            'SELECT id, balance FROM school_invoices
             WHERE school_id = ? AND student_id = ? AND academic_year = ? AND period_name = ? LIMIT 1'
        );
        $invoiceStmt->execute([$schoolId, $studentId, $academicYear, $periodName]);
        $invoice = $invoiceStmt->fetch();
        if (!$invoice) {
            return ['status' => 'Pending Financial Review', 'reason' => 'Invoice is missing for ' . $periodName . '.'];
        }
        if ((float) $invoice['balance'] > 0) {
            $arrangementStmt = db()->prepare(
                'SELECT 1 FROM school_invoice_adjustments
                 WHERE school_id = ? AND invoice_id = ? AND adjustment_type = "payment_arrangement"
                   AND status = "approved" AND (valid_until IS NULL OR valid_until >= CURRENT_DATE()) LIMIT 1'
            );
            $arrangementStmt->execute([$schoolId, (int) $invoice['id']]);
            if (!$arrangementStmt->fetchColumn()) {
                $hasPendingBalance = true;
            }
        }
    }

    return $hasPendingBalance
        ? ['status' => 'Fee Clearance Pending', 'reason' => 'An outstanding balance has no approved active arrangement.']
        : ['status' => 'Cleared', 'reason' => 'Verified payments or approved adjustments satisfy the configured policy.'];
}

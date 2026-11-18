<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/staff_records.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'No active school was found for your account.');
    redirect('schools.php');
}

$schoolId = (int) $school['id'];
$userId = (int) $user['id'];
$role = school_user_role_for_school($userId, $schoolId);
$supportContext = active_it_support_context($user);
if ($supportContext !== null) {
    http_response_code(403);
    render_header('Staff records access denied');
    echo '<section class="panel"><h1>Staff records access denied</h1><p>Staff personnel records are unavailable during IT support access.</p><a class="button" href="school_dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

$isManager = in_array($role, ['school_proprietor', 'school_headmaster'], true);
$staffTablesReady = true;
$tableCheck = db()->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?'
);
foreach (['school_staff_concerns', 'school_staff_promotions', 'school_staff_documents'] as $tableName) {
    $tableCheck->execute([$tableName]);
    $staffTablesReady = $staffTablesReady && (int) $tableCheck->fetchColumn() === 1;
}
if (!$staffTablesReady) {
    http_response_code(503);
    render_header('Staff records migration required');
    echo '<section class="panel"><h1>Staff records are not configured</h1><p>Apply database migration 087 before using staff concerns, promotion history, or official staff documents. No records have been changed.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$ownStaffStmt = db()->prepare(
    'SELECT staff.id, staff.employee_number
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
     JOIN school_memberships membership
       ON membership.school_id = staff.school_id AND membership.user_id = staff.user_id
      AND membership.status = "active"
      AND membership.role_name NOT IN ("school_parent", "school_student")
     WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
     LIMIT 1'
);
$ownStaffStmt->execute([$schoolId, $userId]);
$ownStaff = $ownStaffStmt->fetch();
if (!$isManager && !$ownStaff) {
    http_response_code(403);
    render_header('Staff records access denied');
    echo '<section class="panel"><h1>Staff records access denied</h1><p>Your account is not linked to an active staff record in this school.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$returnToRecords = static function (): never {
    redirect('staff_records.php');
};
$validateText = static function (mixed $value, int $maxLength, string $label, bool $allowEmpty = false): string {
    $text = trim((string) $value);
    if (!$allowEmpty && $text === '') {
        throw new RuntimeException('Enter ' . $label . '.');
    }
    if (preg_match('//u', $text) !== 1) {
        throw new RuntimeException('Enter valid text for ' . $label . '.');
    }
    preg_match_all('/./us', $text, $characters);
    if (count($characters[0]) > $maxLength) {
        throw new RuntimeException($label . ' must be no longer than ' . $maxLength . ' characters.');
    }
    return $text;
};
$validDate = static function (mixed $value): string {
    $dateText = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $dateText
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        throw new RuntimeException('Choose a valid effective date.');
    }
    return $dateText;
};
$requireActiveStaff = static function (mixed $staffId) use ($schoolId): array {
    $id = filter_var($staffId, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new RuntimeException('Choose an active staff member.');
    }
    $staffStmt = db()->prepare(
        'SELECT staff.id, staff.user_id, account.full_name
         FROM school_staff staff
         JOIN users account ON account.id = staff.user_id
         WHERE staff.id = ? AND staff.school_id = ? AND staff.status = "active"
           AND account.status = "active" AND account.email_verified_at IS NOT NULL
           AND EXISTS (
               SELECT 1 FROM school_memberships membership
               WHERE membership.school_id = staff.school_id
                 AND membership.user_id = staff.user_id AND membership.status = "active"
                 AND membership.role_name NOT IN ("school_parent", "school_student")
           )
         LIMIT 1'
    );
    $staffStmt->execute([(int) $id, $schoolId]);
    $staff = $staffStmt->fetch();
    if (!$staff) {
        throw new RuntimeException('That person is not an active, verified staff member of this school.');
    }
    return $staff;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $storedDocument = null;
    try {
        if ($action === 'submit_concern') {
            $subject = $validateText($_POST['subject'] ?? '', 160, 'a subject');
            $message = $validateText($_POST['message'] ?? '', 5000, 'your concern');
            db()->beginTransaction();
            $insertConcern = db()->prepare(
                'INSERT INTO school_staff_concerns (school_id, staff_id, submitted_by, subject, message)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertConcern->execute([$schoolId, (int) $ownStaff['id'], $userId, $subject, $message]);
            $concernId = (int) db()->lastInsertId();
            audit($userId, 'school_staff_concern_submitted',
                'school_id=' . $schoolId . ';staff_id=' . (int) $ownStaff['id'] . ';concern_id=' . $concernId);
            school_notify_roles(
                $schoolId,
                ['school_proprietor', 'school_headmaster'],
                $userId,
                'staff-concern-' . $concernId,
                'other',
                'Staff concern submitted',
                (string) $user['full_name'] . ' submitted a staff concern for review.',
                'staff_records.php#staff-concerns',
                null,
                null,
                null,
                null,
                [$userId],
                'high',
                true
            );
            db()->commit();
            flash('success', 'Your concern was submitted confidentially to the school leadership team.');
            $returnToRecords();
        }

        if (!$isManager) {
            throw new RuntimeException('Only the school Proprietor or Headmaster can manage staff records.');
        }

        if ($action === 'review_concern') {
            $concernId = filter_var($_POST['concern_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            $response = $validateText($_POST['response'] ?? '', 2000, 'a response', true);
            if ($concernId === false || $concernId < 1
                || !in_array($status, ['in_review', 'resolved'], true)
                || ($status === 'resolved' && $response === '')) {
                throw new RuntimeException('Choose a valid concern status and provide a response when resolving a concern.');
            }
            db()->beginTransaction();
            $concernStmt = db()->prepare(
                'SELECT concern.id, concern.staff_id, staff.user_id
                 FROM school_staff_concerns concern
                 JOIN school_staff staff ON staff.id = concern.staff_id AND staff.school_id = concern.school_id
                 WHERE concern.id = ? AND concern.school_id = ?
                 LIMIT 1 FOR UPDATE'
            );
            $concernStmt->execute([(int) $concernId, $schoolId]);
            $concern = $concernStmt->fetch();
            if (!$concern) {
                throw new RuntimeException('That staff concern was not found in this school.');
            }
            $updateConcern = db()->prepare(
                'UPDATE school_staff_concerns
                 SET status = ?, response = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND school_id = ?'
            );
            $updateConcern->execute([
                $status,
                $response === '' ? null : $response,
                $userId,
                (int) $concernId,
                $schoolId,
            ]);
            audit($userId, 'school_staff_concern_reviewed',
                'school_id=' . $schoolId . ';staff_id=' . (int) $concern['staff_id']
                . ';concern_id=' . (int) $concernId . ';status=' . $status);
            if ($concern['user_id'] !== null) {
                school_notification_create(
                    $schoolId,
                    (int) $concern['user_id'],
                    $userId,
                    'staff-concern-update-' . (int) $concernId . '-' . $status . '-' . bin2hex(random_bytes(6)),
                    'other',
                    $status === 'resolved' ? 'Staff concern resolved' : 'Staff concern under review',
                    $status === 'resolved'
                        ? 'Your staff concern has been marked resolved. Sign in to view the school response.'
                        : 'The school leadership team is reviewing your staff concern.',
                    'staff_records.php#my-concerns',
                    null,
                    null,
                    null,
                    null,
                    'normal',
                    false
                );
            }
            db()->commit();
            flash('success', $status === 'resolved' ? 'Staff concern marked resolved.' : 'Staff concern marked as under review.');
            $returnToRecords();
        }

        if ($action === 'record_promotion') {
            $staff = $requireActiveStaff($_POST['staff_id'] ?? null);
            $previousTitle = $validateText($_POST['previous_job_title'] ?? '', 120, 'the previous job title');
            $newTitle = $validateText($_POST['new_job_title'] ?? '', 120, 'the new job title');
            $effectiveOn = $validDate($_POST['effective_on'] ?? '');
            $reason = $validateText($_POST['reason'] ?? '', 2000, 'the promotion reason');
            db()->beginTransaction();
            $insertPromotion = db()->prepare(
                'INSERT INTO school_staff_promotions
                    (school_id, staff_id, previous_job_title, new_job_title, effective_on, reason, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertPromotion->execute([
                $schoolId,
                (int) $staff['id'],
                $previousTitle,
                $newTitle,
                $effectiveOn,
                $reason,
                $userId,
            ]);
            $promotionId = (int) db()->lastInsertId();
            audit($userId, 'school_staff_promotion_recorded',
                'school_id=' . $schoolId . ';staff_id=' . (int) $staff['id'] . ';promotion_id=' . $promotionId);
            school_notification_create(
                $schoolId,
                (int) $staff['user_id'],
                $userId,
                'staff-promotion-' . $promotionId,
                'other',
                'Promotion recorded',
                'A promotion from ' . $previousTitle . ' to ' . $newTitle . ' has been recorded in your school staff history.',
                'staff_records.php#my-promotions',
                null,
                null,
                null,
                null,
                'normal',
                false
            );
            db()->commit();
            flash('success', 'Promotion history recorded. System roles and permissions were not changed.');
            $returnToRecords();
        }

        if ($action === 'upload_document') {
            $staff = $requireActiveStaff($_POST['staff_id'] ?? null);
            $documentType = (string) ($_POST['document_type'] ?? '');
            if (!in_array($documentType, ['appointment', 'qualification', 'identity', 'contract', 'other'], true)) {
                throw new RuntimeException('Choose a valid document type.');
            }
            $title = $validateText($_POST['title'] ?? '', 160, 'a document title');
            $storedDocument = store_school_staff_document($_FILES['document'] ?? []);
            if ($storedDocument === null) {
                throw new RuntimeException('Choose a document to upload.');
            }
            db()->beginTransaction();
            $insertDocument = db()->prepare(
                'INSERT INTO school_staff_documents
                    (school_id, staff_id, document_type, title, stored_name, original_name,
                     mime_type, file_size, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertDocument->execute([
                $schoolId,
                (int) $staff['id'],
                $documentType,
                $title,
                $storedDocument['stored_name'],
                $storedDocument['original_name'],
                $storedDocument['mime_type'],
                (int) $storedDocument['file_size'],
                $userId,
            ]);
            $documentId = (int) db()->lastInsertId();
            audit($userId, 'school_staff_document_uploaded',
                'school_id=' . $schoolId . ';staff_id=' . (int) $staff['id'] . ';document_id=' . $documentId);
            school_notification_create(
                $schoolId,
                (int) $staff['user_id'],
                $userId,
                'staff-document-' . $documentId,
                'other',
                'Staff document added',
                'A document has been added to your school staff record.',
                'staff_records.php#my-documents',
                null,
                null,
                null,
                null,
                'normal',
                false
            );
            db()->commit();
            $storedDocument = null;
            flash('success', 'Staff document securely uploaded.');
            $returnToRecords();
        }

        throw new RuntimeException('Choose a valid staff records action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        remove_uncommitted_school_staff_document($storedDocument);
        if ($exception instanceof PDOException) {
            throw $exception;
        }
        if ($exception instanceof RuntimeException || $exception instanceof InvalidArgumentException) {
            flash('error', $exception->getMessage());
            $returnToRecords();
        }
        throw $exception;
    }
}

$listPage = static function (string $key): array {
    $page = filter_var($_GET[$key] ?? 1, FILTER_VALIDATE_INT);
    return [max(1, $page === false ? 1 : (int) $page), 25];
};
$loadList = static function (string $countSql, array $countParams, string $dataSql, array $dataParams, int $page, int $pageSize): array {
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();
    $dataStmt = db()->prepare($dataSql);
    foreach ($dataParams as $index => $value) {
        $dataStmt->bindValue($index + 1, $value);
    }
    $dataStmt->bindValue(count($dataParams) + 1, $pageSize, PDO::PARAM_INT);
    $dataStmt->bindValue(count($dataParams) + 2, ($page - 1) * $pageSize, PDO::PARAM_INT);
    $dataStmt->execute();
    return [$dataStmt->fetchAll(), $total];
};
$renderPages = static function (string $key, int $page, int $total, int $pageSize): void {
    $pageCount = max(1, (int) ceil($total / $pageSize));
    if ($pageCount <= 1) {
        return;
    }
    echo '<nav class="directory-pagination" aria-label="Record pages">';
    echo '<span>Page ' . $page . ' of ' . $pageCount . '</span>';
    foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $number) {
        if ($number < 1 || $number > $pageCount) {
            continue;
        }
        $query = $_GET;
        $query[$key] = $number;
        $url = 'staff_records.php?' . http_build_query($query);
        echo '<a class="button button-small" href="' . e($url) . '">' . e($label) . '</a>';
    }
    echo '</nav>';
};

$staffOptions = [];
if ($isManager) {
    $staffOptionsStmt = db()->prepare(
        'SELECT staff.id, account.full_name
         FROM school_staff staff
         JOIN users account ON account.id = staff.user_id
         WHERE staff.school_id = ? AND staff.status = "active"
           AND account.status = "active" AND account.email_verified_at IS NOT NULL
           AND EXISTS (
               SELECT 1 FROM school_memberships membership
               WHERE membership.school_id = staff.school_id
                 AND membership.user_id = staff.user_id AND membership.status = "active"
                 AND membership.role_name NOT IN ("school_parent", "school_student")
           )
         ORDER BY account.full_name, staff.id'
    );
    $staffOptionsStmt->execute([$schoolId]);
    $staffOptions = $staffOptionsStmt->fetchAll();
}

$concernsPage = $listPage('concerns_page');
$promotionsPage = $listPage('promotions_page');
$documentsPage = $listPage('documents_page');
$promotionScopeSql = $isManager ? '' : ' AND promotion.staff_id = ?';
$promotionScopeParams = $isManager ? [] : [(int) $ownStaff['id']];
$documentScopeSql = $isManager ? '' : ' AND document.staff_id = ?';
$documentScopeParams = $isManager ? [] : [(int) $ownStaff['id']];
$concernScopeSql = $isManager ? '' : ' AND concern.staff_id = ?';
$concernScopeParams = $isManager ? [] : [(int) $ownStaff['id']];
[$concerns, $concernsTotal] = $loadList(
    'SELECT COUNT(*) FROM school_staff_concerns concern WHERE concern.school_id = ?' . $concernScopeSql,
    array_merge([$schoolId], $concernScopeParams),
    'SELECT concern.id, concern.staff_id, concern.subject, concern.message, concern.status,
            concern.response, concern.created_at, concern.reviewed_at,
            submitter.full_name AS staff_name, reviewer.full_name AS reviewer_name
     FROM school_staff_concerns concern
     JOIN school_staff staff ON staff.id = concern.staff_id AND staff.school_id = concern.school_id
     LEFT JOIN users submitter ON submitter.id = staff.user_id
     LEFT JOIN users reviewer ON reviewer.id = concern.reviewed_by
     WHERE concern.school_id = ?' . $concernScopeSql . '
     ORDER BY concern.created_at DESC, concern.id DESC LIMIT ? OFFSET ?',
    array_merge([$schoolId], $concernScopeParams),
    $concernsPage[0],
    $concernsPage[1]
);
[$promotions, $promotionsTotal] = $loadList(
    'SELECT COUNT(*) FROM school_staff_promotions promotion WHERE promotion.school_id = ?' . $promotionScopeSql,
    array_merge([$schoolId], $promotionScopeParams),
    'SELECT promotion.id, promotion.staff_id, promotion.previous_job_title,
            promotion.new_job_title, promotion.effective_on, promotion.reason,
            promotion.created_at, account.full_name AS staff_name
     FROM school_staff_promotions promotion
     JOIN school_staff staff ON staff.id = promotion.staff_id AND staff.school_id = promotion.school_id
     JOIN users account ON account.id = staff.user_id
     WHERE promotion.school_id = ?' . $promotionScopeSql . '
     ORDER BY promotion.effective_on DESC, promotion.id DESC LIMIT ? OFFSET ?',
    array_merge([$schoolId], $promotionScopeParams),
    $promotionsPage[0],
    $promotionsPage[1]
);
[$documents, $documentsTotal] = $loadList(
    'SELECT COUNT(*) FROM school_staff_documents document WHERE document.school_id = ?' . $documentScopeSql,
    array_merge([$schoolId], $documentScopeParams),
    'SELECT document.id, document.staff_id, document.document_type, document.title,
            document.original_name, document.file_size, document.created_at,
            account.full_name AS staff_name
     FROM school_staff_documents document
     JOIN school_staff staff ON staff.id = document.staff_id AND staff.school_id = document.school_id
     JOIN users account ON account.id = staff.user_id
     WHERE document.school_id = ?' . $documentScopeSql . '
     ORDER BY document.created_at DESC, document.id DESC LIMIT ? OFFSET ?',
    array_merge([$schoolId], $documentScopeParams),
    $documentsPage[0],
    $documentsPage[1]
);

$recordsPageTitle = $isManager ? 'Staff records and concerns' : 'My staff concerns and records';
render_header($recordsPageTitle);
?>
<section class="panel">
    <p class="eyebrow">STAFF MANAGEMENT · <?= e((string) $school['name']) ?></p>
    <h1><?= e($recordsPageTitle) ?></h1>
    <p class="muted"><?= $isManager
        ? 'Review employee concerns and maintain staff promotion and document history for this school.'
        : 'Submit a confidential concern and view your own promotion and document history for this school.' ?></p>
    <?php if ($ownStaff && $ownStaff['employee_number']): ?><p class="muted">Employee number: <?= e((string) $ownStaff['employee_number']) ?></p><?php endif; ?>
</section>

<?php if (!$isManager): ?>
<section class="panel top-gap" id="submit-concern">
    <h2>Submit a staff concern</h2>
    <p class="muted">Only the school Proprietor and Headmaster can review concerns. Your concern is not visible to other employees.</p>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_concern">
        <label for="concern-subject">Subject<input id="concern-subject" name="subject" maxlength="160" required></label>
        <label class="span-all" for="concern-message">Describe your concern<textarea id="concern-message" name="message" maxlength="5000" rows="5" required></textarea></label>
        <div class="span-all"><button class="button button-primary" type="submit">Submit confidentially</button></div>
    </form>
</section>
<?php else: ?>
<section class="dashboard-grid top-gap" aria-label="Staff record actions">
    <article class="panel">
        <h2>Record a promotion</h2>
        <p class="muted">This records job-title history only. It does not change account roles or permissions.</p>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_promotion">
            <label for="promotion-staff">Staff member<select id="promotion-staff" name="staff_id" required>
                <option value="">Choose staff</option>
                <?php foreach ($staffOptions as $staffOption): ?><option value="<?= (int) $staffOption['id'] ?>"><?= e((string) $staffOption['full_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label for="promotion-previous">Previous job title<input id="promotion-previous" name="previous_job_title" maxlength="120" required></label>
            <label for="promotion-new">New job title<input id="promotion-new" name="new_job_title" maxlength="120" required></label>
            <label for="promotion-date">Effective date<input id="promotion-date" type="date" name="effective_on" required></label>
            <label for="promotion-reason">Reason or approval reference<textarea id="promotion-reason" name="reason" maxlength="2000" rows="3" required></textarea></label>
            <button class="button button-primary" type="submit">Save promotion history</button>
        </form>
    </article>
    <article class="panel">
        <h2>Add an official staff document</h2>
        <p class="muted">PDF, JPG, PNG, or WebP; maximum 10 MB. Files are stored privately and accessible only to school leadership and the employee concerned.</p>
        <form method="post" enctype="multipart/form-data" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_document">
            <label for="document-staff">Staff member<select id="document-staff" name="staff_id" required>
                <option value="">Choose staff</option>
                <?php foreach ($staffOptions as $staffOption): ?><option value="<?= (int) $staffOption['id'] ?>"><?= e((string) $staffOption['full_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label for="document-type">Document type<select id="document-type" name="document_type" required>
                <option value="">Choose type</option><option value="appointment">Appointment</option>
                <option value="qualification">Qualification</option><option value="identity">Identity</option>
                <option value="contract">Contract</option><option value="other">Other</option>
            </select></label>
            <label for="document-title">Document title<input id="document-title" name="title" maxlength="160" required></label>
            <label for="staff-document-file">File<input id="staff-document-file" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required></label>
            <button class="button button-primary" type="submit">Upload document</button>
        </form>
    </article>
</section>
<?php endif; ?>

<section class="panel top-gap" id="<?= $isManager ? 'staff-concerns' : 'my-concerns' ?>">
    <h2><?= $isManager ? 'Staff concerns' : 'My concerns' ?></h2>
    <?php if ($concerns === []): ?><p class="muted"><?= $isManager ? 'No staff concerns have been submitted.' : 'You have not submitted any concerns.' ?></p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><?php if ($isManager): ?><th>Employee</th><?php endif; ?><th>Concern</th><th>Status</th><?php if ($isManager): ?><th>Review</th><?php else: ?><th>School response</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($concerns as $concern): ?><tr>
            <?php if ($isManager): ?><td><?= e((string) ($concern['staff_name'] ?? 'Former staff member')) ?></td><?php endif; ?>
            <td><strong><?= e((string) $concern['subject']) ?></strong><br><small><?= nl2br(e((string) $concern['message'])) ?></small><br><small>Submitted <?= e((string) $concern['created_at']) ?></small></td>
            <td><?= e(match ((string) $concern['status']) { 'pending' => 'Pending', 'in_review' => 'Under review', 'resolved' => 'Resolved', default => 'Unknown' }) ?></td>
            <?php if ($isManager): ?><td>
                <?php if ($concern['response'] !== null): ?><p><?= nl2br(e((string) $concern['response'])) ?></p><?php endif; ?>
                <details><summary>Update review</summary><form method="post" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="review_concern">
                    <input type="hidden" name="concern_id" value="<?= (int) $concern['id'] ?>">
                    <label for="concern-status-<?= (int) $concern['id'] ?>">Status<select id="concern-status-<?= (int) $concern['id'] ?>" name="status" required>
                        <option value="in_review" <?= $concern['status'] === 'in_review' ? 'selected' : '' ?>>Under review</option>
                        <option value="resolved" <?= $concern['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select></label>
                    <label for="concern-response-<?= (int) $concern['id'] ?>">Response<textarea id="concern-response-<?= (int) $concern['id'] ?>" name="response" maxlength="2000" rows="3" <?= $concern['status'] === 'resolved' ? 'required' : '' ?>><?= e((string) ($concern['response'] ?? '')) ?></textarea></label>
                    <button class="button button-small" type="submit">Save review</button>
                </form></details>
            </td><?php else: ?><td><?= $concern['response'] === null ? '<span class="muted">No response yet</span>' : nl2br(e((string) $concern['response'])) ?></td><?php endif; ?>
        </tr><?php endforeach; ?></tbody>
    </table></div><?php $renderPages('concerns_page', $concernsPage[0], $concernsTotal, $concernsPage[1]); endif; ?>
</section>

<section class="panel top-gap" id="<?= $isManager ? 'staff-promotions' : 'my-promotions' ?>">
    <h2><?= $isManager ? 'Promotion history' : 'My promotion history' ?></h2>
    <?php if ($promotions === []): ?><p class="muted">No promotion history is recorded.</p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><?php if ($isManager): ?><th>Employee</th><?php endif; ?><th>Previous title</th><th>New title</th><th>Effective date</th><th>Reason</th></tr></thead>
        <tbody><?php foreach ($promotions as $promotion): ?><tr>
            <?php if ($isManager): ?><td><?= e((string) $promotion['staff_name']) ?></td><?php endif; ?>
            <td><?= e((string) $promotion['previous_job_title']) ?></td><td><?= e((string) $promotion['new_job_title']) ?></td>
            <td><?= e((string) $promotion['effective_on']) ?></td><td><?= nl2br(e((string) $promotion['reason'])) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div><?php $renderPages('promotions_page', $promotionsPage[0], $promotionsTotal, $promotionsPage[1]); endif; ?>
</section>

<section class="panel top-gap" id="<?= $isManager ? 'staff-documents' : 'my-documents' ?>">
    <h2><?= $isManager ? 'Official staff documents' : 'My staff documents' ?></h2>
    <?php if ($documents === []): ?><p class="muted">No official staff documents are available.</p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><?php if ($isManager): ?><th>Employee</th><?php endif; ?><th>Document</th><th>Type</th><th>Uploaded</th><th>File</th></tr></thead>
        <tbody><?php foreach ($documents as $document): ?><tr>
            <?php if ($isManager): ?><td><?= e((string) $document['staff_name']) ?></td><?php endif; ?>
            <td><?= e((string) $document['title']) ?></td><td><?= e(ucfirst((string) $document['document_type'])) ?></td>
            <td><?= e((string) $document['created_at']) ?></td>
            <td><a href="staff_record_document.php?id=<?= (int) $document['id'] ?>"><?= e((string) $document['original_name']) ?></a><br><small><?= e(number_format((int) $document['file_size'] / 1024)) ?> KB</small></td>
        </tr><?php endforeach; ?></tbody>
    </table></div><?php $renderPages('documents_page', $documentsPage[0], $documentsTotal, $documentsPage[1]); endif; ?>
</section>
<?php render_footer(); ?>

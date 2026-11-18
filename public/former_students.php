<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/student_lifecycle.php';
require_once APP_ROOT . '/app/student_class_history.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'students.lifecycle.manage');
$userId = (int) $user['id'];
$exitStatuses = ['transferred', 'withdrawn', 'dropped_out', 'expelled', 'deceased'];
$classStmt = db()->prepare(
    "SELECT id, level, section, academic_year FROM school_classes
     WHERE school_id = ? AND status = 'active' ORDER BY academic_year DESC, level, section"
);
$classStmt->execute([$schoolId]);
$classes = $classStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'exit_student' || $action === 'reinstate_student' || $action === 'reverse_graduation') {
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId < 1) {
            flash('error', 'Choose a valid student record.');
            redirect('former_students.php');
        }

        try {
            if ($action === 'exit_student') {
                if (($_POST['confirm_exit'] ?? '') !== '1') {
                    throw new InvalidArgumentException('Confirm the student exit before saving it.');
                }
                $newStatus = (string) ($_POST['exit_status'] ?? '');
                $exitDate = trim((string) ($_POST['exit_date'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $parsedExitDate = DateTimeImmutable::createFromFormat('!Y-m-d', $exitDate);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (!in_array($newStatus, $exitStatuses, true)
                    || $parsedExitDate === false || $parsedExitDate->format('Y-m-d') !== $exitDate
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                    || strlen($reason) < 5 || strlen($reason) > 1000) {
                    throw new InvalidArgumentException('Choose a non-graduation exit status, a valid exit date, and a reason of 5 to 1000 characters. Graduation must be separately reviewed and approved.');
                }
                $isTransfer = $newStatus === 'transferred';

                db()->beginTransaction();
                $studentStmt = db()->prepare(
                    'SELECT id, school_status, class_id FROM school_students
                     WHERE id = ? AND school_id = ? FOR UPDATE'
                );
                $studentStmt->execute([$studentId, $schoolId]);
                $student = $studentStmt->fetch();
                if (!$student || $student['school_status'] !== 'active') {
                    throw new RuntimeException('Only an active student at this school can be exited.');
                }
                $eventStmt = db()->prepare(
                    'INSERT INTO student_lifecycle_events
                     (school_id, student_id, event_type, previous_status, new_status,
                      previous_class_id, new_class_id, exit_date, effective_on,
                      reason, destination_school_id, actor_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $eventStmt->execute([
                    $schoolId, $studentId, 'exit', 'active', $newStatus,
                    $student['class_id'], $student['class_id'], $exitDate, $exitDate, $reason,
                    null, $userId,
                ]);
                $eventId = (int) db()->lastInsertId();
                $statusStmt = db()->prepare(
                    'UPDATE school_students SET school_status = ? WHERE id = ? AND school_id = ?'
                );
                $statusStmt->execute([$newStatus, $studentId, $schoolId]);
                audit($userId, 'student_exit_recorded',
                    'student_id=' . $studentId . ';event_id=' . $eventId . ';status=' . $newStatus
                    . ($isTransfer ? ';transfer_destination_not_recorded' : ''));
                db()->commit();
                flash('success', 'Student exit recorded. The existing student and academic and financial history have been preserved.');
                redirect('former_students.php');
            }

            if ($action === 'reverse_graduation') {
                if (!in_array(school_user_role_for_school((int) $user['id'], $schoolId), ['school_headmaster', 'school_proprietor'], true)
                    || ($_POST['confirm_reverse'] ?? '') !== '1') {
                    throw new InvalidArgumentException('Only an authorized Headmaster or Proprietor may confirm a graduation correction.');
                }
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $requestedClassId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
                if (preg_match('/^.{5,1000}$/us', $reason) !== 1
                    || $requestedClassId === false || $requestedClassId < 1) {
                    throw new InvalidArgumentException('Choose a current class and enter a correction reason of 5 to 1000 characters.');
                }
                db()->beginTransaction();
                try {
                    $graduationStmt = db()->prepare(
                        'SELECT id FROM student_graduations
                         WHERE school_id = ? AND student_id = ? AND status = "approved"
                         ORDER BY approved_at DESC LIMIT 1 FOR UPDATE'
                    );
                    $graduationStmt->execute([$schoolId, $studentId]);
                    $graduationId = $graduationStmt->fetchColumn();
                    $studentStmt = db()->prepare(
                        'SELECT school_status, class_id FROM school_students
                         WHERE id = ? AND school_id = ? FOR UPDATE'
                    );
                    $studentStmt->execute([$studentId, $schoolId]);
                    $student = $studentStmt->fetch();
                    if (!$student || $student['school_status'] !== 'graduated') {
                        throw new RuntimeException('This student has no graduation status to correct.');
                    }
                    school_class_lock_for_student_assignment(db(), $schoolId, $requestedClassId, $studentId);
                    if ($graduationId !== false) {
                        db()->prepare(
                            'UPDATE student_graduations
                             SET status = "reversed", reversed_by = ?, reversed_at = UTC_TIMESTAMP(),
                                 reversal_reason = ? WHERE id = ? AND status = "approved"'
                        )->execute([$userId, $reason, (int) $graduationId]);
                    }
                    student_class_history_record(
                        db(), $schoolId, $studentId, 'graduation_reversed', null, $requestedClassId,
                        date('Y-m-d'), $reason, $userId
                    );
                    db()->prepare(
                        'UPDATE school_students SET school_status = "active", class_id = ?
                         WHERE id = ? AND school_id = ? AND school_status = "graduated"'
                    )->execute([$requestedClassId, $studentId, $schoolId]);
                    db()->prepare(
                        'INSERT INTO student_lifecycle_events
                         (school_id, student_id, event_type, previous_status, new_status,
                          previous_class_id, new_class_id, effective_on, reason, actor_user_id)
                         VALUES (?, ?, "reinstated", "graduated", "active", NULL, ?, ?, ?, ?)'
                    )->execute([$schoolId, $studentId, $requestedClassId, date('Y-m-d'), $reason, $userId]);
                    $activeCertificates = [];
                    if ($graduationId !== false) {
                        $activeCertificatesStmt = db()->prepare(
                            'SELECT id FROM school_completion_certificates
                             WHERE graduation_id = ? AND school_id = ? AND status = "issued" FOR UPDATE'
                        );
                        $activeCertificatesStmt->execute([(int) $graduationId, $schoolId]);
                        $activeCertificates = $activeCertificatesStmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                    foreach ($activeCertificates as $activeCertificateId) {
                        db()->prepare(
                            'UPDATE school_completion_certificates
                             SET status = "revoked", status_changed_by = ?, status_changed_at = UTC_TIMESTAMP(),
                                 status_reason = ? WHERE id = ? AND status = "issued"'
                        )->execute([$userId, 'Graduation reversed: ' . $reason, (int) $activeCertificateId]);
                        db()->prepare(
                            'INSERT INTO student_graduation_events
                             (school_id, student_id, graduation_id, certificate_id, actor_user_id, event_type, reason)
                             VALUES (?, ?, ?, ?, ?, "certificate_revoked", ?)'
                        )->execute([$schoolId, $studentId, (int) $graduationId, (int) $activeCertificateId, $userId,
                            'Graduation reversed: ' . $reason]);
                    }
                    db()->prepare(
                        'INSERT INTO student_graduation_events
                         (school_id, student_id, graduation_id, actor_user_id, event_type, reason)
                         VALUES (?, ?, ?, ?, "graduation_reversed", ?)'
                    )->execute([$schoolId, $studentId, $graduationId === false ? null : (int) $graduationId, $userId, $reason]);
                    audit($userId, 'student_graduation_reversed',
                        'school_id=' . $schoolId . ';student_id=' . $studentId . ';graduation_id='
                            . ($graduationId === false ? 'legacy-unapproved' : (string) (int) $graduationId)
                            . ';reason=' . $reason);
                    db()->commit();
                    flash('success', 'Graduation was reversed with an audit record. Issued certificates were revoked and the existing student record reinstated.');
                } catch (Throwable $exception) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    throw $exception;
                }
                redirect('former_students.php');
            }

            if (($_POST['confirm_reinstate'] ?? '') !== '1') {
                throw new InvalidArgumentException('Confirm the reinstatement before saving it.');
            }
            $requestedClassId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
            if ($requestedClassId === false || $requestedClassId < 0) {
                throw new InvalidArgumentException('Choose a valid class or keep the previous placement.');
            }
            if ($requestedClassId > 0) {
                $requestedClassId = (int) $requestedClassId;
            }
            db()->beginTransaction();
            $studentStmt = db()->prepare(
                'SELECT id, school_status, class_id FROM school_students
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $studentStmt->execute([$studentId, $schoolId]);
            $student = $studentStmt->fetch();
            if (!$student || $student['school_status'] === 'active' || $student['school_status'] === 'graduated') {
                throw new RuntimeException('Only a former student at this school can be reinstated.');
            }
            $newClassId = $requestedClassId > 0 ? $requestedClassId : $student['class_id'];
            if ($newClassId !== null) {
                school_class_lock_for_student_assignment(db(), $schoolId, (int) $newClassId, $studentId);
            }
            if (($student['class_id'] === null ? null : (int) $student['class_id'])
                !== ($newClassId === null ? null : (int) $newClassId)) {
                student_class_history_record(
                    db(), $schoolId, $studentId, 'reinstated',
                    $student['class_id'] === null ? null : (int) $student['class_id'],
                    $newClassId === null ? null : (int) $newClassId,
                    date('Y-m-d'), 'Student record reinstated to the selected class placement.', $userId
                );
            }
            $eventStmt = db()->prepare(
                'INSERT INTO student_lifecycle_events
                 (school_id, student_id, event_type, previous_status, new_status,
                  previous_class_id, new_class_id, effective_on, actor_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $eventStmt->execute([
                $schoolId, $studentId, 'reinstated', $student['school_status'], 'active',
                $student['class_id'], $newClassId, date('Y-m-d'), $userId,
            ]);
            $updateStmt = db()->prepare(
                'UPDATE school_students SET school_status = ?, class_id = ? WHERE id = ? AND school_id = ?'
            );
            $updateStmt->execute(['active', $newClassId, $studentId, $schoolId]);
            audit($userId, 'student_record_reinstated', 'student_id=' . $studentId . ';event_id=' . (int) db()->lastInsertId());
            db()->commit();
            flash('success', 'The existing student record has been reinstated; no duplicate student was created.');
            redirect('former_students.php');
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect('former_students.php');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
    }

    if ($action === 'receive_transfer') {
        $transferId = filter_var($_POST['transfer_id'] ?? null, FILTER_VALIDATE_INT);
        $requestedClassId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        if ($transferId === false || $transferId < 1
            || $requestedClassId === false || $requestedClassId < 0
            || ($_POST['confirm_receive'] ?? '') !== '1') {
            flash('error', 'Confirm the record receipt and choose a valid class placement.');
            redirect('former_students.php');
        }
        db()->beginTransaction();
        try {
            $requestStmt = db()->prepare(
                'SELECT * FROM student_record_transfer_requests WHERE id = ? FOR UPDATE'
            );
            $requestStmt->execute([$transferId]);
            $request = $requestStmt->fetch();
            if (!$request || (int) $request['target_school_id'] !== $schoolId
                || $request['status'] !== 'released' || $request['received_student_id'] !== null
                || !is_string($request['package_json']) || $request['package_json'] === '') {
                throw new RuntimeException('This released transfer package is unavailable, already received, or not authorized for your school.');
            }
            if ($requestedClassId > 0) {
                school_class_lock_for_student_assignment(db(), $schoolId, $requestedClassId);
            }
            $package = json_decode((string) $request['package_json'], true, 512, JSON_THROW_ON_ERROR);
            $sourceStudent = $package['student'] ?? null;
            if (!is_array($sourceStudent)
                || trim((string) ($sourceStudent['full_name'] ?? '')) === '') {
                throw new RuntimeException('The released transfer package does not contain a valid student record.');
            }
            $fullName = trim((string) $sourceStudent['full_name']);
            $admissionNumber = trim((string) ($sourceStudent['admission_number'] ?? ''));
            $admissionDate = trim((string) ($sourceStudent['admission_date'] ?? substr((string) ($sourceStudent['created_at'] ?? ''), 0, 10)));
            $admissionDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $admissionDate);
            $admissionDate = $admissionDateValue && $admissionDateValue->format('Y-m-d') === $admissionDate
                ? $admissionDate
                : null;
            $dob = trim((string) ($sourceStudent['date_of_birth'] ?? ''));
            $gender = (string) ($sourceStudent['gender'] ?? 'prefer_not_to_say');
            if (!in_array($gender, ['male', 'female', 'other', 'prefer_not_to_say'], true)) {
                $gender = 'prefer_not_to_say';
            }
            if ($admissionNumber !== '') {
                $duplicateStmt = db()->prepare(
                    'SELECT id FROM school_students WHERE school_id = ? AND admission_number = ? LIMIT 1'
                );
                $duplicateStmt->execute([$schoolId, $admissionNumber]);
                if ($duplicateStmt->fetchColumn()) {
                    throw new RuntimeException('A student with this admission number already exists at your school. Review that existing record before receiving this transfer.');
                }
            }
            $identityStmt = db()->prepare(
                'SELECT id FROM school_students
                 WHERE school_id = ? AND LOWER(TRIM(full_name)) = LOWER(?) LIMIT 1'
            );
            $identityStmt->execute([$schoolId, $fullName]);
            if ($identityStmt->fetchColumn()) {
                throw new RuntimeException('A student with this name already exists at your school. Review the existing record before receiving this transfer to prevent a duplicate.');
            }
            $guardianId = null;
            $guardianName = trim((string) ($sourceStudent['guardian_name'] ?? ''));
            $guardianEmail = strtolower(trim((string) ($sourceStudent['guardian_email'] ?? '')));
            $guardianPhone = trim((string) ($sourceStudent['guardian_phone'] ?? ''));
            if ($guardianEmail !== '') {
                $guardianStmt = db()->prepare(
                    'SELECT id FROM school_guardians WHERE school_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1'
                );
                $guardianStmt->execute([$schoolId, $guardianEmail]);
                $guardianId = $guardianStmt->fetchColumn() ?: null;
            }
            if ($guardianId === null && ($guardianName !== '' || $guardianEmail !== '' || $guardianPhone !== '')) {
                $guardianStmt = db()->prepare(
                    'INSERT INTO school_guardians (school_id, full_name, email, phone, relationship)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $guardianStmt->execute([
                    $schoolId, $guardianName !== '' ? $guardianName : 'Guardian',
                    $guardianEmail !== '' ? $guardianEmail : null,
                    $guardianPhone !== '' ? $guardianPhone : null, 'Parent',
                ]);
                $guardianId = (int) db()->lastInsertId();
            }
            $studentInsert = db()->prepare(
                'INSERT INTO school_students
                 (school_id, class_id, full_name, admission_number, admission_date, date_of_birth, gender, guardian_id, school_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $studentInsert->execute([
                $schoolId, $requestedClassId > 0 ? $requestedClassId : null,
                $fullName, $admissionNumber !== '' ? $admissionNumber : null, $admissionDate,
                $dob !== '' ? $dob : null, $gender, $guardianId, 'active',
            ]);
            $receivedStudentId = (int) db()->lastInsertId();
            student_class_history_record(
                db(),
                $schoolId,
                $receivedStudentId,
                'transfer_received',
                null,
                $requestedClassId > 0 ? $requestedClassId : null,
                date('Y-m-d'),
                'Authorized student transfer received from school ' . (int) $request['source_school_id'] . '.',
                $userId
            );
            $eventStmt = db()->prepare(
                'INSERT INTO student_lifecycle_events
                 (school_id, student_id, event_type, previous_status, new_status,
                  previous_class_id, new_class_id, effective_on, reason, actor_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $eventStmt->execute([
                $schoolId, $receivedStudentId, 'transfer_received', 'external_transfer',
                'active', null, $requestedClassId > 0 ? $requestedClassId : null,
                date('Y-m-d'),
                'Received authorized transfer request ' . $transferId . ' from school ' . (int) $request['source_school_id'],
                $userId,
            ]);
            $updateStmt = db()->prepare(
                'UPDATE student_record_transfer_requests SET received_student_id = ?, received_at = UTC_TIMESTAMP()
                 WHERE id = ? AND target_school_id = ? AND status = ? AND received_student_id IS NULL'
            );
            $updateStmt->execute([$receivedStudentId, $transferId, $schoolId, 'released']);
            if ($updateStmt->rowCount() !== 1) {
                throw new RuntimeException('The transfer was received by another authorized user. Refresh the page and review the existing record.');
            }
            audit($userId, 'student_record_transfer_received', 'transfer_id=' . $transferId . ';student_id=' . $receivedStudentId);
            db()->commit();
            flash('success', 'The authorized student record has been added to your school. Historical records remain available in the secure transfer package.');
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $exception->getMessage());
        } catch (JsonException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'The released transfer package could not be decoded. Contact the sending school.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        redirect('former_students.php');
    }

    if ($action === 'review_transfer' || $action === 'release_transfer') {
        $transferId = filter_var($_POST['transfer_id'] ?? null, FILTER_VALIDATE_INT);
        if ($transferId === false || $transferId < 1) {
            flash('error', 'Choose a valid transfer request.');
            redirect('former_students.php');
        }
        db()->beginTransaction();
        try {
            $requestStmt = db()->prepare(
                'SELECT * FROM student_record_transfer_requests WHERE id = ? FOR UPDATE'
            );
            $requestStmt->execute([$transferId]);
            $request = $requestStmt->fetch();
            if (!$request) {
                throw new RuntimeException('The transfer request could not be found.');
            }
            if ($action === 'review_transfer') {
                if ((int) $request['target_school_id'] !== $schoolId
                    || $request['status'] !== 'pending_target_approval') {
                    throw new RuntimeException('This transfer request is not awaiting review by your school.');
                }
                $decision = (string) ($_POST['decision'] ?? '');
                if (!in_array($decision, ['approve', 'decline'], true)) {
                    throw new InvalidArgumentException('Choose approve or decline.');
                }
                $newStatus = $decision === 'approve' ? 'target_approved' : 'declined';
                $updateStmt = db()->prepare(
                    'UPDATE student_record_transfer_requests
                     SET status = ?, target_reviewed_by = ?, target_reviewed_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                );
                $updateStmt->execute([$newStatus, $userId, $transferId]);
                audit($userId, 'student_record_transfer_target_' . $decision, 'transfer_id=' . $transferId);
                db()->commit();
                flash('success', $decision === 'approve'
                    ? 'Transfer approved. The source school must separately release the records before your school can access them.'
                    : 'Transfer request declined. No student records were disclosed.');
            } else {
                if ((int) $request['source_school_id'] !== $schoolId || $request['status'] !== 'target_approved') {
                    throw new RuntimeException('This transfer is not approved and awaiting release by your school.');
                }
                $package = student_transfer_package($schoolId, (int) $request['student_id']);
                $packageJson = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $updateStmt = db()->prepare(
                    'UPDATE student_record_transfer_requests
                     SET status = ?, source_released_by = ?, source_released_at = UTC_TIMESTAMP(), package_json = ?
                     WHERE id = ?'
                );
                $updateStmt->execute(['released', $userId, $packageJson, $transferId]);
                audit($userId, 'student_record_transfer_released', 'transfer_id=' . $transferId . ';student_id=' . (int) $request['student_id']);
                db()->commit();
                flash('success', 'The consent-authorized record package has been released to the approved destination school.');
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
            throw $exception;
        }
        redirect('former_students.php');
    }
    flash('error', 'Unsupported student lifecycle action.');
    redirect('former_students.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = substr($search, 0, 100);
$statusFilter = (string) ($_GET['status'] ?? '');
$graduationYearFilter = substr(trim((string) ($_GET['graduation_year'] ?? '')), 0, 30);
$statusOptions = ['transferred', 'withdrawn', 'dropped_out', 'expelled', 'deceased', 'suspended'];
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}
$graduationYearsStmt = db()->prepare(
    'SELECT DISTINCT academic_year FROM student_graduations
     WHERE school_id = ? AND status = "approved" ORDER BY academic_year DESC'
);
$graduationYearsStmt->execute([$schoolId]);
$graduationYears = $graduationYearsStmt->fetchAll(PDO::FETCH_COLUMN);
$graduateSql = 'SELECT ss.id, ss.full_name, ss.admission_number, g.academic_year,
                       COALESCE(g.graduation_date, (SELECT le.exit_date FROM student_lifecycle_events le
                           WHERE le.school_id = ss.school_id AND le.student_id = ss.id AND le.event_type = "exit"
                           ORDER BY le.created_at DESC LIMIT 1)) AS graduation_date,
                       g.id AS graduation_id,
                       (SELECT c.id FROM school_completion_certificates c
                        WHERE c.graduation_id = g.id AND c.status = "issued"
                        ORDER BY c.issued_at DESC LIMIT 1) AS active_certificate_id
                FROM school_students ss
                LEFT JOIN student_graduations g
                  ON g.school_id = ss.school_id AND g.student_id = ss.id AND g.status = "approved"
                WHERE ss.school_id = ? AND ss.school_status = "graduated"';
$graduateParams = [$schoolId];
if ($search !== '') {
    $graduateSql .= ' AND (ss.full_name LIKE ? OR ss.admission_number LIKE ? OR CAST(ss.id AS CHAR) LIKE ?)';
    array_push($graduateParams, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}
if ($graduationYearFilter !== '') {
    $graduateSql .= ' AND g.academic_year = ?';
    $graduateParams[] = $graduationYearFilter;
}
$graduateSql .= ' ORDER BY g.graduation_date DESC, ss.full_name';
$graduateStmt = db()->prepare($graduateSql);
$graduateStmt->execute($graduateParams);
$graduates = $graduateStmt->fetchAll();

$sql = 'SELECT ss.id, ss.full_name, ss.admission_number, ss.school_status, ss.class_id,
               sc.level AS class_level, sc.section AS class_section, sc.academic_year,
               (SELECT le.exit_date FROM student_lifecycle_events le
                WHERE le.school_id = ss.school_id AND le.student_id = ss.id AND le.event_type = "exit"
                ORDER BY le.created_at DESC LIMIT 1) AS exit_date,
               (SELECT le.reason FROM student_lifecycle_events le
                WHERE le.school_id = ss.school_id AND le.student_id = ss.id AND le.event_type = "exit"
                ORDER BY le.created_at DESC LIMIT 1) AS exit_reason
        FROM school_students ss
        LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
        WHERE ss.school_id = ? AND ss.school_status NOT IN ("active", "graduated")';
$params = [$schoolId];
if ($search !== '') {
    $sql .= ' AND (ss.full_name LIKE ? OR ss.admission_number LIKE ? OR CAST(ss.id AS CHAR) LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($statusFilter !== '') {
    $sql .= ' AND ss.school_status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY ss.full_name';
$studentStmt = db()->prepare($sql);
$studentStmt->execute($params);
$formerStudents = $studentStmt->fetchAll();

$documentStmt = db()->prepare(
    'SELECT d.id, d.student_id, d.original_name, d.created_at
     FROM student_lifecycle_documents d
     JOIN school_students ss ON ss.id = d.student_id AND ss.school_id = d.school_id
     WHERE d.school_id = ? ORDER BY d.created_at DESC'
);
$documentStmt->execute([$schoolId]);
$documentsByStudent = [];
foreach ($documentStmt->fetchAll() as $document) {
    $documentsByStudent[(int) $document['student_id']][] = $document;
}

$outgoingStmt = db()->prepare(
    'SELECT r.id, r.status, r.created_at, ss.full_name, ss.admission_number, target.name AS target_school
     FROM student_record_transfer_requests r
     JOIN school_students ss ON ss.id = r.student_id AND ss.school_id = r.source_school_id
     JOIN schools target ON target.id = r.target_school_id
     WHERE r.source_school_id = ? ORDER BY r.created_at DESC'
);
$outgoingStmt->execute([$schoolId]);
$outgoingTransfers = $outgoingStmt->fetchAll();
$incomingStmt = db()->prepare(
    'SELECT r.id, r.status, r.created_at, r.received_student_id,
            ss.full_name, ss.admission_number, source.name AS source_school
     FROM student_record_transfer_requests r
     JOIN school_students ss ON ss.id = r.student_id AND ss.school_id = r.source_school_id
     JOIN schools source ON source.id = r.source_school_id
     WHERE r.target_school_id = ? ORDER BY r.created_at DESC'
);
$incomingStmt->execute([$schoolId]);
$incomingTransfers = $incomingStmt->fetchAll();
render_header('Former students & transfers');
?>
<section class="panel">
    <p class="eyebrow">STUDENT RECORDS</p>
    <h1>Former students &amp; transfers</h1>
    <p class="muted">Exits remove students from active operations without deleting academic or financial history.</p>
</section>

<section class="panel panel-top-gap">
    <h2>Record a student exit</h2>
    <form method="post" action="former_students.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="exit_student">
        <div class="lifecycle-form-grid">
            <div><label for="student_id">Active student</label>
                <select id="student_id" name="student_id" required>
                    <option value="">Choose a student</option>
                    <?php
                    $activeStmt = db()->prepare('SELECT id, full_name, admission_number FROM school_students WHERE school_id = ? AND school_status = "active" ORDER BY full_name');
                    $activeStmt->execute([$schoolId]);
                    foreach ($activeStmt->fetchAll() as $activeStudent):
                    ?>
                        <option value="<?= (int) $activeStudent['id'] ?>"><?= e((string) $activeStudent['full_name']) ?><?= $activeStudent['admission_number'] ? ' · ' . e((string) $activeStudent['admission_number']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="exit_status">Exit status</label>
                <select id="exit_status" name="exit_status" required>
                    <option value="transferred">Transferred</option>
                    <option value="withdrawn">Withdrawn</option>
                    <option value="dropped_out">Dropped out</option>
                    <option value="expelled">Expelled</option>
                    <option value="deceased">Deceased</option>
                </select>
                <small class="muted">Transferred records the exit at this school only; it does not initiate a record transfer to another school.</small>
            </div>
            <div><label for="exit_date">Exit date</label><input id="exit_date" name="exit_date" type="date" required value="<?= e(gmdate('Y-m-d')) ?>"></div>
            <div class="lifecycle-full"><label for="reason">Reason</label><textarea id="reason" name="reason" minlength="5" maxlength="1000" required></textarea></div>
            <div class="lifecycle-full"><label><input type="checkbox" name="confirm_exit" value="1" required> Confirm this exit. The student will leave active lists and future billing, but their historical records will be retained.</label></div>
        </div>
        <button class="button button-primary" type="submit">Record exit</button>
    </form>
</section>

<section class="panel panel-top-gap">
    <h2>Graduated / former students</h2>
    <form method="get" action="former_students.php" class="lifecycle-form-grid">
        <div><label for="graduate-q">Search graduate by name or student ID</label><input id="graduate-q" name="q" value="<?= e($search) ?>" maxlength="100"></div>
        <div><label for="graduation-year">Graduation academic year</label><select id="graduation-year" name="graduation_year"><option value="">All years</option>
            <?php foreach ($graduationYears as $graduationYear): ?><option value="<?= e((string) $graduationYear) ?>" <?= $graduationYearFilter === $graduationYear ? 'selected' : '' ?>><?= e(school_academic_year_label((string) $graduationYear)) ?></option><?php endforeach; ?>
        </select></div>
        <button class="button button-secondary" type="submit">Search graduates</button>
    </form>
    <?php if ($graduates === []): ?><p class="muted">No approved graduates match this search.</p>
    <?php else: ?><div class="table-scroll"><table>        <thead><tr><th>Graduate</th><th>Student ID</th><th>Academic year / approval</th><th>Graduation date</th><th>History / certificate</th><th>Correction</th></tr></thead><tbody>
        <?php foreach ($graduates as $graduate): ?><tr>
            <td><?= e((string) $graduate['full_name']) ?></td>
            <td><?= e((string) ($graduate['admission_number'] ?: $graduate['id'])) ?></td>
            <td><?= $graduate['academic_year'] ? e(school_academic_year_label((string) $graduate['academic_year'])) : 'Legacy status — approval record unavailable' ?></td>
            <td><?= e((string) $graduate['graduation_date']) ?></td>
            <td><?= $graduate['graduation_id'] === null ? '<span class="muted">Review required before certificate issuance</span><br>' : '' ?><a href="report_cards.php?student_id=<?= (int) $graduate['id'] ?>">Historical report card</a>
                <?php if ($graduate['active_certificate_id'] !== null): ?><br><a href="school_certificate.php?id=<?= (int) $graduate['active_certificate_id'] ?>">Issued certificate</a><?php endif; ?>
            </td>
            <td><details><summary>Correct / reinstate</summary>
                <form method="post" action="former_students.php" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reverse_graduation">
                    <input type="hidden" name="student_id" value="<?= (int) $graduate['id'] ?>">
                    <label for="graduate-reclass-<?= (int) $graduate['id'] ?>">Current placement</label>
                    <select id="graduate-reclass-<?= (int) $graduate['id'] ?>" name="class_id" required><option value="">Choose class</option>
                        <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option><?php endforeach; ?>
                    </select>
                    <label for="graduate-reason-<?= (int) $graduate['id'] ?>">Correction reason</label>
                    <textarea id="graduate-reason-<?= (int) $graduate['id'] ?>" name="reason" minlength="5" maxlength="1000" required></textarea>
                    <label><input type="checkbox" name="confirm_reverse" value="1" required> Confirm graduation reversal and student reinstatement</label>
                    <button class="button button-quiet" type="submit">Reverse graduation</button>
                </form>
            </details></td>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel panel-top-gap">
    <h2>Archived / former students</h2>
    <form method="get" action="former_students.php" class="lifecycle-form-grid">
        <div><label for="former-q">Search by name, student ID, or admission number</label><input id="former-q" name="q" value="<?= e($search) ?>" maxlength="100"></div>
        <div><label for="status">Filter by status</label><select id="status" name="status"><option value="">All former statuses</option>
            <?php foreach ($statusOptions as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?>
        </select></div>
        <button class="button button-secondary" type="submit">Search</button>
    </form>
    <?php if ($formerStudents === []): ?>
        <p class="muted">No former students match this search.</p>
    <?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Student</th><th>Status</th><th>Previous class</th><th>Exit date</th><th>Reason</th><th>Documents</th><th>Reinstate</th></tr></thead><tbody>
        <?php foreach ($formerStudents as $former): ?>
            <tr>
                <td><?= e((string) $former['full_name']) ?><br><small><?= e((string) ($former['admission_number'] ?: 'No admission number')) ?></small></td>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $former['school_status']))) ?></td>
                <td><?= e($former['class_level'] ? school_class_label(['level' => $former['class_level'], 'section' => $former['class_section']]) . ' · ' . school_academic_year_label((string) $former['academic_year']) : '—') ?></td>
                <td><?= e((string) ($former['exit_date'] ?: '—')) ?></td>
                <td><?= e((string) ($former['exit_reason'] ?: '—')) ?></td>
                <td><?php foreach ($documentsByStudent[(int) $former['id']] ?? [] as $document): ?><a href="student_lifecycle_document.php?id=<?= (int) $document['id'] ?>"><?= e((string) $document['original_name']) ?></a><br><?php endforeach; ?></td>
                <td><form method="post" action="former_students.php" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reinstate_student"><input type="hidden" name="student_id" value="<?= (int) $former['id'] ?>">
                    <label for="reclass-<?= (int) $former['id'] ?>">Placement</label><select id="reclass-<?= (int) $former['id'] ?>" name="class_id"><option value="0">Keep previous placement</option>
                        <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option><?php endforeach; ?>
                    </select><label><input type="checkbox" name="confirm_reinstate" value="1" required> Reinstate this existing record</label><button class="button button-secondary" type="submit">Reinstate</button>
                </form></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel panel-top-gap">
    <h2>Incoming record transfer requests</h2>
    <?php if ($incomingTransfers === []): ?><p class="muted">No schools have requested a record transfer to this school.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Student</th><th>Sending school</th><th>Request status</th><th>Action / record package</th></tr></thead><tbody>
        <?php foreach ($incomingTransfers as $transfer): ?><tr>
            <td><?= e((string) $transfer['full_name']) ?><br><small><?= e((string) ($transfer['admission_number'] ?: 'No admission number')) ?></small></td>
            <td><?= e((string) $transfer['source_school']) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string) $transfer['status']))) ?></td><td>
                <?php if ($transfer['status'] === 'pending_target_approval'): ?><form method="post" action="former_students.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="review_transfer"><input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>"><button class="button button-primary" name="decision" value="approve" type="submit">Approve</button><button class="button button-secondary" name="decision" value="decline" type="submit">Decline</button></form>
                <?php elseif ($transfer['status'] === 'released'): ?>
                    <a class="button button-primary" href="student_transfer_package.php?id=<?= (int) $transfer['id'] ?>">View / download released records</a>
                    <?php if ($transfer['received_student_id'] === null): ?>
                        <form method="post" action="former_students.php" class="lifecycle-form-grid panel-top-gap-xs">
                            <?= csrf_field() ?><input type="hidden" name="action" value="receive_transfer"><input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>">
                            <div><label for="receive-class-<?= (int) $transfer['id'] ?>">Place in class (optional)</label><select id="receive-class-<?= (int) $transfer['id'] ?>" name="class_id"><option value="0">No class yet</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option><?php endforeach; ?></select></div>
                            <div class="lifecycle-full"><label><input type="checkbox" name="confirm_receive" value="1" required> Create the receiving school's student record from this authorized transfer.</label></div>
                            <button class="button button-primary" type="submit">Receive student record</button>
                        </form>
                    <?php else: ?><p class="muted">Received as this school's student record.</p><?php endif; ?>
                <?php else: ?>No records disclosed<?php endif; ?>
            </td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel panel-top-gap">
    <h2>Outgoing record transfer requests</h2>
    <?php if ($outgoingTransfers === []): ?><p class="muted">No transfer requests have been sent.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Student</th><th>Destination school</th><th>Request status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($outgoingTransfers as $transfer): ?><tr>
            <td><?= e((string) $transfer['full_name']) ?><br><small><?= e((string) ($transfer['admission_number'] ?: 'No admission number')) ?></small></td><td><?= e((string) $transfer['target_school']) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string) $transfer['status']))) ?></td><td>
                <?php if ($transfer['status'] === 'target_approved'): ?><form method="post" action="former_students.php"><?= csrf_field() ?><input type="hidden" name="action" value="release_transfer"><input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>"><button class="button button-primary" type="submit">Release records</button></form>
                <?php elseif ($transfer['status'] === 'released'): ?>Released with authorization
                <?php elseif ($transfer['status'] === 'declined'): ?>Declined
                <?php else: ?>Awaiting destination approval<?php endif; ?>
            </td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>

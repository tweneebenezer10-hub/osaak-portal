<?php
declare(strict_types=1);
require_once __DIR__ . '/student_class_history.php';

function store_student_lifecycle_document(array $upload): ?array
{
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The document exceeds the server upload size limit.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The supporting document upload failed. Check the server upload settings and try again.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Choose a valid supporting document.');
    }
    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize < 1 || $fileSize > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Supporting documents must be between 1 byte and 10 MB.');
    }

    $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimeTypes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];
    if (!isset($allowedMimeTypes[$extension])) {
        throw new InvalidArgumentException('Supporting documents must be PDF, JPG, PNG, or WebP files.');
    }
    if (!class_exists(finfo::class)) {
        throw new RuntimeException('The PHP Fileinfo extension is required to validate supporting documents.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
        throw new InvalidArgumentException('The document contents do not match an allowed file type.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
    $originalName = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '_', $originalName));
    if ($originalName === '' || strlen($originalName) > 255) {
        throw new InvalidArgumentException('The document file name must be between 1 and 255 characters.');
    }

    $directory = APP_ROOT . '/storage/student-lifecycle';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The private student document directory could not be created.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $path = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $path)) {
        throw new RuntimeException('The supporting document could not be stored.');
    }

    return [
        'stored_name' => $storedName,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'path' => $path,
    ];
}

function student_transfer_package(int $schoolId, int $studentId): array
{
    $studentStmt = db()->prepare(
        'SELECT ss.id, ss.full_name, ss.admission_number, ss.admission_date, ss.date_of_birth, ss.gender,
                ss.school_status, ss.created_at, sc.level AS class_level, sc.section AS class_section,
                sc.academic_year AS class_academic_year, sg.full_name AS guardian_name,
                sg.email AS guardian_email, sg.phone AS guardian_phone
         FROM school_students ss
         LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         LEFT JOIN school_guardians sg ON sg.id = ss.guardian_id AND sg.school_id = ss.school_id
         WHERE ss.id = ? AND ss.school_id = ? LIMIT 1'
    );
    $studentStmt->execute([$studentId, $schoolId]);
    $student = $studentStmt->fetch();
    if (!$student) {
        throw new RuntimeException('The student record is no longer available at the source school.');
    }

    $attendanceStmt = db()->prepare(
        'SELECT attendance_date, status, remarks
         FROM student_attendance WHERE school_id = ? AND student_id = ?
         ORDER BY attendance_date'
    );
    $attendanceStmt->execute([$schoolId, $studentId]);

    $resultsStmt = db()->prepare(
        'SELECT sa.assessment_name, sa.term_name, ss.name AS subject_name,
                sc.level AS class_level, sc.academic_year, ar.score, ar.grade, ar.remarks,
                ar.created_at
         FROM assessment_results ar
         JOIN school_assessments sa ON sa.id = ar.assessment_id AND sa.school_id = ar.school_id
         JOIN school_subjects ss ON ss.id = sa.subject_id AND ss.school_id = sa.school_id
         JOIN school_classes sc ON sc.id = sa.class_id AND sc.school_id = sa.school_id
         WHERE ar.school_id = ? AND ar.student_id = ?
         ORDER BY sc.academic_year, sa.term_name, sa.assessment_name, ss.name'
    );
    $resultsStmt->execute([$schoolId, $studentId]);

    if (student_class_history_schema_ready()) {
        $lifecycleStmt = db()->prepare(
            'SELECT event.event_type, event.previous_status, event.new_status,
                    COALESCE(event.effective_on, event.exit_date, DATE(event.created_at)) AS effective_on,
                    event.reason, event.created_at, actor.full_name AS actor_name
             FROM student_lifecycle_events event
             LEFT JOIN users actor ON actor.id = event.actor_user_id
             WHERE event.school_id = ? AND event.student_id = ?
             ORDER BY event.effective_on, event.id'
        );
        $classHistoryStmt = db()->prepare(
            'SELECT history.event_type, history.previous_class_snapshot,
                    history.new_class_snapshot, history.previous_academic_year,
                    history.new_academic_year, history.effective_on, history.reason,
                    history.created_at, actor.full_name AS actor_name
             FROM school_student_class_history history
             LEFT JOIN users actor ON actor.id = history.actor_user_id
             WHERE history.school_id = ? AND history.student_id = ?
             ORDER BY history.effective_on, history.id'
        );
        $classHistoryStmt->execute([$schoolId, $studentId]);
    } else {
        $lifecycleStmt = db()->prepare(
            'SELECT event.event_type, event.previous_status, event.new_status,
                    COALESCE(event.exit_date, DATE(event.created_at)) AS effective_on,
                    event.reason, event.created_at, actor.full_name AS actor_name
             FROM student_lifecycle_events event
             LEFT JOIN users actor ON actor.id = event.actor_user_id
             WHERE event.school_id = ? AND event.student_id = ?
             ORDER BY event.created_at, event.id'
        );
        $classHistoryStmt = null;
    }
    $lifecycleStmt->execute([$schoolId, $studentId]);

    $invoiceStmt = db()->prepare(
        'SELECT si.invoice_number, si.class_level, si.academic_year, si.period_name,
                si.amount, si.balance, si.status, si.due_date, si.created_at,
                COALESCE((
                    SELECT SUM(allocation.amount)
                    FROM school_payment_allocations allocation
                    JOIN school_payments payment
                      ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
                     AND payment.student_id = allocation.student_id
                    WHERE allocation.school_id = si.school_id AND allocation.student_id = si.student_id
                      AND allocation.invoice_id = si.id AND allocation.status = "active"
                      AND payment.status = "verified"
                ), 0) AS verified_allocated_total,
                COALESCE((
                    SELECT SUM(allocation.amount)
                    FROM school_payment_allocations allocation
                    JOIN school_payments payment
                      ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
                     AND payment.student_id = allocation.student_id
                    WHERE allocation.school_id = si.school_id AND allocation.student_id = si.student_id
                      AND allocation.invoice_id = si.id AND allocation.status = "pending"
                      AND payment.status = "pending"
                ), 0) AS pending_allocated_total
         FROM school_invoices si WHERE si.school_id = ? AND si.student_id = ?
         ORDER BY si.created_at'
    );
    $invoiceStmt->execute([$schoolId, $studentId]);
    $invoices = $invoiceStmt->fetchAll();
    $paymentStmt = db()->prepare(
        'SELECT id, invoice_id, amount, payment_coverage, payment_reference, payment_method, status, received_at, verified_at
         FROM school_payments WHERE school_id = ? AND student_id = ? ORDER BY received_at'
    );
    $paymentStmt->execute([$schoolId, $studentId]);
    $paymentHistory = $paymentStmt->fetchAll();
    $paymentIds = array_map(static fn(array $payment): int => (int) $payment['id'], $paymentHistory);
    foreach ($paymentHistory as &$paymentRow) {
        $paymentRow['allocations'] = [];
        $paymentRow['credit_surplus_amount'] = '0.00';
        $paymentRow['credit_available_amount'] = '0.00';
    }
    unset($paymentRow);
    if ($paymentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $allocationStmt = db()->prepare(
            'SELECT allocation.payment_id, allocation.invoice_id, allocation.amount,
                    allocation.allocation_type, allocation.status AS allocation_status,
                    invoice.invoice_number, invoice.academic_year, invoice.period_name
             FROM school_payment_allocations allocation
             JOIN school_invoices invoice
               ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
              AND invoice.student_id = allocation.student_id
             WHERE allocation.school_id = ? AND allocation.student_id = ?
               AND allocation.payment_id IN (' . $placeholders . ')
             ORDER BY allocation.payment_id, allocation.id'
        );
        $allocationStmt->execute(array_merge([$schoolId, $studentId], $paymentIds));
        $paymentIndex = [];
        foreach ($paymentHistory as $index => $paymentRow) {
            $paymentIndex[(int) $paymentRow['id']] = $index;
        }
        foreach ($allocationStmt->fetchAll() as $allocationRow) {
            $paymentHistory[$paymentIndex[(int) $allocationRow['payment_id']]]['allocations'][] = [
                'invoice_id' => (int) $allocationRow['invoice_id'],
                'invoice_number' => (string) $allocationRow['invoice_number'],
                'academic_year' => $allocationRow['academic_year'],
                'period_name' => $allocationRow['period_name'],
                'amount' => $allocationRow['amount'],
                'allocation_type' => (string) $allocationRow['allocation_type'],
                'status' => (string) $allocationRow['allocation_status'],
            ];
        }
        $creditStmt = db()->prepare(
            'SELECT credit.payment_id, credit.surplus_amount,
                    credit.surplus_amount - COALESCE(SUM(allocation.amount), 0) AS available_amount
             FROM school_fee_credit_sources credit
             JOIN school_payments payment
               ON payment.id = credit.payment_id AND payment.school_id = credit.school_id
              AND payment.student_id = credit.student_id AND payment.status = "verified"
             LEFT JOIN school_payment_allocations allocation
               ON allocation.school_id = credit.school_id
              AND allocation.payment_id = credit.payment_id
              AND allocation.allocation_type = "automatic_credit" AND allocation.status = "active"
             WHERE credit.school_id = ? AND credit.student_id = ?
               AND credit.payment_id IN (' . $placeholders . ')
             GROUP BY credit.payment_id, credit.surplus_amount'
        );
        $creditStmt->execute(array_merge([$schoolId, $studentId], $paymentIds));
        foreach ($creditStmt->fetchAll() as $creditRow) {
            $index = $paymentIndex[(int) $creditRow['payment_id']];
            $paymentHistory[$index]['credit_surplus_amount'] = $creditRow['surplus_amount'];
            $paymentHistory[$index]['credit_available_amount'] = $creditRow['available_amount'];
        }
    }

    return [
        'exported_at' => gmdate('c'),
        'source_school_id' => $schoolId,
        'student' => $student,
        'attendance_history' => $attendanceStmt->fetchAll(),
        'assessment_results' => $resultsStmt->fetchAll(),
        'lifecycle_history' => $lifecycleStmt->fetchAll(),
        'class_history' => $classHistoryStmt ? $classHistoryStmt->fetchAll() : [],
        'invoices' => $invoices,
        'payment_history' => $paymentHistory,
        'notice' => 'This consent-authorized transfer copy does not delete or alter the source school records.',
    ];
}

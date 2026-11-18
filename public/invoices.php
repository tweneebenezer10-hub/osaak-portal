<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$pageAcademicPeriod = school_page_academic_period($schoolId);
require_school_permission($schoolId, 'fees.manage');
$canVerifyPayments = school_has_permission($user, $schoolId, 'payments.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate_period_billing') {
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        if ($termId === false || $termId < 1) {
            flash('error', 'Select a valid academic period.');
            redirect('invoices.php');
        }
        $termStmt = db()->prepare(
            'SELECT academic_year.academic_year, term.term_name
             FROM school_terms term
             JOIN school_academic_years academic_year
               ON academic_year.id = term.academic_year_id AND academic_year.school_id = term.school_id
             WHERE term.id = ? AND term.school_id = ?'
        );
        $termStmt->execute([$termId, $schoolId]);
        $term = $termStmt->fetch();
        if (!$term) {
            flash('error', 'Academic period does not belong to this school.');
            redirect('invoices.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $result = school_assess_term_invoices($schoolId, $termId, null, (int) $user['id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Bulk school billing failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Billing could not be completed. No invoices from this batch were saved.');
            redirect('invoices.php');
        }
        audit((int) $user['id'], 'school_period_billing_generated', 'school_id=' . $schoolId . '; term_id=' . $termId . '; created=' . $result['created'] . '; existing=' . $result['existing'] . '; missing_class_or_year=' . $result['without_class_or_year'] . '; admission_date=' . $result['not_yet_enrolled'] . '; missing_schedule=' . $result['without_schedule']);
        foreach ($result['created_invoices'] as [$studentId, $invoiceId]) {
            school_fee_invoice_parent_notice($schoolId, $studentId, 'School invoice issued', 'A new invoice has been issued for ' . $term['term_name'] . ' ' . $term['academic_year'] . '.', $invoiceId);
        }
        if (!$result['supported']) {
            flash('error', 'Only First Term, Second Term, and Third Term can be assessed. No invoices were created.');
        } else {
            flash('success', $result['created'] . ' invoices generated; ' . $result['existing'] . ' already existed; '
                . $result['without_class_or_year'] . ' skipped for missing/incorrect class year; '
                . $result['not_yet_enrolled'] . ' skipped for missing/admission date after term end; '
                . $result['without_schedule'] . ' skipped for missing matching fee schedule.');
        }
        redirect('invoices.php');
    }

    if ($action === 'generate_invoice') {
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId < 1 || $termId === false || $termId < 1) {
            flash('error', 'Select a valid student and academic period.');
            redirect('invoices.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $result = school_assess_term_invoices($schoolId, $termId, $studentId, (int) $user['id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('School invoice generation failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The invoice could not be generated.');
            redirect('invoices.php');
        }
        if (!$result['supported']) {
            flash('error', 'Only First Term, Second Term, and Third Term can be assessed.');
            redirect('invoices.php');
        }
        if ($result['created'] === 0) {
            $skip = $result['skipped_students'][0] ?? null;
            flash('error', $result['existing'] > 0
                ? 'An invoice already exists for this student and period.'
                : ($skip !== null
                    ? 'No invoice was created for ' . $skip['full_name'] . ': ' . $skip['reason']
                    : 'No invoice was created. Confirm the student, selected term, class/year assignment, admission date, and fee schedule.'));
            redirect('invoices.php');
        }
        $invoiceId = (int) $result['created_invoices'][0][1];
        audit((int) $user['id'], 'school_invoice_generated', 'school_id=' . $schoolId . '; invoice_id=' . $invoiceId);
        $termStmt = $pdo->prepare(
            'SELECT academic_year.academic_year, term.term_name
             FROM school_terms term
             JOIN school_academic_years academic_year
               ON academic_year.id = term.academic_year_id AND academic_year.school_id = term.school_id
             WHERE term.id = ? AND term.school_id = ?'
        );
        $termStmt->execute([$termId, $schoolId]);
        $term = $termStmt->fetch();
        school_fee_invoice_parent_notice($schoolId, $studentId, 'School invoice issued', 'A new invoice has been issued for ' . $term['term_name'] . ' ' . $term['academic_year'] . '.', $invoiceId);
        flash('success', 'Invoice generated from the applicable fee schedule.');
        redirect('invoices.php');
    }

    if ($action === 'record_payment' || $action === 'verify_payment' || $action === 'reject_payment') {
        require_school_permission($schoolId, 'payments.manage');
        $pdo = db();
        if ($action === 'record_payment') {
            $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            $amountCents = school_fee_amount_cents($_POST['amount'] ?? null);
            $coverage = (string) ($_POST['payment_coverage'] ?? '');
            $method = (string) ($_POST['payment_method'] ?? '');
            $reference = trim((string) ($_POST['payment_reference'] ?? ''));
            $submittedAllocations = $_POST['allocations'] ?? null;
            if ($invoiceId === false || $invoiceId < 1 || $amountCents === null || $amountCents <= 0
                || !is_array($submittedAllocations)
                || !in_array($coverage, ['part', 'term', 'academic_year'], true)
                || !in_array($method, ['cash','bank_transfer','mobile_money','card','offline'], true)) {
                flash('error', 'Select a payment coverage, enter the amount actually received, and explicitly allocate it to one or more invoices.');
                redirect('invoices.php');
            }

            $allocations = [];
            $allocationTotalCents = 0;
            foreach ($submittedAllocations as $targetInvoiceId => $rawAmount) {
                if (!is_string($targetInvoiceId) && !is_int($targetInvoiceId)) {
                    flash('error', 'Invoice allocations must identify valid invoices.');
                    redirect('invoices.php');
                }
                $targetInvoiceId = filter_var((string) $targetInvoiceId, FILTER_VALIDATE_INT);
                $allocationCents = school_fee_amount_cents($rawAmount);
                if ($targetInvoiceId === false || $targetInvoiceId < 1 || $allocationCents === null) {
                    flash('error', 'Enter valid invoice allocation amounts.');
                    redirect('invoices.php');
                }
                if ($allocationCents === 0) {
                    continue;
                }
                $allocations[(int) $targetInvoiceId] = $allocationCents;
                $allocationTotalCents += $allocationCents;
            }
            if ($allocationTotalCents <= 0 || !isset($allocations[(int) $invoiceId]) || $allocationTotalCents > $amountCents) {
                flash('error', 'Explicitly allocate a positive amount to the selected invoice; allocations cannot exceed the payment total.');
                redirect('invoices.php');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
                if ($reference !== '') {
                    $refCheck = $pdo->prepare('SELECT 1 FROM school_payments WHERE school_id = ? AND payment_reference = ? LIMIT 1');
                    $refCheck->execute([$schoolId, $reference]);
                    if ($refCheck->fetchColumn()) {
                        throw new RuntimeException('That payment reference has already been recorded for this school.');
                    }
                }
                $invoiceIds = array_keys($allocations);
                sort($invoiceIds, SORT_NUMERIC);
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $invoiceStmt = $pdo->prepare(
                    'SELECT invoice.id, invoice.student_id, invoice.balance, term.term_name
                     FROM school_invoices invoice
                    JOIN school_academic_years academic_year
                      ON academic_year.school_id = invoice.school_id
                     AND academic_year.academic_year = invoice.academic_year
                    JOIN school_terms term
                      ON term.school_id = academic_year.school_id
                     AND term.academic_year_id = academic_year.id
                     AND term.term_name = invoice.period_name
                     WHERE invoice.school_id = ? AND invoice.id IN (' . $placeholders . ')
                     ORDER BY invoice.id FOR UPDATE'
                );
                $invoiceStmt->execute(array_merge([$schoolId], $invoiceIds));
                $invoiceRows = $invoiceStmt->fetchAll();
                if (count($invoiceRows) !== count($invoiceIds)) {
                    throw new RuntimeException('Every allocation must target an existing configured term invoice in this school.');
                }
                $studentId = (int) $invoiceRows[0]['student_id'];
                $invoiceById = [];
                foreach ($invoiceRows as $invoiceRow) {
                    if ((int) $invoiceRow['student_id'] !== $studentId || !school_fee_supported_term((string) $invoiceRow['term_name'])) {
                        throw new RuntimeException('Manual allocations must target supported term invoices for one student in this school.');
                    }
                    $invoiceById[(int) $invoiceRow['id']] = $invoiceRow;
                    if ($allocations[(int) $invoiceRow['id']] > (int) round((float) $invoiceRow['balance'] * 100)) {
                        throw new RuntimeException('An allocation cannot exceed its invoice’s current outstanding balance.');
                    }
                }
                $studentLock = $pdo->prepare('SELECT id FROM school_students WHERE id = ? AND school_id = ? FOR UPDATE');
                $studentLock->execute([$studentId, $schoolId]);
                if (!$studentLock->fetchColumn()) {
                    throw new RuntimeException('The invoices do not belong to an active student in this school.');
                }
                $insert = $pdo->prepare(
                    'INSERT INTO school_payments
                     (school_id, invoice_id, student_id, amount, payment_coverage, payment_method, payment_reference, status, received_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?)'
                );
                $insert->execute([
                    $schoolId, $invoiceId, $studentId, number_format($amountCents / 100, 2, '.', ''),
                    $coverage, $method, $reference ?: null, (int) $user['id'],
                ]);
                $paymentId = (int) $pdo->lastInsertId();
                $allocationInsert = $pdo->prepare(
                    'INSERT INTO school_payment_allocations
                     (school_id, student_id, payment_id, invoice_id, amount, allocation_type, status, created_by)
                     VALUES (?, ?, ?, ?, ?, "manual", "pending", ?)'
                );
                foreach ($allocations as $targetInvoiceId => $allocationCents) {
                    $allocationInsert->execute([
                        $schoolId,
                        $studentId,
                        $paymentId,
                        $targetInvoiceId,
                        number_format($allocationCents / 100, 2, '.', ''),
                        (int) $user['id'],
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($exception instanceof RuntimeException) {
                    flash('error', $exception->getMessage());
                } else {
                    error_log('School payment allocation recording failed: ' . $exception->getMessage());
                    flash('error', 'Payment and allocations could not be recorded. No balances changed.');
                }
                redirect('invoices.php');
            }
            audit((int) $user['id'], 'school_payment_recorded_pending', 'school_id=' . $schoolId . '; invoice_id=' . $invoiceId);
            audit((int) $user['id'], 'school_payment_allocations_recorded_pending', 'school_id=' . $schoolId . '; payment_id=' . $paymentId . '; allocation_count=' . count($allocations));
            flash('success', 'Payment and explicit invoice allocations recorded as pending verification; no balances or credits changed.');
            redirect('fee_management.php?section=student-accounts&student_id=' . $studentId);
        }

        $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($paymentId === false || $paymentId < 1) {
            flash('error', 'Select a valid payment record.');
            redirect('invoices.php');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
            $paymentStmt = $pdo->prepare('SELECT p.* FROM school_payments p WHERE p.id = ? AND p.school_id = ? FOR UPDATE');
            $paymentStmt->execute([$paymentId, $schoolId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['status'] !== 'pending') {
                throw new RuntimeException('Only pending payments can be verified or rejected.');
            }
            $studentLock = $pdo->prepare('SELECT id FROM school_students WHERE id = ? AND school_id = ? FOR UPDATE');
            $studentLock->execute([(int) $payment['student_id'], $schoolId]);
            if (!$studentLock->fetchColumn()) {
                throw new RuntimeException('The payment student does not belong to this school.');
            }
            $allocationStmt = $pdo->prepare(
                'SELECT id, invoice_id, amount FROM school_payment_allocations
                 WHERE payment_id = ? AND school_id = ? AND student_id = ? AND status = "pending"
                 ORDER BY invoice_id FOR UPDATE'
            );
            $allocationStmt->execute([$paymentId, $schoolId, (int) $payment['student_id']]);
            $allocations = $allocationStmt->fetchAll();
            if ($allocations === []) {
                throw new RuntimeException('This payment has no explicit invoice allocations; send it for financial review.');
            }
            $allocationTotalCents = 0;
            $targetInvoiceIds = [];
            foreach ($allocations as $allocation) {
                $allocationTotalCents += (int) round((float) $allocation['amount'] * 100);
                $targetInvoiceIds[] = (int) $allocation['invoice_id'];
            }
            $paymentCents = (int) round((float) $payment['amount'] * 100);
            if ($allocationTotalCents <= 0 || $allocationTotalCents > $paymentCents) {
                throw new RuntimeException('The recorded allocations do not reconcile to the payment amount.');
            }
            sort($targetInvoiceIds, SORT_NUMERIC);
            $placeholders = implode(',', array_fill(0, count($targetInvoiceIds), '?'));
            $lockedInvoicesStmt = $pdo->prepare(
                'SELECT id, student_id, amount, balance, status
                 FROM school_invoices
                 WHERE school_id = ? AND student_id = ? AND id IN (' . $placeholders . ')
                 ORDER BY id FOR UPDATE'
            );
            $lockedInvoicesStmt->execute(array_merge([$schoolId, (int) $payment['student_id']], $targetInvoiceIds));
            $lockedInvoices = [];
            foreach ($lockedInvoicesStmt->fetchAll() as $invoiceRow) {
                $lockedInvoices[(int) $invoiceRow['id']] = $invoiceRow;
            }
            if (count($lockedInvoices) !== count(array_unique($targetInvoiceIds))) {
                throw new RuntimeException('An allocated invoice no longer belongs to this school and student.');
            }
            if ($action === 'verify_payment') {
                foreach ($allocations as $allocation) {
                    $invoiceId = (int) $allocation['invoice_id'];
                    if ((int) round((float) $allocation['amount'] * 100) > (int) round((float) $lockedInvoices[$invoiceId]['balance'] * 100)) {
                        throw new RuntimeException('An allocation exceeds its invoice’s current balance. Reject this payment and record a corrected allocation for review.');
                    }
                }
                $pdo->prepare('UPDATE school_payments SET status = "verified", verified_by = ?, verified_at = NOW() WHERE id = ? AND school_id = ?')
                    ->execute([(int) $user['id'], $paymentId, $schoolId]);
                $pdo->prepare('UPDATE school_payment_allocations SET status = "active" WHERE payment_id = ? AND school_id = ? AND status = "pending"')
                    ->execute([$paymentId, $schoolId]);
                foreach ($allocations as $allocation) {
                    $invoiceId = (int) $allocation['invoice_id'];
                    $balanceCents = (int) round((float) $lockedInvoices[$invoiceId]['balance'] * 100)
                        - (int) round((float) $allocation['amount'] * 100);
                    school_fee_write_invoice_balance(
                        $pdo,
                        $schoolId,
                        $invoiceId,
                        $balanceCents,
                        (int) round((float) $lockedInvoices[$invoiceId]['amount'] * 100),
                        (string) $lockedInvoices[$invoiceId]['status']
                    );
                }
                $surplusCents = $paymentCents - $allocationTotalCents;
                if ($surplusCents > 0) {
                    $anchorStmt = $pdo->prepare(
                        'SELECT invoice.academic_year, invoice.period_name, term.id
                         FROM school_invoices invoice
                         JOIN school_academic_years academic_year
                           ON academic_year.school_id = invoice.school_id
                          AND academic_year.academic_year = invoice.academic_year
                         LEFT JOIN school_terms term
                           ON term.school_id = academic_year.school_id
                          AND term.academic_year_id = academic_year.id
                          AND term.term_name = invoice.period_name
                         WHERE invoice.id = ? AND invoice.school_id = ? AND invoice.student_id = ?'
                    );
                    $anchorStmt->execute([(int) $payment['invoice_id'], $schoolId, (int) $payment['student_id']]);
                    $anchorPeriod = $anchorStmt->fetch();
                    if (!$anchorPeriod || !$anchorPeriod['id'] || !school_fee_supported_term((string) $anchorPeriod['period_name'])) {
                        throw new RuntimeException('Surplus cannot be carried from an unmapped or unsupported term invoice; allocate the full payment manually or refer it for financial review.');
                    }
                    $pdo->prepare(
                        'INSERT INTO school_fee_credit_sources (school_id, student_id, payment_id, surplus_amount, created_by)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([
                        $schoolId,
                        (int) $payment['student_id'],
                        $paymentId,
                        number_format($surplusCents / 100, 2, '.', ''),
                        (int) $user['id'],
                    ]);
                }
                $appliedCreditCents = school_apply_future_fee_credits(
                    $pdo,
                    $schoolId,
                    (int) $payment['student_id'],
                    (int) $user['id']
                );
            } else {
                $pdo->prepare('UPDATE school_payments SET status = "rejected", verified_by = ?, verified_at = NOW() WHERE id = ? AND school_id = ?')
                    ->execute([(int) $user['id'], $paymentId, $schoolId]);
                $pdo->prepare('UPDATE school_payment_allocations SET status = "void" WHERE payment_id = ? AND school_id = ? AND status = "pending"')
                    ->execute([$paymentId, $schoolId]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
            } else {
                error_log('School payment verification failed: ' . $exception->getMessage());
                flash('error', 'Payment verification failed. No balance was changed.');
            }
            redirect('invoices.php');
        }
        audit((int) $user['id'], 'school_payment_' . ($action === 'verify_payment' ? 'verified' : 'rejected'), 'school_id=' . $schoolId . '; payment_id=' . $paymentId);
        if ($action === 'verify_payment') {
            audit((int) $user['id'], 'school_payment_allocations_verified', 'school_id=' . $schoolId . '; payment_id=' . $paymentId . '; allocation_count=' . count($allocations) . '; surplus_cents=' . max(0, $paymentCents - $allocationTotalCents) . '; credit_applied_cents=' . $appliedCreditCents);
            $academicYearsStmt = db()->prepare(
                'SELECT DISTINCT academic_year FROM school_invoices
                 WHERE school_id = ? AND student_id = ? AND id IN (' . $placeholders . ') AND academic_year IS NOT NULL'
            );
            $academicYearsStmt->execute(array_merge([$schoolId, (int) $payment['student_id']], $targetInvoiceIds));
            foreach ($academicYearsStmt->fetchAll(PDO::FETCH_COLUMN) as $academicYear) {
                $clearance = school_student_fee_clearance((int) $payment['student_id'], $schoolId, (string) $academicYear);
                audit((int) $user['id'], 'school_fee_clearance_rechecked', 'school_id=' . $schoolId . '; student_id=' . $payment['student_id'] . ';academic_year=' . $academicYear . ';status=' . $clearance['status']);
            }
            school_fee_invoice_parent_notice($schoolId, (int) $payment['student_id'], 'School payment verified', 'A payment was verified and applied to invoice ' . $payment['invoice_id'] . '.', (int) $payment['invoice_id']);
        }
        flash('success', $action === 'verify_payment' ? 'Payment verified and applied to the invoice. Its receipt is now available in the student account.' : 'Payment rejected; invoice balance was unchanged.');
        $returnStudentId = filter_var($_POST['return_student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($returnStudentId !== false && $returnStudentId > 0) {
            redirect('fee_management.php?section=student-accounts&student_id=' . $returnStudentId);
        }
        redirect('invoices.php');
    }

    if ($action === 'request_adjustment' || $action === 'decide_adjustment') {
        require_school_permission($schoolId, 'payments.manage');
        $pdo = db();
        if ($action === 'request_adjustment') {
            $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            $type = (string) ($_POST['adjustment_type'] ?? '');
            $amount = filter_var($_POST['adjustment_amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
            if ($invoiceId === false || $invoiceId < 1 || !in_array($type, ['discount','scholarship','waiver','payment_arrangement'], true)
                || ($type !== 'payment_arrangement' && ($amount === false || $amount <= 0))
                || ($type === 'payment_arrangement' && ($validUntil === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)))
                || $reason === '' || ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil))) {
                flash('error', 'Enter a valid adjustment type, amount where applicable, reason, and arrangement end date.');
                redirect('invoices.php');
            }
            $invoiceCheck = $pdo->prepare('SELECT id FROM school_invoices WHERE id = ? AND school_id = ?');
            $invoiceCheck->execute([$invoiceId, $schoolId]);
            if (!$invoiceCheck->fetchColumn()) {
                flash('error', 'Invoice not found in this school.');
                redirect('invoices.php');
            }
            $pdo->prepare(
                'INSERT INTO school_invoice_adjustments (school_id, invoice_id, adjustment_type, amount, effective_on, valid_until, reason, requested_by)
                 VALUES (?, ?, ?, ?, CURRENT_DATE(), ?, ?, ?)'
            )->execute([$schoolId, $invoiceId, $type, $type === 'payment_arrangement' ? null : number_format((float) $amount, 2, '.', ''),
                $validUntil ?: null, $reason, (int) $user['id']]);
            audit((int) $user['id'], 'school_invoice_adjustment_requested', 'school_id=' . $schoolId . '; invoice_id=' . $invoiceId . '; type=' . $type);
            flash('success', 'Adjustment submitted as pending approval.');
            redirect('invoices.php');
        }
        $adjustmentId = filter_var($_POST['adjustment_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string) ($_POST['decision'] ?? '');
        if ($adjustmentId === false || $adjustmentId < 1 || !in_array($decision, ['approved','rejected'], true)) {
            flash('error', 'Select a valid adjustment decision.');
            redirect('invoices.php');
        }
        $pdo->beginTransaction();
        try {
            $adjustmentStmt = $pdo->prepare(
                'SELECT a.*, i.balance, i.status AS invoice_status, i.student_id, i.academic_year FROM school_invoice_adjustments a
                 JOIN school_invoices i ON i.id = a.invoice_id AND i.school_id = a.school_id
                 WHERE a.id = ? AND a.school_id = ? FOR UPDATE'
            );
            $adjustmentStmt->execute([$adjustmentId, $schoolId]);
            $adjustment = $adjustmentStmt->fetch();
            if (!$adjustment || $adjustment['status'] !== 'pending') throw new RuntimeException('Only pending adjustments can be decided.');
            if ($decision === 'approved' && $adjustment['adjustment_type'] !== 'payment_arrangement') {
                if ((float) $adjustment['amount'] > (float) $adjustment['balance']) throw new RuntimeException('Adjustment cannot exceed the outstanding balance.');
                $balance = (float) $adjustment['balance'] - (float) $adjustment['amount'];
                $invoiceStatus = $balance <= 0.00001 ? 'paid' : ((float) $balance < (float) $adjustment['balance'] ? 'partial' : (string) $adjustment['invoice_status']);
                $pdo->prepare('UPDATE school_invoices SET balance = ?, adjustments_total = adjustments_total + ?, status = ?, updated_at = NOW() WHERE id = ? AND school_id = ?')
                    ->execute([number_format(max(0, $balance), 2, '.', ''), $adjustment['amount'], $invoiceStatus, (int) $adjustment['invoice_id'], $schoolId]);
            }
            $pdo->prepare('UPDATE school_invoice_adjustments SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND school_id = ?')
                ->execute([$decision, (int) $user['id'], $adjustmentId, $schoolId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof RuntimeException) flash('error', $exception->getMessage());
            else { error_log('School invoice adjustment decision failed: ' . $exception->getMessage()); flash('error', 'Adjustment decision failed.'); }
            redirect('invoices.php');
        }
        audit((int) $user['id'], 'school_invoice_adjustment_' . $decision, 'school_id=' . $schoolId . '; adjustment_id=' . $adjustmentId);
        if ($decision === 'approved' && is_string($adjustment['academic_year']) && $adjustment['academic_year'] !== '') {
            $clearance = school_student_fee_clearance((int) $adjustment['student_id'], $schoolId, (string) $adjustment['academic_year']);
            audit((int) $user['id'], 'school_fee_clearance_rechecked', 'school_id=' . $schoolId . '; student_id=' . $adjustment['student_id'] . '; status=' . $clearance['status']);
        }
        flash('success', 'Invoice adjustment ' . $decision . '.');
        redirect('invoices.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('fee_management.php?section=student-accounts');
}

$studentsStmt = db()->prepare(
    'SELECT ss.id, ss.full_name, sc.level AS class_level, sc.section AS class_section
     FROM school_students ss LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
     WHERE ss.school_id = ? AND ss.school_status = "active" ORDER BY ss.full_name'
);
$studentsStmt->execute([$schoolId]);
$students = $studentsStmt->fetchAll();
$termsStmt = db()->prepare(
    'SELECT term.id, academic_year.academic_year, term.term_name
     FROM school_terms term
     JOIN school_academic_years academic_year
       ON academic_year.id = term.academic_year_id AND academic_year.school_id = term.school_id
     WHERE term.school_id = ? AND term.is_current = 1 ORDER BY term.starts_on DESC'
);
$termsStmt->execute([$schoolId]);
$terms = array_values(array_filter($termsStmt->fetchAll(), static fn(array $term): bool => school_fee_supported_term((string) $term['term_name'])));
$invoiceStmt = db()->prepare(
    'SELECT si.id, si.student_id, si.invoice_number, si.amount, si.balance, si.adjustments_total, si.status, si.due_date, si.class_level, si.academic_year, si.period_name,
            ss.full_name, si.fee_snapshot
     FROM school_invoices si JOIN school_students ss ON ss.id = si.student_id AND ss.school_id = si.school_id
     WHERE si.school_id = ? ORDER BY si.created_at DESC'
);
$invoiceStmt->execute([$schoolId]);
$invoices = $invoiceStmt->fetchAll();
$invoicesByStudent = [];
foreach ($invoices as $invoiceRow) {
    $invoicesByStudent[(int) $invoiceRow['student_id']][] = $invoiceRow;
}
$allocatableStmt = db()->prepare(
    'SELECT invoice.id, invoice.student_id, invoice.invoice_number, invoice.academic_year,
            invoice.period_name, invoice.balance
     FROM school_invoices invoice
     JOIN school_academic_years academic_year
       ON academic_year.school_id = invoice.school_id
      AND academic_year.academic_year = invoice.academic_year
     JOIN school_terms term
       ON term.school_id = academic_year.school_id
      AND term.academic_year_id = academic_year.id
      AND term.term_name = invoice.period_name
     WHERE invoice.school_id = ? AND invoice.balance > 0
       AND term.term_name IN ("First Term", "Second Term", "Third Term")
     ORDER BY invoice.student_id, term.starts_on, invoice.id'
);
$allocatableStmt->execute([$schoolId]);
$allocatableInvoicesByStudent = [];
$allocatableInvoiceIdsByStudent = [];
foreach ($allocatableStmt->fetchAll() as $allocatableInvoice) {
    $allocatableInvoicesByStudent[(int) $allocatableInvoice['student_id']][] = $allocatableInvoice;
    $allocatableInvoiceIdsByStudent[(int) $allocatableInvoice['student_id']][(int) $allocatableInvoice['id']] = true;
}
$pendingPaymentsStmt = db()->prepare(
    'SELECT p.id, p.invoice_id, p.amount, p.payment_coverage, p.payment_method, p.payment_reference, p.received_at, ss.full_name
     FROM school_payments p JOIN school_students ss ON ss.id = p.student_id AND ss.school_id = p.school_id
     WHERE p.school_id = ? AND p.status = "pending" ORDER BY p.received_at'
);
$pendingPaymentsStmt->execute([$schoolId]);
$pendingPayments = $pendingPaymentsStmt->fetchAll();
$pendingAllocationStmt = db()->prepare(
    'SELECT allocation.payment_id, invoice.invoice_number, invoice.period_name, allocation.amount
     FROM school_payment_allocations allocation
     JOIN school_payments payment
       ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
     JOIN school_invoices invoice
       ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
     WHERE allocation.school_id = ? AND allocation.status = "pending" AND payment.status = "pending"
     ORDER BY allocation.payment_id, invoice.id'
);
$pendingAllocationStmt->execute([$schoolId]);
$pendingAllocationsByPayment = [];
foreach ($pendingAllocationStmt->fetchAll() as $pendingAllocation) {
    $pendingAllocationsByPayment[(int) $pendingAllocation['payment_id']][] = $pendingAllocation;
}
$adjustmentStmt = db()->prepare(
    'SELECT a.id, a.invoice_id, a.adjustment_type, a.amount, a.valid_until, a.reason, a.status, si.invoice_number, ss.full_name
     FROM school_invoice_adjustments a JOIN school_invoices si ON si.id = a.invoice_id AND si.school_id = a.school_id
     JOIN school_students ss ON ss.id = si.student_id AND ss.school_id = si.school_id
     WHERE a.school_id = ? ORDER BY a.created_at DESC'
);
$adjustmentStmt->execute([$schoolId]);
$adjustments = $adjustmentStmt->fetchAll();
$paymentHistoryStmt = db()->prepare(
    'SELECT payment.id, allocation.invoice_id, allocation.amount AS allocated_amount,
            allocation.allocation_type, allocation.status AS allocation_status,
            payment.amount AS payment_amount, payment.payment_coverage, payment.payment_reference,
            payment.status AS payment_status
     FROM school_payment_allocations allocation
     JOIN school_payments payment
       ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
     WHERE allocation.school_id = ?
     ORDER BY payment.received_at DESC, allocation.id'
);
$paymentHistoryStmt->execute([$schoolId]);
$paymentsByInvoice = [];
foreach ($paymentHistoryStmt->fetchAll() as $paymentRow) $paymentsByInvoice[(int) $paymentRow['invoice_id']][] = $paymentRow;
$financeSummaryStmt = db()->prepare(
    'SELECT COUNT(*) AS invoice_count, COALESCE(SUM(amount),0) AS invoiced_total,
            COALESCE(SUM(balance),0) AS outstanding_total, COALESCE(SUM(adjustments_total),0) AS adjustments_total
     FROM school_invoices WHERE school_id = ?'
);
$financeSummaryStmt->execute([$schoolId]);
$financeSummary = $financeSummaryStmt->fetch();
$paymentSummaryStmt = db()->prepare(
    'SELECT COALESCE(SUM(CASE WHEN status = "verified" THEN amount ELSE 0 END),0) AS verified_total,
            COALESCE(SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END),0) AS pending_total
     FROM school_payments WHERE school_id = ?'
);
$paymentSummaryStmt->execute([$schoolId]);
$paymentSummary = $paymentSummaryStmt->fetch();
$creditSummaryStmt = db()->prepare(
    'SELECT COALESCE(SUM(credit.surplus_amount - COALESCE(used.amount, 0)), 0)
     FROM school_fee_credit_sources credit
     JOIN school_payments payment
       ON payment.id = credit.payment_id AND payment.school_id = credit.school_id
      AND payment.student_id = credit.student_id AND payment.status = "verified"
     LEFT JOIN (
         SELECT payment_id, SUM(amount) AS amount
         FROM school_payment_allocations
         WHERE school_id = ? AND allocation_type = "automatic_credit" AND status = "active"
         GROUP BY payment_id
     ) used ON used.payment_id = credit.payment_id
     WHERE credit.school_id = ?'
);
$creditSummaryStmt->execute([$schoolId, $schoolId]);
$availableCreditTotal = (float) $creditSummaryStmt->fetchColumn();
$reconciliationStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM school_invoices invoice
     LEFT JOIN (
         SELECT allocation.school_id, allocation.invoice_id, SUM(allocation.amount) AS paid_amount
         FROM school_payment_allocations allocation
         JOIN school_payments payment
           ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
          AND payment.student_id = allocation.student_id AND payment.status = "verified"
         WHERE allocation.status = "active"
         GROUP BY allocation.school_id, allocation.invoice_id
     ) paid ON paid.school_id = invoice.school_id AND paid.invoice_id = invoice.id
     WHERE invoice.school_id = ?
       AND ABS(ROUND((invoice.amount - invoice.adjustments_total - COALESCE(paid.paid_amount, 0) - invoice.balance) * 100)) >= 1'
);
$reconciliationStmt->execute([$schoolId]);
$reconciliationCount = (int) $reconciliationStmt->fetchColumn();

render_header('Invoices');
?>
<section class="panel"><p class="eyebrow">FINANCE</p><h1>School invoices</h1><p class="muted">Only First Term, Second Term, and Third Term are assessed from class/year fee schedules. Verified payments are applied to explicit invoice allocations. Verified surplus is carried only to future configured terms; pending funds never reduce balances.</p></section>
<section class="report-action-grid" aria-label="Invoice and payment actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('invoice') ?></span>
        <div><h2>Generate an Invoice</h2><p>Create an invoice for one student in a configured academic period.</p></div>
        <a class="button button-primary" href="#invoice-single" data-action-open="invoice-single">Generate Invoice</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('receipt') ?></span>
        <div><h2>Assess a Whole Period</h2><p>Generate invoices from the saved fee schedules for all eligible students.</p></div>
        <a class="button" href="#invoice-period" data-action-open="invoice-period">Assess Period</a>
    </article>
    <?php if ($canVerifyPayments): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span>
            <div><h2>Review Pending Payments</h2><p>Verify or reject recorded payments and their invoice allocations.</p></div>
            <a class="button" href="#pending-payments" data-action-open="pending-payments">Review Payments</a>
        </article>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('reports') ?></span>
            <div><h2>Finance Summary</h2><p>Review invoice balances, verified allocations, and reconciliation totals.</p></div>
            <a class="button" href="#finance-summary" data-action-open="finance-summary">View Summary</a>
        </article>
    <?php endif; ?>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('invoice') ?></span>
        <div><h2>Invoice Ledger</h2><p>Review invoices and use the permitted payment and adjustment actions.</p></div>
        <a class="button" href="#invoice-ledger" data-action-open="invoice-ledger">Open Ledger</a>
    </article>
    <?php if ($canVerifyPayments): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span>
            <div><h2>Review Adjustments</h2><p>Review discount, scholarship, waiver, and payment-arrangement requests.</p></div>
            <a class="button" href="#invoice-adjustments" data-action-open="invoice-adjustments">Review Adjustments</a>
        </article>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span>
            <div><h2>Reconcile a Statement</h2><p>Compare a bank or provider CSV with recorded fee payments without changing payment status.</p></div>
            <a class="button" href="payment_reconciliation.php">Reconcile Statement</a>
        </article>
    <?php endif; ?>
</section>
<section class="panel notice notice-error panel-top-gap-sm" data-action-view="finance-summary"><strong>Reversal limitation:</strong> verified allocations and automatic credit applications cannot yet be reversed or corrected in the system. Do not re-enter a verified payment; contact the finance administrator for manual review and correction.</section>
<?php if ($reconciliationCount > 0): ?><section class="panel notice notice-error panel-top-gap-sm" data-action-view="finance-summary"><strong>Historical reconciliation review:</strong> <?= $reconciliationCount ?> invoice(s) do not reconcile to their preserved balance, verified allocations, and approved adjustments. Existing balances were not changed by allocation migration; investigate these records before adjusting credits.</section><?php endif; ?>
<?php if ($terms === []): ?><section class="panel notice notice-error panel-top-gap-sm" data-action-view="invoice-period"><p>No academic terms are configured, so new period invoices cannot be generated.<?php if (school_has_permission($user, $schoolId, 'school.manage')): ?> <a href="term_setup.php">Configure Academic Years &amp; Terms</a>.<?php else: ?> Ask your Headmaster or Proprietor to configure them.<?php endif; ?></p></section><?php endif; ?>
<section class="panel panel-top-gap" id="invoice-single" data-action-view="invoice-single">
    <h2>Generate period invoice</h2>
    <form method="post" action="invoices.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="generate_invoice">
        <div class="form-grid two-columns">
            <div><label for="student_id">Student</label><select id="student_id" name="student_id" required><option value="">Select student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e((string) $student['full_name']) ?><?= $student['class_level'] ? ' · ' . e(school_class_label(['level'=>$student['class_level'],'section'=>$student['class_section']])) : '' ?></option><?php endforeach; ?></select></div>
            <div><label for="term_id">Academic year and term</label><select id="term_id" name="term_id" required><option value="">Select term</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $pageAcademicPeriod !== null && $pageAcademicPeriod['academic_year'] === (string) $term['academic_year'] && $pageAcademicPeriod['term_name'] === (string) $term['term_name'] ? 'selected' : '' ?>><?= e((string) $term['academic_year'] . ' · ' . (string) $term['term_name']) ?></option><?php endforeach; ?></select></div>
        </div><button class="button button-primary" type="submit">Generate invoice</button>
    </form>
</section>
<section class="panel panel-top-gap-sm" id="invoice-period" data-action-view="invoice-period">
    <h2>Generate invoices for the whole period</h2>
    <p class="muted">Retries assessment for the active term using existing fee schedules. Students admitted during the term are charged the full configured term fee. Students admitted after the term ends are not billed for that term.</p>
    <form method="post" action="invoices.php" class="form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="generate_period_billing">
        <label for="bulk_term_id">Academic year and term</label><select id="bulk_term_id" name="term_id" required><option value="">Select period</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $pageAcademicPeriod !== null && $pageAcademicPeriod['academic_year'] === (string) $term['academic_year'] && $pageAcademicPeriod['term_name'] === (string) $term['term_name'] ? 'selected' : '' ?>><?= e((string) $term['academic_year'] . ' · ' . (string) $term['term_name']) ?></option><?php endforeach; ?></select>
        <button class="button button-primary" type="submit">Generate all missing invoices</button>
    </form>
</section>
<?php if ($canVerifyPayments): ?>
<section class="panel panel-top-gap" id="pending-payments" data-action-view="pending-payments"><h2>Pending payments</h2>
    <?php if ($pendingPayments === []): ?><p class="muted">No payments are awaiting verification.</p><?php else: ?><table><thead><tr><th>Student</th><th>Invoice</th><th>Amount</th><th>Coverage</th><th>Method</th><th>Reference</th><th>Received</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($pendingPayments as $payment): ?><tr><td><?= e((string) $payment['full_name']) ?></td><td><?php foreach ($pendingAllocationsByPayment[(int) $payment['id']] ?? [] as $allocation): ?><?= e((string) $allocation['invoice_number']) ?> · GHS <?= e(number_format((float) $allocation['amount'], 2)) ?><br><?php endforeach; ?></td><td>GHS <?= e(number_format((float) $payment['amount'], 2)) ?></td><td><?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?></td><td><?= e((string) $payment['payment_method']) ?></td><td><?= e((string) ($payment['payment_reference'] ?: '—')) ?></td><td><?= e((string) $payment['received_at']) ?></td><td><?php foreach (['verify_payment'=>'Verify','reject_payment'=>'Reject'] as $decision=>$label): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="<?= e($decision) ?>"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><button class="button" type="submit"><?= e($label) ?></button></form><?php endforeach; ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
</section>
<?php endif; ?>
<section class="panel panel-top-gap" id="finance-summary" data-action-view="finance-summary"><h2>School finance summary</h2><div class="form-grid two-columns">
    <p><strong>Invoices:</strong> <?= (int) $financeSummary['invoice_count'] ?></p>
    <p><strong>Total invoiced:</strong> GHS <?= e(number_format((float) $financeSummary['invoiced_total'], 2)) ?></p>
    <p><strong>Verified payments:</strong> GHS <?= e(number_format((float) $paymentSummary['verified_total'], 2)) ?></p>
    <p><strong>Payments pending verification:</strong> GHS <?= e(number_format((float) $paymentSummary['pending_total'], 2)) ?></p>
    <p><strong>Unapplied verified student credits:</strong> GHS <?= e(number_format(max(0, $availableCreditTotal), 2)) ?></p>
    <p class="muted">Automatic credit applies oldest verified credit to the earliest chronologically future supported term invoice with a balance.</p>
    <p><strong>Approved adjustments:</strong> GHS <?= e(number_format((float) $financeSummary['adjustments_total'], 2)) ?></p>
    <p><strong>Outstanding balances:</strong> GHS <?= e(number_format((float) $financeSummary['outstanding_total'], 2)) ?></p>
</div></section>
<section class="panel" id="invoice-ledger" data-action-view="invoice-ledger" style="margin-top:1.5rem;"><h2>Invoice ledger</h2>
<?php if ($invoices === []): ?><p class="muted">No invoices have been generated for this school.</p><?php else: ?><table><thead><tr><th>Invoice</th><th>Student</th><th>Class / period</th><th>Items</th><th>Amount</th><th>Adjustments</th><th>Balance</th><th>Status</th><th>Payments / receipts</th><th>Record payment</th><th>Adjustment</th></tr></thead><tbody>
<?php foreach ($invoices as $invoice): $items=json_decode((string) ($invoice['fee_snapshot'] ?? ''), true); ?><tr>
    <td><?= e((string) $invoice['invoice_number']) ?></td><td><?= e((string) $invoice['full_name']) ?></td>
    <td><?= e((string) ($invoice['class_level'] ?: 'Legacy') . ' · ' . (string) ($invoice['academic_year'] ?: '—') . ' ' . (string) ($invoice['period_name'] ?: '')) ?></td>
    <td><?php if (is_array($items)): foreach ($items as $item): ?><?= e((string) ($item['name'] ?? 'Fee')) ?>: GHS <?= e((string) ($item['amount'] ?? '0.00')) ?><br><?php endforeach; else: ?>Legacy fee<?php endif; ?></td>
    <td>GHS <?= e(number_format((float) $invoice['amount'], 2)) ?></td><td>GHS <?= e(number_format((float) $invoice['adjustments_total'], 2)) ?></td><td>GHS <?= e(number_format((float) $invoice['balance'], 2)) ?></td><td><span class="status-pill <?= e(school_fee_status_class((string) $invoice['status'])) ?>"><?= e(ucfirst((string) $invoice['status'])) ?></span></td>
    <td><?php foreach ($paymentsByInvoice[(int) $invoice['id']] ?? [] as $payment): ?>GHS <?= e(number_format((float) $payment['allocated_amount'], 2)) ?> allocated (payment GHS <?= e(number_format((float) $payment['payment_amount'], 2)) ?>; <?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?>; <?= e((string) $payment['payment_status']) ?><?php if ($payment['allocation_status'] === 'void'): ?> / allocation void<?php elseif ($payment['payment_status'] === 'pending'): ?> / not applied<?php else: ?> / <?= e(str_replace('_', ' ', (string) $payment['allocation_type'])) ?><?php endif; ?>)<?php if ($payment['payment_status']==='verified' && $payment['allocation_status']==='active'): ?> <a href="school_payment_receipt.php?id=<?= (int) $payment['id'] ?>">Receipt</a><?php endif; ?><br><?php endforeach; ?></td>
    <td><?php if ($canVerifyPayments && isset($allocatableInvoiceIdsByStudent[(int) $invoice['student_id']][(int) $invoice['id']])): ?><details><summary>Record payment</summary><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><label>Payment coverage<select name="payment_coverage" required><option value="part">Part payment (including half payment)</option><option value="term">Term payment</option><option value="academic_year">Academic-year payment</option></select></label><label>Actual amount received (GHS)<input type="number" name="amount" min="0.01" step="0.01" placeholder="Enter the amount received" required></label><p class="muted">Allocate the received amount to current outstanding invoices. For an academic-year payment, any verified amount not yet matched to an issued invoice is held as student credit and applied automatically as later term invoices are issued. Part payments leave the unpaid invoice balance outstanding.</p><?php foreach ($allocatableInvoicesByStudent[(int) $invoice['student_id']] as $targetInvoice): ?><label><?= e((string) $targetInvoice['invoice_number'] . ' · ' . $targetInvoice['academic_year'] . ' ' . $targetInvoice['period_name']) ?> (outstanding GHS <?= e(number_format((float) $targetInvoice['balance'], 2)) ?>)<input type="number" name="allocations[<?= (int) $targetInvoice['id'] ?>]" min="0" max="<?= e((string) $targetInvoice['balance']) ?>" step="0.01" value="0.00"></label><?php endforeach; ?><label>Payment method<select name="payment_method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mobile_money">Mobile money</option><option value="card">Card</option><option value="offline">Offline</option></select></label><input name="payment_reference" maxlength="80" placeholder="Reference"><button class="button button-primary" type="submit">Record pending payment</button></form></details><?php else: ?>—<?php endif; ?></td>
    <td><?php if ($canVerifyPayments): ?><form method="post" class="form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="request_adjustment"><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><select name="adjustment_type"><option value="discount">Discount</option><option value="scholarship">Scholarship</option><option value="waiver">Waiver</option><option value="payment_arrangement">Payment arrangement</option></select><input type="number" name="adjustment_amount" min="0.01" step="0.01" placeholder="Amount (except plan)"><input type="date" name="valid_until" aria-label="Valid until — Required for a payment arrangement" title="Valid until — Required for a payment arrangement"><input name="reason" maxlength="500" placeholder="Reason" required><button class="button" type="submit">Request</button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<?php if ($canVerifyPayments): ?><section class="panel" id="invoice-adjustments" data-action-view="invoice-adjustments" style="margin-top:1.5rem;"><h2>Discounts, waivers, scholarships, arrangements</h2><?php if ($adjustments===[]): ?><p class="muted">No adjustment requests.</p><?php else: ?><table><thead><tr><th>Invoice / student</th><th>Type</th><th>Amount / valid until</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php foreach ($adjustments as $adjustment): ?><tr><td><?= e((string) $adjustment['invoice_number'] . ' / ' . (string) $adjustment['full_name']) ?></td><td><?= e((string) $adjustment['adjustment_type']) ?></td><td><?= $adjustment['amount'] === null ? e((string) ($adjustment['valid_until'] ?: 'No end date')) : 'GHS ' . e(number_format((float) $adjustment['amount'], 2)) ?></td><td><?= e((string) $adjustment['reason']) ?></td><td><?= e((string) $adjustment['status']) ?></td><td><?php if ($adjustment['status']==='pending'): ?><form method="post" class="form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="decide_adjustment"><input type="hidden" name="adjustment_id" value="<?= (int) $adjustment['id'] ?>"><button class="button button-primary" name="decision" value="approved">Approve</button><button class="button" name="decision" value="rejected">Reject</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section><?php endif; ?>
<?php render_footer(); ?>

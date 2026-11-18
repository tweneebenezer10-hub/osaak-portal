<?php
declare(strict_types=1);

function school_fee_supported_term(string $termName): bool
{
    return in_array($termName, ['First Term', 'Second Term', 'Third Term'], true);
}

function school_fee_payment_coverage_label(string $coverage): string
{
    return match ($coverage) {
        'unspecified' => 'Unclassified (recorded before coverage tracking)',
        'part' => 'Part payment',
        'term' => 'Term payment',
        'academic_year' => 'Academic-year payment',
        default => 'Unknown payment coverage',
    };
}

function school_fee_status_class(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid', 'fully paid', 'settled' => 'fee-status-paid',
        'partial', 'partially paid', 'part payment', 'half payment' => 'fee-status-partial',
        'issued', 'overdue', 'outstanding', 'unpaid', 'owes' => 'fee-status-owes',
        default => 'fee-status-neutral',
    };
}

function school_fee_student_admission_eligible(?string $admissionDate, string $termEndsOn): bool
{
    return $admissionDate !== null && $admissionDate !== '' && $admissionDate <= $termEndsOn;
}

function school_fee_amount_cents(mixed $amount): ?int
{
    if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
        return null;
    }
    $value = trim((string) $amount);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $value)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function school_fee_write_invoice_balance(
    PDO $pdo,
    int $schoolId,
    int $invoiceId,
    int $balanceCents,
    int $amountCents,
    string $previousStatus = 'issued'
): void
{
    $balanceCents = max(0, $balanceCents);
    $status = $balanceCents === 0
        ? 'paid'
        : ($balanceCents < $amountCents ? 'partial' : ($previousStatus === 'overdue' ? 'overdue' : 'issued'));
    $pdo->prepare(
        'UPDATE school_invoices SET balance = ?, status = ?, updated_at = NOW()
         WHERE id = ? AND school_id = ?'
    )->execute([number_format($balanceCents / 100, 2, '.', ''), $status, $invoiceId, $schoolId]);
}

/**
 * Apply only verified surplus credits to later, configured term invoices.
 * The caller must own a transaction; locking the student serializes credit use
 * with payment verification and term invoice assessment.
 */
function school_apply_future_fee_credits(PDO $pdo, int $schoolId, int $studentId, ?int $actorId): int
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Fee credits can only be applied inside a transaction.');
    }
    $studentLock = $pdo->prepare('SELECT id FROM school_students WHERE id = ? AND school_id = ? FOR UPDATE');
    $studentLock->execute([$studentId, $schoolId]);
    if (!$studentLock->fetchColumn()) {
        throw new RuntimeException('The student does not belong to this school.');
    }

    $sourceStmt = $pdo->prepare(
        'SELECT credit.payment_id, credit.surplus_amount, anchorTerm.starts_on AS source_starts_on,
                COALESCE(used.amount, 0) AS used_amount
         FROM school_fee_credit_sources credit
         JOIN school_payments payment
           ON payment.id = credit.payment_id AND payment.school_id = credit.school_id
          AND payment.student_id = credit.student_id AND payment.status = "verified"
         JOIN school_invoices anchor
           ON anchor.id = payment.invoice_id AND anchor.school_id = payment.school_id
          AND anchor.student_id = payment.student_id
         JOIN school_academic_years anchorYear
           ON anchorYear.school_id = anchor.school_id
          AND anchorYear.academic_year = anchor.academic_year
         JOIN school_terms anchorTerm
           ON anchorTerm.school_id = anchorYear.school_id
          AND anchorTerm.academic_year_id = anchorYear.id
          AND anchorTerm.term_name = anchor.period_name
         LEFT JOIN (
             SELECT payment_id, SUM(amount) AS amount
             FROM school_payment_allocations
             WHERE school_id = ? AND student_id = ? AND allocation_type = "automatic_credit" AND status = "active"
             GROUP BY payment_id
         ) used ON used.payment_id = credit.payment_id
         WHERE credit.school_id = ? AND credit.student_id = ?
         ORDER BY credit.created_at, credit.payment_id
         FOR UPDATE'
    );
    $sourceStmt->execute([$schoolId, $studentId, $schoolId, $studentId]);
    $sources = $sourceStmt->fetchAll();
    if ($sources === []) {
        return 0;
    }

    $invoiceStmt = $pdo->prepare(
        'SELECT invoice.id, invoice.amount, invoice.balance, invoice.status, term.starts_on
         FROM school_invoices invoice
         JOIN school_academic_years invoiceYear
           ON invoiceYear.school_id = invoice.school_id
          AND invoiceYear.academic_year = invoice.academic_year
         JOIN school_terms term
           ON term.school_id = invoiceYear.school_id
          AND term.academic_year_id = invoiceYear.id
          AND term.term_name = invoice.period_name
         WHERE invoice.school_id = ? AND invoice.student_id = ?
           AND term.term_name IN ("First Term", "Second Term", "Third Term")
         ORDER BY term.starts_on, invoice.id
         FOR UPDATE'
    );
    $invoiceStmt->execute([$schoolId, $studentId]);
    $invoices = $invoiceStmt->fetchAll();
    $allocationCheck = $pdo->prepare(
        'SELECT id FROM school_payment_allocations
         WHERE payment_id = ? AND invoice_id = ? AND allocation_type = "automatic_credit" LIMIT 1'
    );
    $allocationInsert = $pdo->prepare(
        'INSERT INTO school_payment_allocations
         (school_id, student_id, payment_id, invoice_id, amount, allocation_type, status, created_by)
         VALUES (?, ?, ?, ?, ?, "automatic_credit", "active", ?)'
    );
    $totalAppliedCents = 0;

    foreach ($sources as $source) {
        $remainingCents = (int) round((float) $source['surplus_amount'] * 100)
            - (int) round((float) $source['used_amount'] * 100);
        if ($remainingCents <= 0) {
            continue;
        }
        foreach ($invoices as &$invoice) {
            if ((string) $invoice['starts_on'] <= (string) $source['source_starts_on']) {
                continue;
            }
            $invoiceId = (int) $invoice['id'];
            $allocationCheck->execute([(int) $source['payment_id'], $invoiceId]);
            if ($allocationCheck->fetchColumn()) {
                continue;
            }
            $balanceCents = (int) round((float) $invoice['balance'] * 100);
            if ($balanceCents <= 0) {
                continue;
            }
            $applyCents = min($remainingCents, $balanceCents);
            $allocationInsert->execute([
                $schoolId,
                $studentId,
                (int) $source['payment_id'],
                $invoiceId,
                number_format($applyCents / 100, 2, '.', ''),
                $actorId,
            ]);
            $newBalanceCents = $balanceCents - $applyCents;
            school_fee_write_invoice_balance(
                $pdo,
                $schoolId,
                $invoiceId,
                $newBalanceCents,
                (int) round((float) $invoice['amount'] * 100),
                (string) $invoice['status']
            );
            $invoice['status'] = $newBalanceCents === 0
                ? 'paid'
                : ($newBalanceCents < (int) round((float) $invoice['amount'] * 100)
                    ? 'partial'
                    : ((string) $invoice['status'] === 'overdue' ? 'overdue' : 'issued'));
            $invoice['balance'] = number_format($newBalanceCents / 100, 2, '.', '');
            $remainingCents -= $applyCents;
            $totalAppliedCents += $applyCents;
            if ($remainingCents === 0) {
                break;
            }
        }
        unset($invoice);
    }
    return $totalAppliedCents;
}

function school_invoice_fee_items(int $schoolId, string $classLevel, array $term): array
{
    $pdo = db();
    $feeStmt = $pdo->prepare(
        'SELECT id, name, amount, due_day, fee_category
         FROM school_fee_structures
         WHERE school_id = ? AND class_level = ? AND academic_year = ? AND period_name = ? AND is_active = 1
           AND effective_from <= ?
         ORDER BY id'
    );
    $feeStmt->execute([$schoolId, $classLevel, $term['academic_year'], $term['term_name'], $term['starts_on']]);
    $items = [];
    $totalCents = 0;
    $dueDays = [];
    foreach ($feeStmt->fetchAll() as $fee) {
        $adjustmentStmt = $pdo->prepare(
            'SELECT previous_amount, new_amount, effective_from FROM school_fee_adjustments
             WHERE school_id = ? AND fee_structure_id = ? ORDER BY effective_from, id'
        );
        $adjustmentStmt->execute([$schoolId, (int) $fee['id']]);
        $adjustments = $adjustmentStmt->fetchAll();
        $feeAmount = $adjustments === [] ? (float) $fee['amount'] : (float) $adjustments[0]['previous_amount'];
        foreach ($adjustments as $adjustment) {
            if ((string) $adjustment['effective_from'] > (string) $term['starts_on']) {
                break;
            }
            $feeAmount = (float) $adjustment['new_amount'];
        }
        $amount = number_format($feeAmount, 2, '.', '');
        $items[] = [
            'fee_structure_id' => (int) $fee['id'],
            'name' => (string) $fee['name'],
            'category' => (string) $fee['fee_category'],
            'amount' => $amount,
        ];
        $totalCents += (int) round((float) $amount * 100);
        $dueDays[] = (int) $fee['due_day'];
    }

    $deadline = new DateTimeImmutable((string) $term['starts_on']);
    $endDate = new DateTimeImmutable((string) $term['ends_on']);
    if ($dueDays !== []) {
        $dueDay = min($dueDays);
        $monthDays = (int) $deadline->format('t');
        $deadline = $deadline->setDate((int) $deadline->format('Y'), (int) $deadline->format('n'), min($dueDay, $monthDays));
        if ($deadline < new DateTimeImmutable((string) $term['starts_on'])) {
            $deadline = $deadline->modify('first day of next month');
            $deadline = $deadline->setDate((int) $deadline->format('Y'), (int) $deadline->format('n'), min($dueDay, (int) $deadline->format('t')));
        }
        if ($deadline > $endDate) {
            $deadline = $endDate;
        }
    }
    return ['items' => $items, 'amount' => number_format($totalCents / 100, 2, '.', ''), 'due_date' => $deadline->format('Y-m-d')];
}

/**
 * Assess only the active term. Caller must hold an open transaction; the school
 * and term locks serialize assessments while the invoice unique key is the final
 * idempotency guard. Existing invoices and their snapshots are never rewritten.
 */
function school_assess_term_invoices(int $schoolId, int $termId, ?int $onlyStudentId = null, ?int $actorId = null): array
{
    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new LogicException('Term fee assessment requires a database transaction.');
    }

    $schoolStmt = $pdo->prepare('SELECT id, short_name FROM schools WHERE id = ? FOR UPDATE');
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        throw new RuntimeException('The school record could not be found.');
    }
    $termStmt = $pdo->prepare(
        'SELECT t.id, y.academic_year, t.term_name, t.starts_on, t.ends_on,
                t.is_current, y.status AS year_status, y.is_active
         FROM school_terms t
         JOIN school_academic_years y
           ON y.id = t.academic_year_id AND y.school_id = t.school_id
         WHERE t.id = ? AND t.school_id = ?
           AND t.starts_on >= y.starts_on AND t.ends_on <= y.ends_on
         FOR UPDATE'
    );
    $termStmt->execute([$termId, $schoolId]);
    $term = $termStmt->fetch();
    if (!$term || (int) $term['is_active'] !== 1 || $term['year_status'] !== 'open') {
        throw new RuntimeException('Fee assessment is available only for terms in an active, open academic year.');
    }

    $result = [
        'supported' => school_fee_supported_term((string) $term['term_name']),
        'created' => 0,
        'existing' => 0,
        'without_class_or_year' => 0,
        'not_yet_enrolled' => 0,
        'without_schedule' => 0,
        'skipped_students' => [],
        'created_invoices' => [],
    ];
    if (!$result['supported']) {
        return $result;
    }

    $studentsSql = 'SELECT ss.id, ss.full_name, ss.admission_date, sc.level AS class_level,
                           sc.academic_year AS class_academic_year, sc.status AS class_status
                    FROM school_students ss
                    LEFT JOIN school_classes sc
                      ON sc.id = ss.class_id AND sc.school_id = ss.school_id
                    WHERE ss.school_id = ? AND ss.school_status = "active"';
    $studentParams = [$schoolId];
    if ($onlyStudentId !== null) {
        $studentsSql .= ' AND ss.id = ?';
        $studentParams[] = $onlyStudentId;
    }
    $studentsSql .= ' ORDER BY ss.id';
    $studentsStmt = $pdo->prepare($studentsSql);
    $studentsStmt->execute($studentParams);
    $existsStmt = $pdo->prepare(
        'SELECT id FROM school_invoices
         WHERE school_id = ? AND student_id = ? AND academic_year = ? AND period_name = ? LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO school_invoices
         (school_id, student_id, fee_structure_id, invoice_number, amount, balance, status, due_date,
          class_level, academic_year, period_name, fee_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, "issued", ?, ?, ?, ?, ?)'
    );

    foreach ($studentsStmt->fetchAll() as $student) {
        if (!$student['class_level'] || $student['class_status'] !== 'active'
            || (string) $student['class_academic_year'] !== (string) $term['academic_year']) {
            $result['without_class_or_year']++;
            $reason = !$student['class_level'] || $student['class_status'] !== 'active'
                ? 'The student needs an active class assigned.'
                : ((string) $student['class_academic_year'] === ''
                    ? 'The student’s class is not assigned to an academic year.'
                    : 'The student’s class belongs to academic year ' . (string) $student['class_academic_year']
                        . ', not ' . (string) $term['academic_year'] . '.');
            $result['skipped_students'][] = [
                'student_id' => (int) $student['id'],
                'full_name' => (string) $student['full_name'],
                'reason' => $reason,
            ];
            continue;
        }
        if (!school_fee_student_admission_eligible(
            $student['admission_date'] === null ? null : (string) $student['admission_date'],
            (string) $term['ends_on']
        )) {
            $result['not_yet_enrolled']++;
            $reason = !$student['admission_date']
                ? 'The student’s admission date is missing.'
                : 'The student’s admission date is after the selected term ends.';
            $result['skipped_students'][] = [
                'student_id' => (int) $student['id'],
                'full_name' => (string) $student['full_name'],
                'reason' => $reason,
            ];
            continue;
        }
        $existsStmt->execute([$schoolId, (int) $student['id'], $term['academic_year'], $term['term_name']]);
        if ($existsStmt->fetchColumn()) {
            $result['existing']++;
            continue;
        }

        $feeData = school_invoice_fee_items($schoolId, (string) $student['class_level'], $term);
        if ($feeData['items'] === []) {
            $result['without_schedule']++;
            $result['skipped_students'][] = [
                'student_id' => (int) $student['id'],
                'full_name' => (string) $student['full_name'],
                'reason' => 'No active fee schedule matches the student’s class level, academic year, term, and effective date.',
            ];
            continue;
        }

        $number = 'INV-' . strtoupper(substr((string) $school['short_name'], 0, 6)) . '-' . date('Ymd') . '-' . bin2hex(random_bytes(6));
        try {
            $insertStmt->execute([
                $schoolId,
                (int) $student['id'],
                (int) $feeData['items'][0]['fee_structure_id'],
                $number,
                number_format((float) $feeData['amount'], 2, '.', ''),
                number_format((float) $feeData['amount'], 2, '.', ''),
                $feeData['due_date'],
                (string) $student['class_level'],
                (string) $term['academic_year'],
                (string) $term['term_name'],
                json_encode($feeData['items'], JSON_THROW_ON_ERROR),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $existsStmt->execute([$schoolId, (int) $student['id'], $term['academic_year'], $term['term_name']]);
            if ($existsStmt->fetchColumn()) {
                $result['existing']++;
                continue;
            }
            throw $exception;
        }
        $result['created']++;
        $invoiceId = (int) $pdo->lastInsertId();
        school_apply_future_fee_credits($pdo, $schoolId, (int) $student['id'], $actorId);
        $result['created_invoices'][] = [(int) $student['id'], $invoiceId];
    }
    return $result;
}

function school_fee_invoice_parent_notice(int $schoolId, int $studentId, string $title, string $body, int $invoiceId): void
{
    $stmt = db()->prepare(
        "SELECT DISTINCT u.id FROM school_students ss
         JOIN school_guardians g ON g.id = ss.guardian_id AND g.school_id = ss.school_id
         JOIN users u ON LOWER(u.email) = LOWER(g.email) AND u.status = 'active' AND u.email_verified_at IS NOT NULL
         WHERE ss.id = ? AND ss.school_id = ?"
    );
    $stmt->execute([$studentId, $schoolId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $parentId) {
        create_user_notification((int) $parentId, 'school-invoice-' . $invoiceId, 'other', $title, $body, 'parent_portal.php');
    }
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$canManageFees = school_has_permission($user, $schoolId, 'fees.manage');
$canManagePayments = school_has_permission($user, $schoolId, 'payments.manage');
$canViewReports = school_has_permission($user, $schoolId, 'reports.view') || $canManageFees;
if (!$canManageFees && !$canManagePayments) {
    require_school_permission($schoolId, 'fees.manage');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'record_school_fee_payment') {
        if (!$canManagePayments) {
            require_school_permission($schoolId, 'payments.manage');
        }
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        $amountCents = school_fee_amount_cents($_POST['amount'] ?? null);
        $coverage = (string) ($_POST['payment_coverage'] ?? '');
        $method = (string) ($_POST['payment_method'] ?? '');
        $reference = trim((string) ($_POST['payment_reference'] ?? ''));
        $payerName = trim((string) ($_POST['payer_name'] ?? ''));
        if ($studentId === false || $studentId < 1 || $termId === false || $termId < 1
            || $amountCents === null || $amountCents < 1
            || !in_array($coverage, ['part', 'term', 'academic_year'], true)
            || !in_array($method, ['cash', 'bank_deposit', 'bank_transfer', 'mobile_money', 'card', 'offline'], true)
            || strlen($reference) > 80 || strlen($payerName) > 120) {
            flash('error', 'Select a student and period, enter the amount received, and choose a valid payment type and method.');
            redirect('fee_management.php?section=record-payment');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $studentStmt = $pdo->prepare(
                'SELECT ss.id, ss.full_name, ss.admission_date, sc.level AS class_level,
                        sc.academic_year AS class_academic_year, sc.status AS class_status
                 FROM school_students ss
                 LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
                 WHERE ss.id = ? AND ss.school_id = ? AND ss.school_status = "active"
                 FOR UPDATE'
            );
            $studentStmt->execute([$studentId, $schoolId]);
            $student = $studentStmt->fetch();
            if (!$student || !$student['class_level'] || $student['class_status'] !== 'active') {
                throw new RuntimeException('Select an active student with an active class assignment.');
            }

            $termStmt = $pdo->prepare(
                'SELECT term.id, term.term_name, term.starts_on, term.ends_on, year.academic_year,
                        year.status AS year_status, year.is_active
                 FROM school_terms term
                 JOIN school_academic_years year
                   ON year.id = term.academic_year_id AND year.school_id = term.school_id
                 WHERE term.id = ? AND term.school_id = ?'
            );
            $termStmt->execute([$termId, $schoolId]);
            $selectedTerm = $termStmt->fetch();
            if (!$selectedTerm || !school_fee_supported_term((string) $selectedTerm['term_name'])
                || $selectedTerm['year_status'] !== 'open' || (int) $selectedTerm['is_active'] !== 1) {
                throw new RuntimeException('Choose a term in the school’s active, open academic year.');
            }
            if ((string) $student['class_academic_year'] !== (string) $selectedTerm['academic_year']) {
                throw new RuntimeException('The student’s class is not assigned to the selected academic year.');
            }
            if (!school_fee_student_admission_eligible(
                $student['admission_date'] === null ? null : (string) $student['admission_date'],
                (string) $selectedTerm['ends_on']
            )) {
                throw new RuntimeException('The student must have an admission date on or before the selected term ends.');
            }

            $yearTermsStmt = $pdo->prepare(
                'SELECT term.id, term.term_name, term.starts_on, term.ends_on, year.academic_year
                 FROM school_terms term
                 JOIN school_academic_years year
                   ON year.id = term.academic_year_id AND year.school_id = term.school_id
                 WHERE term.school_id = ? AND year.academic_year = ? AND year.status = "open"
                   AND year.is_active = 1
                 ORDER BY term.starts_on, term.id'
            );
            $yearTermsStmt->execute([$schoolId, (string) $selectedTerm['academic_year']]);
            $yearTerms = array_values(array_filter(
                $yearTermsStmt->fetchAll(),
                static fn(array $yearTerm): bool => school_fee_supported_term((string) $yearTerm['term_name'])
            ));
            $assessmentTerms = $coverage === 'academic_year'
                ? $yearTerms
                : array_values(array_filter(
                    $yearTerms,
                    static fn(array $yearTerm): bool => (int) $yearTerm['id'] === $termId
                ));
            if ($assessmentTerms === []) {
                throw new RuntimeException('No supported configured terms are available for the selected period.');
            }

            foreach ($assessmentTerms as $assessmentTerm) {
                school_assess_term_invoices($schoolId, (int) $assessmentTerm['id'], $studentId, (int) $user['id']);
            }

            $periodNames = array_column($assessmentTerms, 'term_name');
            $periodPlaceholders = implode(',', array_fill(0, count($periodNames), '?'));
            $invoiceStmt = $pdo->prepare(
                'SELECT invoice.id, invoice.amount, invoice.balance, invoice.period_name, term.starts_on
                 FROM school_invoices invoice
                 JOIN school_academic_years year
                   ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
                 JOIN school_terms term
                   ON term.school_id = year.school_id AND term.academic_year_id = year.id
                  AND term.term_name = invoice.period_name
                 WHERE invoice.school_id = ? AND invoice.student_id = ? AND invoice.academic_year = ?
                   AND invoice.period_name IN (' . $periodPlaceholders . ')
                 ORDER BY term.starts_on, invoice.id FOR UPDATE'
            );
            $invoiceStmt->execute(array_merge(
                [$schoolId, $studentId, (string) $selectedTerm['academic_year']],
                $periodNames
            ));
            $invoices = $invoiceStmt->fetchAll();
            if ($invoices === []) {
                throw new RuntimeException('No fee schedule is configured for this student’s class and selected period.');
            }

            $amountRemaining = $amountCents;
            $allocations = [];
            foreach ($invoices as $invoice) {
                $balanceCents = (int) round((float) $invoice['balance'] * 100);
                if ($balanceCents <= 0 || $amountRemaining <= 0) {
                    continue;
                }
                $allocationCents = min($balanceCents, $amountRemaining);
                $allocations[(int) $invoice['id']] = $allocationCents;
                $amountRemaining -= $allocationCents;
            }
            if ($allocations === []) {
                throw new RuntimeException('There is no outstanding fee balance to apply this payment to.');
            }
            $anchorInvoiceId = (int) array_key_first($allocations);
            if ($reference !== '') {
                $referenceStmt = $pdo->prepare(
                    'SELECT 1 FROM school_payments WHERE school_id = ? AND payment_reference = ? LIMIT 1'
                );
                $referenceStmt->execute([$schoolId, $reference]);
                if ($referenceStmt->fetchColumn()) {
                    throw new RuntimeException('That payment reference has already been recorded for this school.');
                }
            }
            $paymentStmt = $pdo->prepare(
                'INSERT INTO school_payments
                 (school_id, invoice_id, student_id, amount, payment_coverage, payment_method, payment_reference, payer_name, status, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", ?)'
            );
            $paymentStmt->execute([
                $schoolId,
                $anchorInvoiceId,
                $studentId,
                number_format($amountCents / 100, 2, '.', ''),
                $coverage,
                $method,
                $reference ?: null,
                $payerName ?: null,
                (int) $user['id'],
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            $allocationStmt = $pdo->prepare(
                'INSERT INTO school_payment_allocations
                 (school_id, student_id, payment_id, invoice_id, amount, allocation_type, status, created_by)
                 VALUES (?, ?, ?, ?, ?, "manual", "pending", ?)'
            );
            foreach ($allocations as $allocationInvoiceId => $allocationCents) {
                $allocationStmt->execute([
                    $schoolId,
                    $studentId,
                    $paymentId,
                    $allocationInvoiceId,
                    number_format($allocationCents / 100, 2, '.', ''),
                    (int) $user['id'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('School fee dashboard payment recording failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The payment could not be recorded. No invoice balance was changed.');
            redirect('fee_management.php?section=record-payment');
        }
        audit((int) $user['id'], 'school_fee_dashboard_payment_recorded_pending', 'school_id=' . $schoolId . '; payment_id=' . $paymentId . '; student_id=' . $studentId . '; coverage=' . $coverage);
        flash('success', 'Payment recorded and is awaiting verification. A printable receipt will be available from the student account after verification.');
        redirect('fee_management.php?section=student-accounts&student_id=' . $studentId);
    }
}

$section = (string) ($_GET['section'] ?? 'dashboard');
$sections = ['dashboard', 'record-payment', 'student-accounts', 'payment-reports', 'set-class-fees'];
if (!in_array($section, $sections, true)) {
    $section = 'dashboard';
}
$selectedYear = trim((string) ($_GET['academic_year'] ?? ''));
$selectedTermId = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
$selectedClass = trim((string) ($_GET['class_level'] ?? ''));
$selectedStudentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);

$yearsStmt = db()->prepare(
    'SELECT academic_year, status, is_active
     FROM school_academic_years WHERE school_id = ?
     ORDER BY starts_on DESC'
);
$yearsStmt->execute([$schoolId]);
$academicYears = $yearsStmt->fetchAll();
$termsStmt = db()->prepare(
    'SELECT term.id, term.term_name, term.starts_on, term.ends_on,
            year.academic_year, year.status AS year_status, year.is_active
     FROM school_terms term
     JOIN school_academic_years year
       ON year.id = term.academic_year_id AND year.school_id = term.school_id
     WHERE term.school_id = ? AND year.status = "open" AND year.is_active = 1
     ORDER BY term.starts_on, term.id'
);
$termsStmt->execute([$schoolId]);
$openTerms = array_values(array_filter(
    $termsStmt->fetchAll(),
    static fn(array $term): bool => school_fee_supported_term((string) $term['term_name'])
));
$allTermsStmt = db()->prepare(
    'SELECT term.id, term.term_name, term.starts_on, year.academic_year
     FROM school_terms term
     JOIN school_academic_years year
       ON year.id = term.academic_year_id AND year.school_id = term.school_id
     WHERE term.school_id = ? ORDER BY term.starts_on DESC'
);
$allTermsStmt->execute([$schoolId]);
$allTerms = array_values(array_filter(
    $allTermsStmt->fetchAll(),
    static fn(array $term): bool => school_fee_supported_term((string) $term['term_name'])
));
if (!array_key_exists('academic_year', $_GET) && $academicYears !== []) {
    $activeYear = array_values(array_filter($academicYears, static fn(array $year): bool => (int) $year['is_active'] === 1));
    $selectedYear = (string) ($activeYear[0]['academic_year'] ?? $academicYears[0]['academic_year']);
}
if (!array_key_exists('term_id', $_GET)) {
    $calendarTerm = school_academic_period_for_date($schoolId);
    $calendarYear = (string) ($calendarTerm['academic_year'] ?? '');
    $selectedTermId = $calendarYear === $selectedYear
        ? filter_var($calendarTerm['term_id'] ?? null, FILTER_VALIDATE_INT)
        : false;
    if ($selectedTermId === false || $selectedTermId < 1) {
        $selectedTerm = array_values(array_filter(
            $openTerms,
            static fn(array $term): bool => (string) $term['academic_year'] === $selectedYear
        ));
        $selectedTermId = (int) ($selectedTerm[0]['id'] ?? 0);
    }
}
$selectedTerm = null;
foreach ($allTerms as $term) {
    if ((int) $term['id'] === (int) $selectedTermId) {
        $selectedTerm = $term;
        break;
    }
}
$classLevels = school_class_levels($schoolId, true);
$studentsStmt = db()->prepare(
    'SELECT student.id, student.full_name, student.admission_number,
            class.level AS class_level, class.section AS class_section, class.academic_year
     FROM school_students student
     LEFT JOIN school_classes class
       ON class.id = student.class_id AND class.school_id = student.school_id
     WHERE student.school_id = ? AND student.school_status = "active"
     ORDER BY student.full_name'
);
$studentsStmt->execute([$schoolId]);
$students = $studentsStmt->fetchAll();

$invoiceConditions = ['invoice.school_id = ?'];
$invoiceParams = [$schoolId];
if ($selectedYear !== '') {
    $invoiceConditions[] = 'invoice.academic_year = ?';
    $invoiceParams[] = $selectedYear;
}
if ($selectedTermId !== false && $selectedTermId > 0) {
    $invoiceConditions[] = 'term.id = ?';
    $invoiceParams[] = (int) $selectedTermId;
}
if ($selectedClass !== '') {
    $invoiceConditions[] = 'invoice.class_level = ?';
    $invoiceParams[] = $selectedClass;
}
$invoiceFilter = implode(' AND ', $invoiceConditions);
$invoiceStmt = db()->prepare(
    'SELECT invoice.id, invoice.student_id, invoice.invoice_number, invoice.amount,
            invoice.balance, invoice.adjustments_total, invoice.status, invoice.due_date,
            invoice.class_level, invoice.academic_year, invoice.period_name, invoice.fee_snapshot,
            student.full_name, student.admission_number, term.starts_on
     FROM school_invoices invoice
     JOIN school_students student
       ON student.id = invoice.student_id AND student.school_id = invoice.school_id
     LEFT JOIN school_academic_years year
       ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
     LEFT JOIN school_terms term
       ON term.school_id = year.school_id AND term.academic_year_id = year.id
      AND term.term_name = invoice.period_name
     WHERE ' . $invoiceFilter . '
     ORDER BY invoice.created_at DESC'
);
$invoiceStmt->execute($invoiceParams);
$filteredInvoices = $invoiceStmt->fetchAll();

$summaryStmt = db()->prepare(
    'SELECT COUNT(DISTINCT invoice.student_id) AS billed_students,
            COALESCE(SUM(invoice.amount), 0) AS fees_expected,
            COALESCE(SUM(invoice.balance), 0) AS outstanding,
            COALESCE(SUM(CASE WHEN invoice.balance <= 0 THEN 1 ELSE 0 END), 0) AS paid_invoice_count
     FROM school_invoices invoice
     LEFT JOIN school_academic_years year
       ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
     LEFT JOIN school_terms term
       ON term.school_id = year.school_id AND term.academic_year_id = year.id
      AND term.term_name = invoice.period_name
     WHERE ' . $invoiceFilter
);
$summaryStmt->execute($invoiceParams);
$feeSummary = $summaryStmt->fetch();
$collectedStmt = db()->prepare(
    'SELECT COALESCE(SUM(allocation.amount), 0)
     FROM school_payment_allocations allocation
     JOIN school_payments payment
       ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
      AND payment.student_id = allocation.student_id AND payment.status = "verified"
     JOIN school_invoices invoice
       ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
      AND invoice.student_id = allocation.student_id
     LEFT JOIN school_academic_years year
       ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
     LEFT JOIN school_terms term
       ON term.school_id = year.school_id AND term.academic_year_id = year.id
      AND term.term_name = invoice.period_name
     WHERE allocation.school_id = ? AND allocation.status = "active"
       AND ' . implode(' AND ', $invoiceConditions)
);
$collectedStmt->execute(array_merge([$schoolId], $invoiceParams));
$collectedTotal = (float) $collectedStmt->fetchColumn();
$monthlyCollectionStmt = db()->prepare(
    'SELECT monthly.month_label, monthly.collected
     FROM (
         SELECT DATE_FORMAT(payment.received_at, "%Y-%m") AS month_label,
                COALESCE(SUM(allocation.amount), 0) AS collected
         FROM school_payment_allocations allocation
         JOIN school_payments payment
           ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
          AND payment.student_id = allocation.student_id AND payment.status = "verified"
         JOIN school_invoices invoice
           ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
          AND invoice.student_id = allocation.student_id
         LEFT JOIN school_academic_years year
           ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
         LEFT JOIN school_terms term
           ON term.school_id = year.school_id AND term.academic_year_id = year.id
          AND term.term_name = invoice.period_name
         WHERE allocation.school_id = ? AND allocation.status = "active"
           AND ' . $invoiceFilter . '
         GROUP BY DATE_FORMAT(payment.received_at, "%Y-%m")
         ORDER BY month_label DESC
         LIMIT 12
     ) monthly
     ORDER BY monthly.month_label ASC'
);
$monthlyCollectionStmt->execute(array_merge([$schoolId], $invoiceParams));
$monthlyCollectionRows = $monthlyCollectionStmt->fetchAll();
$monthlyCollectionChart = [];
foreach ($monthlyCollectionRows as $row) {
    $monthlyCollectionChart[(string) $row['month_label']] = (float) $row['collected'];
}
if ($monthlyCollectionChart !== []) {
    $collectionByMonth = $monthlyCollectionChart;
    $monthlyCollectionChart = [];
    $firstMonth = DateTimeImmutable::createFromFormat('!Y-m', (string) array_key_first($collectionByMonth));
    $lastMonth = DateTimeImmutable::createFromFormat('!Y-m', (string) array_key_last($collectionByMonth));
    if ($firstMonth !== false && $lastMonth !== false) {
        for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('+1 month')) {
            $monthKey = $month->format('Y-m');
            $monthlyCollectionChart[$monthKey] = $collectionByMonth[$monthKey] ?? 0.0;
        }
    } else {
        $monthlyCollectionChart = $collectionByMonth;
    }
}
$todayStmt = db()->prepare(
    'SELECT COALESCE(SUM(payment.amount), 0)
     FROM school_payments payment
     JOIN school_invoices invoice
       ON invoice.id = payment.invoice_id AND invoice.school_id = payment.school_id
      AND invoice.student_id = payment.student_id
     LEFT JOIN school_academic_years year
       ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
     LEFT JOIN school_terms term
       ON term.school_id = year.school_id AND term.academic_year_id = year.id
      AND term.term_name = invoice.period_name
     WHERE payment.school_id = ? AND payment.status = "verified"
       AND DATE(payment.received_at) = CURRENT_DATE()
       AND ' . implode(' AND ', $invoiceConditions)
);
$todayStmt->execute(array_merge([$schoolId], $invoiceParams));
$todayTotal = (float) $todayStmt->fetchColumn();
$statusStmt = db()->prepare(
    'SELECT account.student_id, SUM(account.amount) AS fees, SUM(account.balance) AS balance
     FROM (
         SELECT invoice.student_id, SUM(invoice.amount) AS amount, SUM(invoice.balance) AS balance
         FROM school_invoices invoice
         LEFT JOIN school_academic_years year
           ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
         LEFT JOIN school_terms term
           ON term.school_id = year.school_id AND term.academic_year_id = year.id
          AND term.term_name = invoice.period_name
         WHERE ' . $invoiceFilter . '
         GROUP BY invoice.student_id
     ) account
     GROUP BY account.student_id'
);
$statusStmt->execute($invoiceParams);
$paidStudents = $partialStudents = $unpaidStudents = 0;
foreach ($statusStmt->fetchAll() as $accountStatus) {
    if ((float) $accountStatus['balance'] <= 0.00001) {
        $paidStudents++;
    } elseif ((float) $accountStatus['balance'] < (float) $accountStatus['fees']) {
        $partialStudents++;
    } else {
        $unpaidStudents++;
    }
}

$classSummaryStmt = db()->prepare(
    'SELECT invoice.class_level, COUNT(DISTINCT invoice.student_id) AS students,
            COALESCE(SUM(invoice.amount), 0) AS expected,
            COALESCE(SUM(invoice.amount - invoice.balance), 0) AS settled,
            COALESCE(SUM(invoice.balance), 0) AS outstanding
     FROM school_invoices invoice
     LEFT JOIN school_academic_years year
       ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
     LEFT JOIN school_terms term
       ON term.school_id = year.school_id AND term.academic_year_id = year.id
      AND term.term_name = invoice.period_name
     WHERE ' . $invoiceFilter . '
     GROUP BY invoice.class_level ORDER BY invoice.class_level'
);
$classSummaryStmt->execute($invoiceParams);
$classSummaryRows = $classSummaryStmt->fetchAll();
$classOutstandingChart = [];
foreach ($classSummaryRows as $row) {
    $classOutstandingChart[(string) ($row['class_level'] ?: 'No class')] = (float) $row['outstanding'];
}
$recentStmt = db()->prepare(
    'SELECT payment.id, payment.amount, payment.payment_coverage, payment.payment_method,
            payment.payment_reference, payment.status, payment.received_at,
            student.full_name, invoice.academic_year, invoice.period_name, invoice.class_level
     FROM school_payments payment
     JOIN school_students student ON student.id = payment.student_id AND student.school_id = payment.school_id
     JOIN school_invoices invoice
       ON invoice.id = payment.invoice_id AND invoice.school_id = payment.school_id
      AND invoice.student_id = payment.student_id
     LEFT JOIN school_academic_years year
       ON year.school_id = invoice.school_id AND year.academic_year = invoice.academic_year
     LEFT JOIN school_terms term
       ON term.school_id = year.school_id AND term.academic_year_id = year.id
      AND term.term_name = invoice.period_name
     WHERE payment.school_id = ? AND ' . implode(' AND ', $invoiceConditions) . '
     ORDER BY payment.received_at DESC LIMIT 12'
);
$recentStmt->execute(array_merge([$schoolId], $invoiceParams));
$recentPayments = $recentStmt->fetchAll();
$maxClassExpected = max(array_map(static fn(array $row): float => (float) $row['expected'], $classSummaryRows) ?: [0.0]);

$studentInvoices = [];
$studentPaymentRows = [];
if ($selectedStudentId !== false && $selectedStudentId > 0) {
    $studentInvoices = array_values(array_filter(
        $filteredInvoices,
        static fn(array $invoice): bool => (int) $invoice['student_id'] === (int) $selectedStudentId
    ));
    $studentPaymentStmt = db()->prepare(
        'SELECT payment.id, payment.amount, payment.payment_coverage, payment.payment_method,
                payment.payment_reference, payment.status, payment.received_at,
                allocation.amount AS allocated_amount, invoice.invoice_number,
                invoice.academic_year, invoice.period_name
         FROM school_payments payment
         JOIN school_payment_allocations allocation
           ON allocation.payment_id = payment.id AND allocation.school_id = payment.school_id
          AND allocation.student_id = payment.student_id
         JOIN school_invoices invoice
           ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
          AND invoice.student_id = allocation.student_id
         WHERE payment.school_id = ? AND payment.student_id = ?
         ORDER BY payment.received_at DESC, allocation.id'
    );
    $studentPaymentStmt->execute([$schoolId, (int) $selectedStudentId]);
    $studentPaymentRows = $studentPaymentStmt->fetchAll();
}

$feeScheduleStmt = db()->prepare(
    'SELECT id, name, amount, due_day, description, class_level, academic_year,
            period_name, fee_category, effective_from
     FROM school_fee_structures
     WHERE school_id = ?
     ORDER BY academic_year DESC, period_name, class_level, name'
);
$feeScheduleStmt->execute([$schoolId]);
$feeSchedules = $feeScheduleStmt->fetchAll();
$pendingPaymentsStmt = db()->prepare(
    'SELECT payment.id, payment.student_id, payment.amount, payment.payment_coverage, payment.payment_method,
            payment.payment_reference, payment.received_at, student.full_name,
            invoice.invoice_number, invoice.period_name
     FROM school_payments payment
     JOIN school_students student ON student.id = payment.student_id AND student.school_id = payment.school_id
     JOIN school_invoices invoice ON invoice.id = payment.invoice_id AND invoice.school_id = payment.school_id
     WHERE payment.school_id = ? AND payment.status = "pending"
     ORDER BY payment.received_at'
);
$pendingPaymentsStmt->execute([$schoolId]);
$pendingPayments = $pendingPaymentsStmt->fetchAll();
$pendingAllocationsStmt = db()->prepare(
    'SELECT allocation.payment_id, invoice.invoice_number, invoice.period_name, allocation.amount
     FROM school_payment_allocations allocation
     JOIN school_invoices invoice
       ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
     WHERE allocation.school_id = ? AND allocation.status = "pending"
     ORDER BY allocation.payment_id, invoice.id'
);
$pendingAllocationsStmt->execute([$schoolId]);
$pendingAllocations = [];
foreach ($pendingAllocationsStmt->fetchAll() as $pendingAllocation) {
    $pendingAllocations[(int) $pendingAllocation['payment_id']][] = $pendingAllocation;
}

render_header('Fees Management');
?>
<section class="panel"><p class="eyebrow">FINANCE · FEES MANAGEMENT</p><h1>Fees Management</h1><p class="muted">Record payments received by the school, review student fee accounts, and manage class fee schedules. Online parent payments are not required.</p></section>
<nav class="report-action-grid" aria-label="Fee management functions">
    <?php foreach ([
        'dashboard' => ['Dashboard', 'Review school collections and balances.'],
        'record-payment' => ['Record Payment', 'Save a cash, deposit, or transfer payment.'],
        'student-accounts' => ['Student Fee Accounts', 'Search account balances and payment history.'],
        'payment-reports' => ['Payment Reports', 'Review collections and outstanding balances.'],
        'set-class-fees' => ['Set Class Fees', 'Configure fee items by class, year, and term.'],
    ] as $key => [$title, $description]): ?>
        <article class="report-action-card"><span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span><div><h2><?= e($title) ?></h2><p><?= e($description) ?></p></div><a class="button <?= $section === $key ? 'button-primary' : '' ?>" href="fee_management.php?section=<?= e($key) ?>">Open</a></article>
    <?php endforeach; ?>
    <?php if ($canManagePayments): ?>
        <article class="report-action-card"><span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('finance') ?></span><div><h2>Payment Reconciliation</h2><p>Compare a bank or provider CSV with school payment references and amounts.</p></div><a class="button" href="payment_reconciliation.php">Open Reconciliation</a></article>
    <?php endif; ?>
</nav>
<section class="panel spaced-top">
    <form method="get" action="fee_management.php" class="form-inline">
        <input type="hidden" name="section" value="<?= e($section) ?>">
        <label>Academic year<select name="academic_year"><option value="">All years</option><?php foreach ($academicYears as $year): ?><option value="<?= e((string) $year['academic_year']) ?>" <?= $selectedYear === (string) $year['academic_year'] ? 'selected' : '' ?>><?= e((string) $year['academic_year']) ?></option><?php endforeach; ?></select></label>
        <label>Term<select name="term_id"><option value="">All terms</option><?php foreach ($allTerms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= (int) $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e((string) $term['academic_year'] . ' · ' . (string) $term['term_name']) ?></option><?php endforeach; ?></select></label>
        <label>Class<select name="class_level"><option value="">All classes</option><?php foreach ($classLevels as $level): ?><option value="<?= e((string) $level) ?>" <?= $selectedClass === (string) $level ? 'selected' : '' ?>><?= e((string) $level) ?></option><?php endforeach; ?></select></label>
        <?php if ($section === 'student-accounts' && $selectedStudentId !== false): ?><input type="hidden" name="student_id" value="<?= (int) $selectedStudentId ?>"><?php endif; ?>
        <button class="button" type="submit">Apply filters</button>
    </form>
</section>
<?php if ($section === 'dashboard'): ?>
<section class="form-grid two-columns top-gap-small">
    <?php foreach ([
        ['Total Fees Expected', (float) $feeSummary['fees_expected']],
        ['Total Amount Collected', $collectedTotal],
        ['Outstanding Balance', (float) $feeSummary['outstanding']],
        ["Today's Collections", $todayTotal],
    ] as [$label, $value]): ?><article class="panel"><p class="eyebrow"><?= e($label) ?></p><h2>GH₵ <?= e(number_format((float) $value, 2)) ?></h2></article><?php endforeach; ?>
    <?php foreach ([['Fully Paid', $paidStudents, 'paid'], ['Partially Paid', $partialStudents, 'partial'], ['Unpaid', $unpaidStudents, 'outstanding']] as [$label, $count, $status]): ?><article class="panel"><p class="eyebrow">BILLED STUDENTS · <span class="status-pill <?= e(school_fee_status_class($status)) ?>"><?= e($label) ?></span></p><h2><?= (int) $count ?></h2></article><?php endforeach; ?>
</section>
<section class="panel top-gap-small"><h2>Collection by class</h2>
    <?php if ($classSummaryRows === []): ?><p class="muted">No invoices are available for these filters. The class-fee schedule remains available under Set Class Fees.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Class</th><th>Students billed</th><th>Fees expected</th><th>Settled</th><th>Outstanding</th><th>Collection</th></tr></thead><tbody>
    <?php foreach ($classSummaryRows as $row): $percent = (float) $row['expected'] > 0 ? min(100, ((float) $row['settled'] / (float) $row['expected']) * 100) : 0; ?><tr><td><?= e((string) $row['class_level']) ?></td><td><?= (int) $row['students'] ?></td><td>GH₵ <?= e(number_format((float) $row['expected'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $row['settled'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $row['outstanding'], 2)) ?></td><td><progress max="100" value="<?= e((string) $percent) ?>"><?= e(number_format($percent, 1)) ?>%</progress></td></tr><?php endforeach; ?>
    </tbody></table></div><?php render_dashboard_bar_chart('Outstanding balances by class · current filters', $classOutstandingChart, 'GHS'); ?><?php endif; ?>
<?php render_dashboard_line_chart('Monthly verified collections · current filters', $monthlyCollectionChart, 'GHS'); ?>
</section>
<section class="panel top-gap-small"><h2>Recent payments</h2><?php if ($recentPayments === []): ?><p class="muted">No payments match the selected filters.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Student</th><th>Academic period</th><th>Amount received</th><th>Coverage</th><th>Status</th><th>Receipt</th></tr></thead><tbody><?php foreach ($recentPayments as $payment): ?><tr><td><?= e((string) $payment['received_at']) ?></td><td><?= e((string) $payment['full_name']) ?></td><td><?= e((string) $payment['academic_year'] . ' · ' . (string) $payment['period_name']) ?></td><td>GH₵ <?= e(number_format((float) $payment['amount'], 2)) ?></td><td><?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?></td><td><?= e((string) $payment['status']) ?></td><td><?php if ($payment['status'] === 'verified'): ?><a href="school_payment_receipt.php?id=<?= (int) $payment['id'] ?>">Print receipt</a><?php elseif ($payment['status'] === 'pending'): ?>Available after verification<?php else: ?>Not available<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php elseif ($section === 'record-payment'): ?>
<section class="panel top-gap-small"><h2>Record a payment received by the school</h2>
    <?php if (!$canManagePayments): ?><p class="notice notice-error">Your account can manage fee schedules but does not have permission to record payments. An authorized accountant can enter it.</p><?php else: ?>
    <p class="muted">The amount must match the money actually received. Payments are saved as separate transactions and remain pending until an authorised user verifies them.</p>
    <form method="post" action="fee_management.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="record_school_fee_payment">
        <div class="form-grid two-columns">
            <label>Student<select name="student_id" required><option value="">Search/select student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e((string) $student['full_name'] . ' · ' . ($student['admission_number'] ?: 'No ID') . ' · ' . ($student['class_level'] ?: 'No class')) ?></option><?php endforeach; ?></select></label>
            <label>Term to pay<select name="term_id" required><option value="">Select term</option><?php foreach ($openTerms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= (int) $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e((string) $term['academic_year'] . ' · ' . (string) $term['term_name']) ?></option><?php endforeach; ?></select></label>
            <label>Payment coverage<select name="payment_coverage" required><option value="part">Part payment (including half payment)</option><option value="term">Term payment</option><option value="academic_year">Full academic year</option></select></label>
            <label>Amount received (GH₵)<input name="amount" type="number" min="0.01" step="0.01" required></label>
            <label>Payment method<select name="payment_method"><option value="cash">Cash</option><option value="bank_deposit">Bank deposit</option><option value="bank_transfer">Bank transfer</option><option value="mobile_money">Mobile money</option><option value="card">Card</option><option value="offline">Other/offline</option></select></label>
            <label>Parent/payer name (optional)<input name="payer_name" maxlength="120"></label>
            <label>Reference (optional)<input name="payment_reference" maxlength="80"></label>
        </div>
        <p class="muted">Term payments are applied to the selected term. Full academic-year payments are applied across that year’s term balances in date order. Any verified surplus remains tracked as student credit. Existing charges and transactions are preserved.</p>
        <button class="button button-primary" type="submit">Save Payment</button>
    </form><?php endif; ?>
</section>
<?php elseif ($section === 'student-accounts'): ?>
<section class="panel top-gap-small"><h2>Student fee accounts</h2>
    <form method="get" class="form-inline"><input type="hidden" name="section" value="student-accounts"><label>Find student<select name="student_id" required><option value="">Select student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>" <?= $selectedStudentId !== false && (int) $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>><?= e((string) $student['full_name'] . ' · ' . ($student['admission_number'] ?: 'No ID') . ' · ' . ($student['class_level'] ?: 'No class')) ?></option><?php endforeach; ?></select></label><button class="button button-primary" type="submit">Open account</button><button class="button" type="button" data-print-button>Print statement</button></form>
    <?php if ($selectedStudentId !== false && $selectedStudentId > 0): $accountExpected = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $studentInvoices)); $accountBalance = array_sum(array_map(static fn(array $row): float => (float) $row['balance'], $studentInvoices)); ?>
    <?php $accountStatus = $accountBalance <= 0.00001 ? 'paid' : ($accountBalance < $accountExpected ? 'partial' : 'outstanding'); $accountStatusLabel = $accountStatus === 'paid' ? 'Fully paid' : ($accountStatus === 'partial' ? 'Partially paid' : 'Unpaid'); ?>
    <h3>Account summary</h3><p>Fees charged: GH₵ <?= e(number_format($accountExpected, 2)) ?> · Outstanding: GH₵ <?= e(number_format($accountBalance, 2)) ?> · Status: <span class="status-pill <?= e(school_fee_status_class($accountStatus)) ?>"><?= e($accountStatusLabel) ?></span></p>
    <h3>Charges and balances</h3><?php if ($studentInvoices === []): ?><p class="muted">No invoice or assessed fee record for this student in the selected filters.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Receipt/account reference</th><th>Academic period</th><th>Fees charged</th><th>Balance</th><th>Status</th></tr></thead><tbody><?php foreach ($studentInvoices as $invoice): ?><tr><td><?= e((string) $invoice['invoice_number']) ?></td><td><?= e((string) $invoice['academic_year'] . ' · ' . $invoice['period_name']) ?></td><td>GH₵ <?= e(number_format((float) $invoice['amount'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $invoice['balance'], 2)) ?></td><td><span class="status-pill <?= e(school_fee_status_class((string) $invoice['status'])) ?>"><?= e(ucfirst((string) $invoice['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <h3>Payment history and receipts</h3><?php if ($studentPaymentRows === []): ?><p class="muted">No payment history is available for this student.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Receipt</th><th>Period</th><th>Payment received</th><th>Allocated</th><th>Coverage</th><th>Method</th><th>Status</th></tr></thead><tbody><?php foreach ($studentPaymentRows as $payment): ?><tr><td><?= e((string) $payment['received_at']) ?></td><td><?php if ($payment['status'] === 'verified'): ?><a class="button button-primary" href="school_payment_receipt.php?id=<?= (int) $payment['id'] ?>">Print receipt</a><?php elseif ($payment['status'] === 'pending'): ?>Available after verification<?php else: ?>Not available<?php endif; ?></td><td><?= e((string) $payment['academic_year'] . ' · ' . (string) $payment['period_name']) ?></td><td>GH₵ <?= e(number_format((float) $payment['amount'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $payment['allocated_amount'], 2)) ?></td><td><?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?></td><td><?= e((string) $payment['payment_method']) ?></td><td><?= e((string) $payment['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php endif; ?>
</section>
<?php elseif ($section === 'payment-reports'): ?>
<section class="panel top-gap-small"><h2>Payment reports</h2><?php if (!$canViewReports): ?><p class="notice notice-error">Your account does not have permission to view financial reports.</p><?php else: ?><button class="button" type="button" data-print-button>Print report</button><h3>Fees charged versus verified collections</h3><p>Total fees charged: GH₵ <?= e(number_format((float) $feeSummary['fees_expected'], 2)) ?> · Applied verified collections: GH₵ <?= e(number_format($collectedTotal, 2)) ?> · Outstanding: GH₵ <?= e(number_format((float) $feeSummary['outstanding'], 2)) ?></p>
    <?php render_dashboard_bar_chart('Fees charged, verified collections and outstanding · current filters', [
        'Fees charged' => (float) $feeSummary['fees_expected'],
        'Verified collections' => $collectedTotal,
        'Outstanding' => (float) $feeSummary['outstanding'],
    ], 'GHS'); ?>
    <?php render_dashboard_line_chart('Monthly verified collections · current filters', $monthlyCollectionChart, 'GHS'); ?>
    <div class="table-scroll"><table><thead><tr><th>Class</th><th>Students</th><th>Fees charged</th><th>Collected</th><th>Outstanding</th></tr></thead><tbody><?php foreach ($classSummaryRows as $row): ?><tr><td><?= e((string) $row['class_level']) ?></td><td><?= (int) $row['students'] ?></td><td>GH₵ <?= e(number_format((float) $row['expected'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $row['settled'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $row['outstanding'], 2)) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <h3>Outstanding student balances</h3><div class="table-scroll"><table><thead><tr><th>Student</th><th>Class</th><th>Year</th><th>Term</th><th>Fees charged</th><th>Balance</th><th>Status</th></tr></thead><tbody><?php foreach ($filteredInvoices as $invoice): if ((float) $invoice['balance'] <= 0) continue; ?><tr><td><?= e((string) $invoice['full_name']) ?></td><td><?= e((string) ($invoice['class_level'] ?: '—')) ?></td><td><?= e((string) $invoice['academic_year']) ?></td><td><?= e((string) $invoice['period_name']) ?></td><td>GH₵ <?= e(number_format((float) $invoice['amount'], 2)) ?></td><td>GH₵ <?= e(number_format((float) $invoice['balance'], 2)) ?></td><td><span class="status-pill <?= e(school_fee_status_class((float) $invoice['balance'] < (float) $invoice['amount'] ? 'partial' : 'outstanding')) ?>"><?= (float) $invoice['balance'] < (float) $invoice['amount'] ? 'Partially paid' : 'Outstanding' ?></span></td></tr><?php endforeach; ?></tbody></table></div>
    <h3>Daily collections and transactions</h3><div class="table-scroll"><table><thead><tr><th>Payment date</th><th>Student</th><th>Coverage</th><th>Amount received</th><th>Period</th><th>Status</th></tr></thead><tbody><?php foreach ($recentPayments as $payment): ?><tr><td><?= e((string) $payment['received_at']) ?></td><td><?= e((string) $payment['full_name']) ?></td><td><?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?></td><td>GH₵ <?= e(number_format((float) $payment['amount'], 2)) ?></td><td><?= e((string) $payment['academic_year'] . ' · ' . (string) $payment['period_name']) ?></td><td><?= e((string) $payment['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php elseif ($section === 'set-class-fees'): ?>
<section class="panel top-gap-small"><h2>Set class fees</h2>
    <?php if (!$canManageFees): ?><p class="notice notice-error">Your account cannot configure class fees.</p><?php elseif ($openTerms === []): ?><p class="muted">No open academic years and terms are available. Configure them in Academic Years &amp; Terms first.</p><?php else: ?>
    <p>Add one fee item at a time for each class, academic year, and term. Add further items to make up the total. Changes apply to future assessments; existing invoices and payment history remain unchanged.</p>
    <form method="post" action="fees.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="add_fee_structure">
        <div class="form-grid two-columns"><label>Fee item<input name="name" maxlength="120" required></label><label>Amount (GH₵)<input name="amount" type="number" min="0.01" step="0.01" required></label><label>Class<select name="class_level" required><?php foreach (school_class_levels($schoolId) as $level): ?><option value="<?= e($level) ?>"><?= e($level) ?></option><?php endforeach; ?></select></label><label>Academic year and term<select name="term_id" required><?php foreach ($openTerms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= (int) $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e((string) $term['academic_year'] . ' · ' . (string) $term['term_name']) ?></option><?php endforeach; ?></select></label><label>Category<select name="fee_category"><?php foreach (['tuition'=>'Tuition','books'=>'Books','uniform'=>'Uniform','examination'=>'Examination','other'=>'Other'] as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Due day<input name="due_day" type="number" min="1" max="31" value="10" required></label><label>Effective from<input name="effective_from" type="date" value="<?= e((string) ($selectedTerm['starts_on'] ?? date('Y-m-d'))) ?>" required></label><label>Description<input name="description" maxlength="255"></label></div><button class="button button-primary" type="submit">Save fee item</button>
    </form><?php endif; ?>
    <h3>Configured fee items</h3><?php if ($feeSchedules === []): ?><p class="muted">No fee items are configured yet.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Fee item</th><th>Class</th><th>Year / term</th><th>Category</th><th>Amount</th><th>Effective from</th></tr></thead><tbody><?php foreach ($feeSchedules as $fee): ?><tr><td><?= e((string) $fee['name']) ?></td><td><?= e((string) $fee['class_level']) ?></td><td><?= e((string) $fee['academic_year'] . ' · ' . (string) $fee['period_name']) ?></td><td><?= e((string) $fee['fee_category']) ?></td><td>GH₵ <?= e(number_format((float) $fee['amount'], 2)) ?></td><td><?= e((string) $fee['effective_from']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php if ($canManagePayments && $pendingPayments !== []): ?>
<section class="panel top-gap-small"><h2>Payments awaiting verification</h2><p class="muted">Only verified payments reduce outstanding balances and produce printable receipts.</p><div class="table-scroll"><table><thead><tr><th>Payment</th><th>Student</th><th>Received</th><th>Allocations</th><th>Decision</th></tr></thead><tbody><?php foreach ($pendingPayments as $payment): ?><tr><td>GHS <?= e(number_format((float) $payment['amount'], 2)) ?> · <?= e(school_fee_payment_coverage_label((string) $payment['payment_coverage'])) ?> · <?= e((string) $payment['received_at']) ?></td><td><?= e((string) $payment['full_name']) ?></td><td><?= e((string) $payment['payment_method']) ?><?= $payment['payment_reference'] ? ' · ' . e((string) $payment['payment_reference']) : '' ?></td><td><?php foreach ($pendingAllocations[(int) $payment['id']] ?? [] as $allocation): ?><?= e((string) $allocation['invoice_number'] . ' · ' . (string) $allocation['period_name']) ?>: GHS <?= e(number_format((float) $allocation['amount'], 2)) ?><br><?php endforeach; ?></td><td><form method="post" action="invoices.php" class="form-inline"><?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><input type="hidden" name="return_student_id" value="<?= (int) $payment['student_id'] ?>"><button class="button button-primary" name="action" value="verify_payment">Verify</button><button class="button" name="action" value="reject_payment">Reject</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php render_footer(); ?>

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($paymentId === false || $paymentId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}
$stmt = db()->prepare(
    'SELECT p.id, p.student_id, p.amount, p.payment_coverage, p.payment_method,
            p.payment_reference, p.payer_name, p.received_by, p.received_at, p.verified_at,
            i.invoice_number, i.class_level, i.academic_year, i.period_name,
            ss.full_name AS student_name, ss.admission_number,
            s.id AS school_id, s.name AS school_name, s.logo_path,
            g.full_name AS guardian_name, g.email AS guardian_email,
            receiver.full_name AS receiver_name
     FROM school_payments p
     JOIN school_invoices i ON i.id = p.invoice_id AND i.school_id = p.school_id
     JOIN school_students ss ON ss.id = p.student_id AND ss.school_id = p.school_id
     JOIN schools s ON s.id = p.school_id
     LEFT JOIN school_guardians g ON g.id = ss.guardian_id AND g.school_id = ss.school_id
     LEFT JOIN users receiver ON receiver.id = p.received_by
     WHERE p.id = ? AND p.school_id = ? AND p.status = "verified" LIMIT 1'
);
$stmt->execute([$paymentId, $schoolId]);
$receipt = $stmt->fetch();
$receiptAllocations = [];
$availableCredit = 0.0;
$receiptTotals = ['fees' => 0.0, 'previous_paid' => 0.0, 'paid_to_date' => 0.0, 'outstanding' => 0.0];
if ($receipt) {
    $allocationStmt = db()->prepare(
        'SELECT allocation.amount, allocation.allocation_type, invoice.invoice_number,
                invoice.academic_year, invoice.period_name
         FROM school_payment_allocations allocation
         JOIN school_invoices invoice
           ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
          AND invoice.student_id = allocation.student_id
         WHERE allocation.payment_id = ? AND allocation.school_id = ?
           AND allocation.student_id = ? AND allocation.status = "active"
         ORDER BY allocation.id'
    );
    $allocationStmt->execute([$paymentId, $schoolId, (int) $receipt['student_id']]);
    $receiptAllocations = $allocationStmt->fetchAll();
    $receiptTotalStmt = db()->prepare(
        'SELECT COALESCE(SUM(invoice.amount), 0) AS fees,
                COALESCE(SUM(invoice.adjustments_total), 0) AS adjustments,
                COALESCE(SUM(invoice.balance), 0) AS outstanding
         FROM school_invoices invoice
         WHERE invoice.school_id = ? AND invoice.student_id = ?
           AND invoice.academic_year = ?'
           . ((string) $receipt['payment_coverage'] === 'academic_year' ? '' : ' AND invoice.period_name = ?')
    );
    $receiptTotalParams = [$schoolId, (int) $receipt['student_id'], (string) $receipt['academic_year']];
    if ((string) $receipt['payment_coverage'] !== 'academic_year') {
        $receiptTotalParams[] = (string) $receipt['period_name'];
    }
    $receiptTotalStmt->execute($receiptTotalParams);
    $receiptTotal = $receiptTotalStmt->fetch();
    $receiptTotals['fees'] = (float) $receiptTotal['fees'];
    $receiptTotals['outstanding'] = (float) $receiptTotal['outstanding'];
    $receiptTotals['paid_to_date'] = max(
        0,
        $receiptTotals['fees'] - (float) $receiptTotal['adjustments'] - $receiptTotals['outstanding']
    );
    $currentAllocationTotal = array_sum(array_map(
        static fn(array $allocation): float => (float) $allocation['amount'],
        $receiptAllocations
    ));
    $receiptTotals['previous_paid'] = max(0, $receiptTotals['paid_to_date'] - $currentAllocationTotal);
    $creditStmt = db()->prepare(
        'SELECT COALESCE(credit.surplus_amount - COALESCE(used.amount, 0), 0)
         FROM school_fee_credit_sources credit
         LEFT JOIN (
             SELECT payment_id, SUM(amount) AS amount
             FROM school_payment_allocations
             WHERE payment_id = ? AND allocation_type = "automatic_credit" AND status = "active"
             GROUP BY payment_id
         ) used ON used.payment_id = credit.payment_id
         WHERE credit.payment_id = ? AND credit.school_id = ? AND credit.student_id = ?'
    );
    $creditStmt->execute([$paymentId, $paymentId, $schoolId, (int) $receipt['student_id']]);
    $availableCredit = (float) ($creditStmt->fetchColumn() ?: 0);
}
$parentOwnsRecord = $receipt
    && school_has_permission($user, $schoolId, 'fees.view_own')
    && school_parent_has_verified_link($schoolId, (int) $receipt['student_id'], (int) $user['id']);
$studentOwnsRecord = false;
if ($receipt && school_user_role_for_school((int) $user['id'], $schoolId) === 'school_student') {
    $studentLinkStmt = db()->prepare(
        'SELECT 1 FROM school_student_account_links
         WHERE school_id = ? AND student_id = ? AND user_id = ? AND status = "active"
         LIMIT 1'
    );
    $studentLinkStmt->execute([$schoolId, (int) $receipt['student_id'], (int) $user['id']]);
    $studentOwnsRecord = (bool) $studentLinkStmt->fetchColumn();
}
if (!$receipt || (!school_has_permission($user, $schoolId, 'payments.manage') && !$parentOwnsRecord && !$studentOwnsRecord)) {
    http_response_code($receipt ? 403 : 404);
    exit($receipt ? 'You are not allowed to view this receipt.' : 'Receipt not found.');
}

render_header('Payment receipt');
?>
<section class="panel payment-receipt-page">
    <?php if (preg_match('#^school-logos/[a-f0-9]{48}\.(?:jpg|png|webp)$#', (string) $receipt['logo_path']) === 1): ?><img class="school-identity-logo" src="school_logo.php?school_id=<?= (int) $receipt['school_id'] ?>" alt="<?= e((string) $receipt['school_name']) ?> logo"><?php endif; ?>
    <p class="eyebrow"><?= e((string) $receipt['school_name']) ?></p>
    <h1>School payment receipt</h1>
    <p><strong>Receipt:</strong> SPR-<?= e((string) $receipt['id']) ?></p>
    <p><strong>Recorded against:</strong> <?= e((string) $receipt['invoice_number']) ?></p>
    <p><strong>Student:</strong> <?= e((string) $receipt['student_name']) ?><?= $receipt['admission_number'] ? ' · ' . e((string) $receipt['admission_number']) : '' ?><?= $receipt['class_level'] ? ' · ' . e((string) $receipt['class_level']) : '' ?></p>
    <p><strong>Parent/payer:</strong> <?= e((string) ($receipt['payer_name'] ?: $receipt['guardian_name'] ?: 'Not recorded')) ?></p>
    <p><strong>Academic period:</strong> <?= e((string) $receipt['academic_year'] . ' · ' . (string) $receipt['period_name']) ?></p>
    <p><strong>Total applicable fees:</strong> GHS <?= e(number_format($receiptTotals['fees'], 2)) ?></p>
    <p><strong>Previously paid:</strong> GHS <?= e(number_format($receiptTotals['previous_paid'], 2)) ?></p>
    <p><strong>Amount received:</strong> GHS <?= e(number_format((float) $receipt['amount'], 2)) ?></p>
    <p><strong>Total paid to date:</strong> GHS <?= e(number_format($receiptTotals['paid_to_date'], 2)) ?></p>
    <p><strong>Outstanding balance:</strong> GHS <?= e(number_format($receiptTotals['outstanding'], 2)) ?></p>
    <p><strong>Payment coverage:</strong> <?= e(school_fee_payment_coverage_label((string) $receipt['payment_coverage'])) ?></p>
    <h2>Verified invoice allocations</h2>
    <?php if ($receiptAllocations === []): ?><p class="notice notice-error">No verified allocation is available. Refer this payment to finance for review.</p>
    <?php else: ?><ul><?php foreach ($receiptAllocations as $allocation): ?><li><?= e((string) $allocation['invoice_number']) ?> · <?= e((string) ($allocation['academic_year'] ?: 'Historical')) ?> <?= e((string) ($allocation['period_name'] ?: '')) ?> · GHS <?= e(number_format((float) $allocation['amount'], 2)) ?> (<?= e(str_replace('_', ' ', (string) $allocation['allocation_type'])) ?>)</li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($availableCredit > 0): ?><p><strong>Surplus held as future-term credit:</strong> GHS <?= e(number_format($availableCredit, 2)) ?></p><?php endif; ?>
    <p><strong>Method:</strong> <?= e((string) $receipt['payment_method']) ?></p>
    <p><strong>Reference:</strong> <?= e((string) ($receipt['payment_reference'] ?: '—')) ?></p>
    <p><strong>Received by:</strong> <?= e((string) ($receipt['receiver_name'] ?: 'School finance office')) ?></p>
    <p><strong>Received:</strong> <?= e((string) $receipt['received_at']) ?></p>
    <p><strong>Verified:</strong> <?= e((string) ($receipt['verified_at'] ?: '—')) ?></p>
    <button class="button" type="button" data-print-button>Print receipt</button>
</section>
<?php render_footer(); ?>

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$paymentId) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare(
    "SELECT p.id,p.user_id,p.amount,p.currency,p.payment_method,p.reference,p.paid_at,p.status,
            p.reviewed_at,p.receipt_issued_at,
            u.full_name,mp.membership_number,d.title AS due_title,
            COALESCE(a.amount,0) AS refunded_amount,a.reason AS refund_reason,
            a.created_at AS refunded_at
     FROM payment_records p
     JOIN users u ON u.id=p.user_id
     LEFT JOIN member_profiles mp ON mp.user_id=p.user_id
     LEFT JOIN membership_dues d ON d.id=p.due_id
     LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund'
     WHERE p.id=?"
);
$stmt->execute([(int) $paymentId]);
$receipt = $stmt->fetch();
$canView = $receipt && (
    (int) $receipt['user_id'] === (int) $user['id']
    || user_has_permission($user, 'payments.manage')
    || user_has_permission($user, 'finance.view')
);
if (!$canView) {
    http_response_code(404);
    exit;
}

$isRefunded = (float) $receipt['refunded_amount'] > 0 || $receipt['status'] === 'refunded';
$status = $isRefunded ? 'refunded' : (string) $receipt['status'];
$isConfirmedReceipt = $status === 'confirmed';
$canViewFinancialDetails = user_has_permission($user, 'payments.manage')
    || user_has_permission($user, 'finance.view');
$receiptNumber = 'OSAAK-R-' . str_pad((string) $receipt['id'], 8, '0', STR_PAD_LEFT);
$paymentDate = new DateTimeImmutable((string) $receipt['paid_at'], new DateTimeZone('UTC'));
$receiptIssuedAt = $receipt['receipt_issued_at'] ?: ($receipt['reviewed_at'] ?: $receipt['paid_at']);
$receiptDate = (new DateTimeImmutable((string) $receiptIssuedAt, new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone((string) ($config['timezone'] ?? 'UTC')));
$financialTitle = trim((string) ($config['receipt_financial_title'] ?? 'Financial Controller'));
if ($financialTitle === '') {
    $financialTitle = 'Financial Controller';
}
$pageTitle = $isConfirmedReceipt ? 'Payment receipt' : 'Payment record';
$associationName = association_display_name();

function receipt_status_label(string $status): string
{
    return match ($status) {
        'confirmed' => 'Confirmed',
        'pending' => 'Pending review',
        'rejected' => 'Rejected',
        'refunded' => 'Refunded',
        default => ucfirst($status),
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle) ?> · <?= e($associationName) ?></title>
    <link rel="stylesheet" href="assets/css/receipt.css?v=10">
</head>
<body class="receipt-page">
    <main class="receipt-sheet">
        <header class="receipt-heading">
            <?php association_branding('association-branding-receipt'); ?>
            <p class="receipt-kind"><?= $isConfirmedReceipt ? 'PAYMENT RECEIPT' : 'PAYMENT RECORD' ?></p>
            <h1><?= e($receiptNumber) ?></h1>
        </header>

        <?php if (!$isConfirmedReceipt): ?>
            <p class="receipt-notice receipt-notice-<?= e($status) ?>">
                <?= $status === 'pending'
                    ? 'Pending review — this payment has not been confirmed and this record is not proof of a completed payment.'
                    : ($status === 'rejected'
                        ? 'Rejected — this payment was not confirmed. This record is not proof of a completed payment.'
                        : 'Refunded — this record preserves the original payment details and does not represent funds currently held by the association.') ?>
            </p>
        <?php endif; ?>

        <section class="receipt-section" aria-labelledby="payment-details-heading">
            <h2 id="payment-details-heading">Payment details</h2>
            <dl class="receipt-details">
                <dt>Member</dt>
                <dd><?= e($receipt['full_name']) ?></dd>
                <dt>Membership ID</dt>
                <dd><?= e($receipt['membership_number'] ?: 'Not assigned') ?></dd>
                <dt>Payment for</dt>
                <dd><?= e($receipt['due_title'] ?: 'General association payment') ?></dd>
                <dt>Amount recorded</dt>
                <dd class="receipt-amount"><?= e(strtoupper((string) $receipt['currency'])) ?> <?= number_format((float) $receipt['amount'], 2) ?></dd>
                <dt>Payment method</dt>
                <dd><?= e(ucwords(str_replace('_', ' ', (string) $receipt['payment_method']))) ?></dd>
                <dt>Reference</dt>
                <dd><?= e($receipt['reference'] ?: 'Not provided') ?></dd>
                <dt>Payment date</dt>
                <dd><?= e($paymentDate->format('F j, Y · g:i A')) ?> UTC</dd>
                <dt>Payment status</dt>
                <dd><span class="receipt-status receipt-status-<?= e($status) ?>"><?= e(receipt_status_label($status)) ?></span></dd>
                <?php if ($isRefunded): ?>
                    <dt>Refunded amount</dt>
                    <dd><?= e(strtoupper((string) $receipt['currency'])) ?> <?= number_format((float) $receipt['refunded_amount'], 2) ?></dd>
                    <?php if ($receipt['refunded_at']): ?>
                        <dt>Refund recorded</dt>
                        <dd><?= e((new DateTimeImmutable((string) $receipt['refunded_at'], new DateTimeZone('UTC')))->format('F j, Y · g:i A')) ?> UTC</dd>
                    <?php endif; ?>
                    <?php if ($canViewFinancialDetails && $receipt['refund_reason']): ?>
                        <dt>Refund reason</dt>
                        <dd><?= e($receipt['refund_reason']) ?></dd>
                    <?php endif; ?>
                <?php endif; ?>
            </dl>
        </section>

        <?php if ($isConfirmedReceipt): ?>
            <section class="receipt-stamp" aria-label="Official payment received and processed stamp">
                <div class="receipt-stamp-heading">
                    <?php if (association_logo_is_configured()): ?><img class="receipt-stamp-logo" src="branding_logo.php" alt="OSAAK official logo"><?php endif; ?>
                    <div class="receipt-stamp-brand">
                        <strong>OSAAK</strong>
                        <span>OLD STUDENTS ASSOCIATION OF APPIAH KUBI</span>
                    </div>
                </div>
                <p class="receipt-stamp-status">RECEIVED &amp; PROCESSED</p>
                <p class="receipt-stamp-date"><span>Date:</span> <time datetime="<?= e($receiptDate->format('Y-m-d')) ?>"><?= e($receiptDate->format('j F Y')) ?></time></p>
                <div class="receipt-stamp-signature">
                    <span class="receipt-stamp-signature-line" aria-hidden="true"></span>
                    <span><?= e($financialTitle) ?></span>
                </div>
            </section>
        <?php endif; ?>

        <p class="receipt-footnote">
            <?= $isConfirmedReceipt
                ? 'This receipt reflects the confirmed payment ledger. Please keep it with your records.'
                : 'This document is an informational copy of the payment ledger; the status above determines whether the payment was confirmed.' ?>
        </p>
        <div class="receipt-actions">
            <button class="receipt-button" type="button" data-print-receipt>
                <?= $isConfirmedReceipt ? 'Print receipt / Save as PDF' : 'Print payment record / Save as PDF' ?>
            </button>
            <a class="receipt-back" href="school_services.php?module=finance">Back to payment records</a>
        </div>
    </main>
    <script src="assets/js/receipt.js" defer></script>
</body>
</html>

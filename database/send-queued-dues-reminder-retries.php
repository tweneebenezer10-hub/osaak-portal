<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
set_time_limit(0);

$log = static function (string $message): void {
    $directory = APP_ROOT . '/storage/logs';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private monthly dues log directory could not be created.');
    }
    if (file_put_contents(
        $directory . '/monthly-dues-reminders.log',
        '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    ) === false) {
        throw new RuntimeException('The monthly dues retry result could not be written to private storage.');
    }
};

$settings = [];
foreach (db()->query(
    "SELECT setting_key,setting_value
     FROM association_settings
     WHERE setting_key IN ('association_name','association_email','association_phone','payment_instructions')"
)->fetchAll() as $setting) {
    $settings[$setting['setting_key']] = trim((string) $setting['setting_value']);
}
$associationName = $settings['association_name'] ?? '';
if ($associationName === '') {
    $associationName = (string) (($config['mail']['from_name'] ?? '') ?: 'Old Students Association');
}
$contact = ($settings['association_email'] ?? '') !== ''
    ? $settings['association_email']
    : ((($settings['association_phone'] ?? '') !== '') ? $settings['association_phone'] : 'the authorised association representative');
$paymentInstructions = $settings['payment_instructions'] ?? '';
$dashboardUrl = rtrim((string) ($config['app_url'] ?? ''), '/') . '/dashboard.php';
$queuedIds = db()->query(
    "SELECT due_id,user_id
     FROM membership_due_notifications
     WHERE status='retry_queued' AND attempts < 5
     ORDER BY last_attempt_at,due_id,user_id
     LIMIT 200"
)->fetchAll();
$sentCount = 0;
$failedCount = 0;

foreach ($queuedIds as $queued) {
    $dueId = (int) $queued['due_id'];
    $userId = (int) $queued['user_id'];
    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            "SELECT n.attempts,u.full_name,u.email,u.status AS account_status,u.email_verified_at,
                    mp.membership_number,mp.membership_status,mp.graduation_year,
                    d.title,d.amount,d.currency,d.due_date,d.billing_period,d.status AS due_status,
                    d.target_year,d.target_category,mp.membership_category
             FROM membership_due_notifications n
             JOIN membership_dues d ON d.id=n.due_id
             JOIN users u ON u.id=n.user_id
             JOIN member_profiles mp ON mp.user_id=u.id
             WHERE n.due_id=? AND n.user_id=? AND n.status='retry_queued'
             FOR UPDATE"
        );
        $stmt->execute([$dueId, $userId]);
        $recipient = $stmt->fetch();
        if (!$recipient || (int) $recipient['attempts'] >= 5) {
            db()->rollBack();
            continue;
        }
        if ($recipient['account_status'] !== 'active'
            || $recipient['email_verified_at'] === null
            || $recipient['due_status'] !== 'open'
            || $recipient['membership_status'] !== 'active'
            || $recipient['target_year'] !== null
                && (int) $recipient['target_year'] !== (int) $recipient['graduation_year']
            || $recipient['target_category'] !== null
                && $recipient['target_category'] !== $recipient['membership_category']) {
            $stmt = db()->prepare(
                "UPDATE membership_due_notifications
                 SET status='failed',last_error='Queued retry cancelled because the account or dues cycle is no longer eligible.'
                 WHERE due_id=? AND user_id=? AND status='retry_queued'"
            );
            $stmt->execute([$dueId, $userId]);
            db()->commit();
            continue;
        }
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(p.amount-COALESCE(a.amount,0)),0)
             FROM payment_records p
             LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund'
             WHERE p.user_id=? AND p.due_id=? AND p.status='confirmed'"
        );
        $stmt->execute([$userId, $dueId]);
        $paid = (float) $stmt->fetchColumn();
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END),0),
                    MAX(adjustment_type='exemption')
             FROM membership_due_adjustments WHERE due_id=? AND user_id=?"
        );
        $stmt->execute([$dueId, $userId]);
        [$credit, $exempt] = $stmt->fetch(PDO::FETCH_NUM);
        $assessed = $exempt ? 0.0 : max(0.0, (float) $recipient['amount'] - (float) $credit);
        $balance = max(0.0, $assessed - $paid);
        if ($balance <= 0) {
            $stmt = db()->prepare(
                "UPDATE membership_due_notifications
                 SET status='failed',last_error='Queued retry cancelled because the outstanding balance is already settled; no email was sent.'
                 WHERE due_id=? AND user_id=? AND status='retry_queued'"
            );
            $stmt->execute([$dueId, $userId]);
            db()->commit();
            continue;
        }
        $stmt = db()->prepare(
            "UPDATE membership_due_notifications
             SET status='sending',attempts=attempts+1,last_attempt_at=UTC_TIMESTAMP(),last_error=NULL
             WHERE due_id=? AND user_id=? AND status='retry_queued' AND attempts<5"
        );
        $stmt->execute([$dueId, $userId]);
        if ($stmt->rowCount() !== 1) {
            db()->rollBack();
            continue;
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }

    $period = $recipient['billing_period'] !== null
        ? gmdate('F Y', strtotime((string) $recipient['billing_period']))
        : (string) $recipient['title'];
    $amountDue = number_format($assessed, 2);
    $amountPaid = number_format($paid, 2);
    $balanceText = number_format($balance, 2);
    $currency = (string) $recipient['currency'];
    $membershipNumber = trim((string) ($recipient['membership_number'] ?? ''));
    $membershipLine = $membershipNumber !== '' ? '<p>Membership ID: ' . e($membershipNumber) . '</p>' : '';
    $instructionsHtml = $paymentInstructions !== ''
        ? '<p>Payment instructions: ' . nl2br(e($paymentInstructions)) . '</p>'
        : '<p>Please sign in to your member dashboard and follow the association’s approved payment instructions.</p>';
    $html = '<p>Dear ' . e($recipient['full_name']) . ',</p>'
        . '<p>This is a friendly reminder from ' . e($associationName) . ' that our records show an outstanding dues balance.</p>'
        . $membershipLine
        . '<p>Dues period: ' . e($period) . '<br>Total dues assessed: ' . e($currency) . ' ' . e($amountDue)
        . '<br>Amount paid: ' . e($currency) . ' ' . e($amountPaid)
        . '<br>Outstanding balance: ' . e($currency) . ' ' . e($balanceText)
        . '<br>Payment deadline: ' . e((string) ($recipient['due_date'] ?? 'Not specified')) . '</p>'
        . $instructionsHtml
        . '<p><a href="' . e($dashboardUrl) . '">Sign in to view your dues and payment history</a>.</p>'
        . '<p>If you have already paid or believe this balance is incorrect, please contact ' . e($contact) . '.</p>'
        . '<p>Thank you for your continued support.<br>' . e($associationName) . '</p>';
    $text = "Dear {$recipient['full_name']},\n\n"
        . "This is a friendly reminder from {$associationName} that our records show an outstanding dues balance.\n\n"
        . ($membershipNumber !== '' ? "Membership ID: {$membershipNumber}\n" : '')
        . "Dues period: {$period}\nTotal dues assessed: {$currency} {$amountDue}\n"
        . "Amount paid: {$currency} {$amountPaid}\nOutstanding balance: {$currency} {$balanceText}\n"
        . 'Payment deadline: ' . (string) ($recipient['due_date'] ?? 'Not specified') . "\n\n"
        . ($paymentInstructions !== '' ? "Payment instructions: {$paymentInstructions}\n\n" : '')
        . "Dashboard: {$dashboardUrl}\n\nIf you have already paid or believe this balance is incorrect, contact {$contact}.\n\n"
        . "Thank you for your continued support.\n{$associationName}";
    try {
        $delivered = send_mail(
            (string) $recipient['email'],
            'Dues Reminder - ' . $associationName,
            $html,
            $text
        );
        $status = $delivered ? 'sent' : 'failed';
        $error = $delivered ? null : 'SMTP handoff failed; inspect the protected PHP server log.';
    } catch (Throwable $exception) {
        $status = 'failed';
        $error = 'SMTP retry raised an exception; inspect the protected PHP server log.';
        $log('Queued dues reminder failed for due_id=' . $dueId . ', user_id=' . $userId . ': ' . $exception->getMessage());
    }
    $stmt = db()->prepare(
        "UPDATE membership_due_notifications
         SET status=?,sent_at=CASE WHEN ?='sent' THEN UTC_TIMESTAMP() ELSE NULL END,last_error=?
         WHERE due_id=? AND user_id=? AND status='sending'"
    );
    $stmt->execute([$status, $status, $error, $dueId, $userId]);
    if ($delivered ?? false) {
        $sentCount++;
    } else {
        $failedCount++;
    }
}

$message = sprintf('Processed %d queued retry/retries: %d accepted by SMTP, %d failed.', count($queuedIds), $sentCount, $failedCount);
fwrite(STDOUT, $message . PHP_EOL);
$log($message);

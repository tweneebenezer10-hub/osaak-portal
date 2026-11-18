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
    $entry = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    if (file_put_contents($directory . '/monthly-dues-reminders.log', $entry, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('The monthly dues job result could not be written to private storage.');
    }
};

$runStmt = db()->prepare('INSERT INTO dues_reminder_runs (billing_period) VALUES (?)');
$settings = [];
foreach (db()->query(
    "SELECT setting_key, setting_value, updated_by
     FROM association_settings
     WHERE setting_key IN (
        'monthly_dues_amount','monthly_dues_currency','monthly_dues_email_day',
        'association_name','association_email','association_phone','association_address',
        'payment_instructions'
     )"
)->fetchAll() as $setting) {
    $settings[$setting['setting_key']] = $setting;
}

$amount = trim((string) ($settings['monthly_dues_amount']['setting_value'] ?? ''));
$currency = strtoupper(trim((string) ($settings['monthly_dues_currency']['setting_value'] ?? '')));
$emailDay = filter_var($settings['monthly_dues_email_day']['setting_value'] ?? null, FILTER_VALIDATE_INT);
$creatorId = (int) ($settings['monthly_dues_amount']['updated_by'] ?? 0);
if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0
    || !preg_match('/^[A-Z]{3}$/', $currency) || !$emailDay || $emailDay < 1 || $emailDay > 7 || !$creatorId) {
    $message = 'Monthly dues are not configured. Save a valid amount, currency, and reminder day from 1 to 7 in Association management > Dues and payments.';
    fwrite(STDERR, $message . PHP_EOL);
    $log($message);
    exit(1);
}

$now = new DateTimeImmutable('now', new DateTimeZone((string) ($config['timezone'] ?? 'UTC')));
if ((int) $now->format('j') > 7) {
    $message = 'The monthly dues reminder campaign runs during the first seven calendar days only.';
    fwrite(STDOUT, $message . PHP_EOL);
    $log($message);
    exit(0);
}
if ((int) $now->format('j') < $emailDay) {
    $message = "No monthly dues campaign is scheduled today (configured day: {$emailDay}).";
    fwrite(STDOUT, $message . PHP_EOL);
    $log($message);
    exit(0);
}

$period = $now->format('Y-m-01');
$dueDate = $now->modify('last day of this month')->format('Y-m-d');
$periodLabel = $now->format('F Y');
$associationName = trim((string) ($settings['association_name']['setting_value'] ?? ''));
if ($associationName === '') {
    $associationName = (string) (($config['mail']['from_name'] ?? '') ?: 'Old Students Association');
}
$appUrl = rtrim((string) ($config['app_url'] ?? ''), '/');
$dashboardUrl = $appUrl . '/dashboard.php';
$paymentInstructions = trim((string) ($settings['payment_instructions']['setting_value'] ?? ''));
$contactAddress = trim((string) ($settings['association_email']['setting_value'] ?? ''));
$contactPhone = trim((string) ($settings['association_phone']['setting_value'] ?? ''));
$contact = $contactAddress !== '' ? $contactAddress : ($contactPhone !== '' ? $contactPhone : 'the authorised association representative');

$runStmt->execute([$period]);
$runId = (int) db()->lastInsertId();
$sentNow = 0;
$failedNow = 0;
$eligibleCount = 0;
$dueId = 0;

try {
    $stmt = db()->prepare(
        "INSERT INTO membership_dues
            (title, description, amount, currency, due_date, target_year, billing_period, due_type, created_by)
         VALUES (?, ?, ?, ?, ?, NULL, ?, 'monthly', ?)
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
    );
    $stmt->execute([
        'Monthly membership dues - ' . $periodLabel,
        'Monthly membership dues for ' . $periodLabel,
        $amount,
        $currency,
        $dueDate,
        $period,
        $creatorId,
    ]);
    $dueId = (int) db()->lastInsertId();
    if (!$dueId) {
        $stmt = db()->prepare("SELECT id FROM membership_dues WHERE due_type='monthly' AND billing_period=?");
        $stmt->execute([$period]);
        $dueId = (int) $stmt->fetchColumn();
    }
    if (!$dueId) {
        throw new RuntimeException('The monthly dues cycle could not be created or retrieved.');
    }

    $stmt = db()->prepare("SELECT status FROM membership_dues WHERE id=?");
    $stmt->execute([$dueId]);
    if ($stmt->fetchColumn() !== 'open') {
        $message = "Monthly cycle {$period} is closed; no reminders were sent.";
        fwrite(STDOUT, $message . PHP_EOL);
        $log($message);
        $finishRun = db()->prepare(
            'UPDATE dues_reminder_runs SET finished_at=UTC_TIMESTAMP(), error_message=? WHERE id=?'
        );
        $finishRun->execute(['Monthly cycle is closed; no messages sent.', $runId]);
        exit(0);
    }

    $stmt = db()->prepare(
        "INSERT IGNORE INTO membership_due_notifications (due_id, user_id, status)
         SELECT ?, u.id, 'pending'
         FROM users u
         JOIN member_profiles mp ON mp.user_id=u.id
         WHERE u.status='active' AND u.email_verified_at IS NOT NULL
           AND mp.membership_status='active'
           AND EXISTS (
               SELECT 1 FROM membership_dues d
               WHERE d.id=? AND (d.target_year IS NULL OR d.target_year=mp.graduation_year)
                 AND (d.target_category IS NULL OR d.target_category=mp.membership_category)
           )"
    );
    $stmt->execute([$dueId, $dueId]);
    $stmt = db()->prepare(
        "UPDATE membership_due_notifications
         SET status='failed', last_error='Previous reminder process did not finish; safe retry required.'
         WHERE due_id=? AND status='sending' AND last_attempt_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR"
    );
    $stmt->execute([$dueId]);

    $recipientSql =
        "SELECT n.user_id,u.full_name,u.email,mp.membership_number,
                CASE WHEN COALESCE(adjustments.is_exempt,0)=1 THEN 0
                     ELSE GREATEST(d.amount-COALESCE(adjustments.credit_amount,0),0) END AS assessed_amount,
                d.currency,d.due_date,
                COALESCE(paid.paid_amount,0) AS paid_amount,
                GREATEST(CASE WHEN COALESCE(adjustments.is_exempt,0)=1 THEN 0
                              ELSE d.amount-COALESCE(adjustments.credit_amount,0) END
                         -COALESCE(paid.paid_amount,0),0) AS balance
         FROM membership_due_notifications n
         JOIN membership_dues d ON d.id=n.due_id
         JOIN users u ON u.id=n.user_id
         JOIN member_profiles mp ON mp.user_id=u.id
         LEFT JOIN (
             SELECT due_id,user_id,SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END) AS credit_amount,
                    MAX(adjustment_type='exemption') AS is_exempt
             FROM membership_due_adjustments GROUP BY due_id,user_id
         ) adjustments ON adjustments.due_id=d.id AND adjustments.user_id=n.user_id
         LEFT JOIN (
             SELECT p.user_id,p.due_id,
                    SUM(p.amount-COALESCE(refunds.amount,0)) AS paid_amount
             FROM payment_records p
             LEFT JOIN payment_adjustments refunds
               ON refunds.payment_id=p.id AND refunds.adjustment_type='refund'
             WHERE p.status='confirmed'
             GROUP BY p.user_id,p.due_id
         ) paid ON paid.user_id=n.user_id AND paid.due_id=n.due_id
         WHERE n.due_id=? AND n.status IN ('pending','failed') AND n.attempts < 5
           AND d.status='open' AND (d.target_year IS NULL OR d.target_year=mp.graduation_year)
           AND (d.target_category IS NULL OR d.target_category=mp.membership_category)
           AND u.status='active' AND u.email_verified_at IS NOT NULL
           AND mp.membership_status='active'
           AND GREATEST(d.amount-COALESCE(paid.paid_amount,0),0) > 0
         ORDER BY n.user_id";
    $stmt = db()->prepare($recipientSql);
    $stmt->execute([$dueId]);
    $recipients = $stmt->fetchAll();
    $eligibleCount = count($recipients);

    foreach ($recipients as $recipient) {
        $claim = db()->prepare(
            "UPDATE membership_due_notifications
             SET status='sending', attempts=attempts+1, last_attempt_at=UTC_TIMESTAMP(),
                 last_error=NULL
             WHERE due_id=? AND user_id=? AND status IN ('pending','failed') AND attempts < 5"
        );
        $claim->execute([$dueId, (int) $recipient['user_id']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        $amountDue = number_format((float) $recipient['assessed_amount'], 2);
        $amountPaid = number_format((float) $recipient['paid_amount'], 2);
        $balance = number_format((float) $recipient['balance'], 2);
        $membershipNumber = trim((string) ($recipient['membership_number'] ?? ''));
        $membershipLine = $membershipNumber !== '' ? '<p>Membership ID: ' . e($membershipNumber) . '</p>' : '';
        $instructionsHtml = $paymentInstructions !== ''
            ? '<p>Payment instructions: ' . nl2br(e($paymentInstructions)) . '</p>'
            : '<p>Please sign in to your member dashboard and follow the association’s approved payment instructions.</p>';
        $html = '<p>Dear ' . e($recipient['full_name']) . ',</p>'
            . '<p>This is a friendly reminder from ' . e($associationName) . ' that our records show an outstanding dues balance.</p>'
            . $membershipLine
            . '<p>Dues period: ' . e($periodLabel) . '<br>'
            . 'Total dues assessed: ' . e($currency) . ' ' . e($amountDue) . '<br>'
            . 'Amount paid: ' . e($currency) . ' ' . e($amountPaid) . '<br>'
            . 'Outstanding balance: ' . e($currency) . ' ' . e($balance) . '<br>'
            . 'Payment deadline: ' . e($dueDate) . '</p>'
            . $instructionsHtml
            . '<p><a href="' . e($dashboardUrl) . '">Sign in to view your dues and payment history</a>.</p>'
            . '<p>If you have already paid or believe this balance is incorrect, please contact ' . e($contact) . ' so your records can be checked.</p>'
            . '<p>Thank you for your continued support.<br>' . e($associationName) . '</p>';
        $text = "Dear {$recipient['full_name']},\n\n"
            . "This is a friendly reminder from {$associationName} that our records show an outstanding dues balance.\n\n"
            . ($membershipNumber !== '' ? "Membership ID: {$membershipNumber}\n" : '')
            . "Dues period: {$periodLabel}\n"
            . "Total dues assessed: {$currency} {$amountDue}\n"
            . "Amount paid: {$currency} {$amountPaid}\n"
            . "Outstanding balance: {$currency} {$balance}\n"
            . "Payment deadline: {$dueDate}\n\n"
            . ($paymentInstructions !== '' ? "Payment instructions: {$paymentInstructions}\n\n" : "Sign in to your member dashboard and follow the association’s approved payment instructions.\n\n")
            . "Dashboard: {$dashboardUrl}\n\n"
            . "If you have already paid or believe this balance is incorrect, contact {$contact} so your records can be checked.\n\n"
            . "Thank you for your continued support.\n{$associationName}";
        $deliveredToSmtp = send_mail((string) $recipient['email'], 'Monthly Dues Reminder - ' . $associationName, $html, $text);
        $status = $deliveredToSmtp ? 'sent' : 'failed';
        $failureMessage = $deliveredToSmtp ? null : 'SMTP handoff failed; inspect the protected PHP server log.';
        $finish = db()->prepare(
            'UPDATE membership_due_notifications
             SET status=?, sent_at=CASE WHEN ?=\'sent\' THEN UTC_TIMESTAMP() ELSE NULL END,
                 last_error=?
             WHERE due_id=? AND user_id=? AND status=\'sending\''
        );
        $finish->execute([$status, $status, $failureMessage, $dueId, (int) $recipient['user_id']]);
        if ($deliveredToSmtp) {
            $sentNow++;
        } else {
            $failedNow++;
        }
    }

    $finishRun = db()->prepare(
        'UPDATE dues_reminder_runs
         SET finished_at=UTC_TIMESTAMP(),eligible_count=?,sent_count=?,failed_count=?
         WHERE id=?'
    );
    $finishRun->execute([$eligibleCount, $sentNow, $failedNow, $runId]);
} catch (Throwable $exception) {
    $finishRun = db()->prepare(
        'UPDATE dues_reminder_runs SET finished_at=UTC_TIMESTAMP(),error_message=? WHERE id=?'
    );
    $finishRun->execute([substr($exception->getMessage(), 0, 500), $runId]);
    $log('Monthly dues reminder run failed: ' . $exception->getMessage());
    throw $exception;
}

$countRemaining = db()->prepare(
    "SELECT COUNT(*) FROM membership_due_notifications
     WHERE due_id=? AND (status='sending' OR (status IN ('pending','failed') AND attempts < 5))"
);
$countRemaining->execute([$dueId]);
$remaining = (int) $countRemaining->fetchColumn();
$countSent = db()->prepare("SELECT COUNT(*) FROM membership_due_notifications WHERE due_id=? AND status='sent'");
$countSent->execute([$dueId]);
$sentTotal = (int) $countSent->fetchColumn();
$countExhausted = db()->prepare(
    "SELECT COUNT(*) FROM membership_due_notifications WHERE due_id=? AND status='failed' AND attempts >= 5"
);
$countExhausted->execute([$dueId]);
$exhausted = (int) $countExhausted->fetchColumn();

$message = sprintf(
    'Monthly cycle %s (due %s, configured timezone %s): eligible with a balance %d; accepted by SMTP this run %d; failed this run %d; already/successfully sent %d; retryable or in progress %d; exhausted after five attempts %d.',
    $period,
    $dueDate,
    $now->getTimezone()->getName(),
    $eligibleCount,
    $sentNow,
    $failedNow,
    $sentTotal,
    $remaining,
    $exhausted
);
fwrite(STDOUT, $message . PHP_EOL);
$log($message);
if ($failedNow > 0 || $remaining > 0 || $exhausted > 0) {
    exit(1);
}

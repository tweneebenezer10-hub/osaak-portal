<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_fees.php';
require_once APP_ROOT . '/app/school_payment_reconciliation.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'payments.manage');

$results = [];
$uploadError = null;
$statusCounts = [];
$labels = [
    'matched' => 'Matched to verified payment',
    'pending_verification' => 'Payment is pending verification',
    'amount_discrepancy' => 'Amount discrepancy',
    'duplicate_statement_transaction' => 'Duplicate reference in statement',
    'duplicate_recorded_reference' => 'Duplicate reference in school records',
    'possible_reference_mismatch' => 'Possible incorrect reference',
    'ambiguous_reference_mismatch' => 'Reference mismatch needs review',
    'candidate_search_limited' => 'Too many candidates; review manually',
    'unmatched' => 'No matching school payment',
    'rejected_payment' => 'Payment was rejected',
    'invalid_statement_row' => 'Invalid statement row',
    'invalid_recorded_payment' => 'Recorded payment needs review',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $file = $_FILES['statement'] ?? null;
    if (!is_array($file)
        || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])
        || (int) $file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Choose a CSV statement to reconcile. The upload must complete successfully.';
    } elseif (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv'
        || !is_uploaded_file((string) $file['tmp_name'])) {
        $uploadError = 'Upload a valid CSV file no larger than 1 MB.';
    } elseif ((int) $file['size'] < 1 || (int) $file['size'] > 1048576
        || filesize((string) $file['tmp_name']) === false
        || filesize((string) $file['tmp_name']) > 1048576) {
        $uploadError = 'The uploaded CSV is empty or exceeds the 1 MB size limit.';
    } else {
        $stream = fopen((string) $file['tmp_name'], 'rb');
        if ($stream === false) {
            $uploadError = 'The uploaded statement could not be read.';
        } else {
            try {
                $statementRows = school_payment_statement_parse($stream);
            } catch (InvalidArgumentException $exception) {
                $uploadError = $exception->getMessage();
            } finally {
                fclose($stream);
            }

            if ($uploadError === null) {
                $references = [];
                foreach ($statementRows as $row) {
                    if ($row['issue'] === null && empty($row['duplicate_statement_reference'])) {
                        $references[$row['normalized_reference']] = $row['reference'];
                    }
                }

                $paymentsByReference = [];
                if ($references !== []) {
                    $placeholders = implode(',', array_fill(0, count($references), '?'));
                    $paymentStmt = db()->prepare(
                        'SELECT id, payment_reference, amount, status, received_at
                         FROM school_payments
                         WHERE school_id = ? AND payment_reference IN (' . $placeholders . ')
                         ORDER BY id'
                    );
                    $paymentStmt->execute(array_merge([$schoolId], array_values($references)));
                    foreach ($paymentStmt->fetchAll() as $payment) {
                        $key = strtoupper(trim((string) $payment['payment_reference']));
                        $paymentsByReference[$key][] = $payment;
                    }
                }

                $referenceMismatchCandidates = [];
                $searchRows = [];
                foreach ($statementRows as $row) {
                    if ($row['issue'] === null
                        && empty($row['duplicate_statement_reference'])
                        && !isset($paymentsByReference[$row['normalized_reference']])
                        && $row['date'] !== null) {
                        $searchRows[] = $row;
                    }
                }

                foreach (array_chunk($searchRows, 100) as $chunk) {
                    $conditions = [];
                    $parameters = [$schoolId];
                    foreach ($chunk as $row) {
                        $date = new DateTimeImmutable((string) $row['date']);
                        $conditions[] = '(payment.amount = ? AND payment.received_at >= ? AND payment.received_at < ?)';
                        $parameters[] = number_format((int) $row['amount_cents'] / 100, 2, '.', '');
                        $parameters[] = $date->modify('-1 day')->format('Y-m-d') . ' 00:00:00';
                        $parameters[] = $date->modify('+2 days')->format('Y-m-d') . ' 00:00:00';
                    }
                    $candidateStmt = db()->prepare(
                        'SELECT payment.id, payment.payment_reference, payment.amount, payment.status, payment.received_at
                         FROM school_payments payment
                         WHERE payment.school_id = ? AND payment.status <> "rejected"
                           AND payment.payment_reference IS NOT NULL AND payment.payment_reference <> ""
                           AND (' . implode(' OR ', $conditions) . ')
                         ORDER BY payment.id LIMIT 5001'
                    );
                    $candidateStmt->execute($parameters);
                    $candidatePayments = $candidateStmt->fetchAll();
                    if (count($candidatePayments) > 5000) {
                        foreach ($chunk as $row) {
                            $referenceMismatchCandidates[(int) $row['row_number']] = [['search_limited' => true]];
                        }
                        continue;
                    }

                    foreach ($chunk as $row) {
                        $date = (string) $row['date'];
                        $lowerDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
                        $upperDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
                        foreach ($candidatePayments as $candidate) {
                            if (school_fee_amount_cents($candidate['amount']) !== $row['amount_cents']
                                || strtoupper(trim((string) $candidate['payment_reference'])) === $row['normalized_reference']) {
                                continue;
                            }
                            $candidateDate = substr((string) $candidate['received_at'], 0, 10);
                            if ($candidateDate >= $lowerDate && $candidateDate <= $upperDate) {
                                $referenceMismatchCandidates[(int) $row['row_number']][] = $candidate;
                            }
                        }
                    }
                }

                $results = school_payment_reconciliation_results(
                    $statementRows,
                    $paymentsByReference,
                    $referenceMismatchCandidates
                );
                foreach ($results as $result) {
                    $statusCounts[$result['status']] = ($statusCounts[$result['status']] ?? 0) + 1;
                }
                audit(
                    (int) $user['id'],
                    'school_payment_statement_reconciled',
                    'school_id=' . $schoolId . '; rows=' . count($results)
                        . '; matched=' . ($statusCounts['matched'] ?? 0)
                        . '; pending=' . ($statusCounts['pending_verification'] ?? 0)
                        . '; exceptions=' . (count($results) - ($statusCounts['matched'] ?? 0))
                );
            }
        }
    }
}

render_header('Payment reconciliation');
page_title('FINANCE', 'Payment reconciliation', 'Compare a bank or provider statement with this school’s recorded fee payments.');
?>
<section class="panel">
    <p class="muted">Upload a CSV with <code>reference</code> and <code>amount</code> columns; an optional <code>date</code> column must use YYYY-MM-DD. Maximum 1 MB and 500 transactions. The statement is processed for this request only and is not stored. Reconciliation is a review aid: it never verifies payments, changes invoice balances, or posts transactions.</p>
    <p class="muted">Example header: <code>reference,amount,date</code></p>
    <?php if ($uploadError !== null): ?><div class="notice notice-error" role="alert"><?= e($uploadError) ?></div><?php endif; ?>
    <form method="post" action="payment_reconciliation.php" enctype="multipart/form-data" class="form-stack">
        <?= csrf_field() ?>
        <label for="statement">Bank or provider CSV statement</label>
        <input id="statement" name="statement" type="file" accept=".csv,text/csv" required>
        <button class="button button-primary" type="submit">Reconcile statement</button>
    </form>
</section>
<?php if ($results !== []): ?>
<section class="panel panel-top-gap">
    <h2>Reconciliation summary</h2>
    <div class="form-grid two-columns">
        <?php foreach ($labels as $status => $label): ?>
            <?php if (isset($statusCounts[$status])): ?><p><strong><?= e($label) ?>:</strong> <?= (int) $statusCounts[$status] ?></p><?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<section class="panel panel-top-gap">
    <h2>Statement transactions</h2>
    <div class="table-scroll"><table>
        <thead><tr><th>CSV row</th><th>Statement date</th><th>Reference</th><th>Statement amount</th><th>Result</th><th>School payment</th><th>Recorded amount</th><th>Recorded status/date</th></tr></thead>
        <tbody>
        <?php foreach ($results as $result): $payment = $result['payment']; ?>
            <tr>
                <td><?= (int) $result['row_number'] ?></td>
                <td><?= e((string) ($result['date'] ?? '—')) ?></td>
                <td><?= e($result['reference']) ?></td>
                <td><?= $result['amount_cents'] === null ? '—' : 'GHS ' . e(number_format((int) $result['amount_cents'] / 100, 2)) ?></td>
                <td><?= e($labels[$result['status']] ?? 'Needs review') ?><?php if ($result['issue'] !== null): ?><br><small><?= e((string) $result['issue']) ?></small><?php endif; ?></td>
                <td><?= $payment ? '#' . (int) $payment['id'] : '—' ?></td>
                <td><?= $payment ? 'GHS ' . e(number_format((float) $payment['amount'], 2)) : '—' ?></td>
                <td><?= $payment ? e((string) $payment['status'] . ' · ' . (string) $payment['received_at']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted">Possible incorrect references are suggestions based on an exact amount and a date within one day; a finance administrator must confirm them. A verified payment remains unchanged until separately reviewed through the normal payment workflow.</p>
</section>
<?php endif; ?>
<p class="spaced-top"><a href="invoices.php">Return to invoices</a> · <a href="fee_management.php?section=payment-reports">Return to fee reports</a></p>
<?php render_footer(); ?>

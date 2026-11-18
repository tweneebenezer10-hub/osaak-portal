<?php
declare(strict_types=1);

function school_payment_statement_parse($stream, int $maximumRows = 500): array
{
    if (!is_resource($stream) || $maximumRows < 1) {
        throw new InvalidArgumentException('A readable statement and positive row limit are required.');
    }

    $header = fgetcsv($stream, 0, ',', '"', '');
    if (!is_array($header)) {
        throw new InvalidArgumentException('The CSV statement is empty.');
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
    }

    $columns = [];
    foreach ($header as $index => $column) {
        $name = strtolower(trim((string) $column));
        if ($name !== '') {
            if (isset($columns[$name])) {
                throw new InvalidArgumentException('The CSV statement contains a duplicate column name.');
            }
            $columns[$name] = (int) $index;
        }
    }
    if (!isset($columns['reference'], $columns['amount'])) {
        throw new InvalidArgumentException('The CSV header must include reference and amount columns.');
    }

    $rows = [];
    $referenceCounts = [];
    while (($record = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (!is_array($record) || count(array_filter(
            $record,
            static fn($value): bool => trim((string) $value) !== ''
        )) === 0) {
            continue;
        }
        if (count($rows) >= $maximumRows) {
            throw new InvalidArgumentException('The CSV statement exceeds the ' . $maximumRows . '-row limit.');
        }

        $rowNumber = count($rows) + 2;
        $reference = array_key_exists($columns['reference'], $record)
            ? trim((string) $record[$columns['reference']])
            : '';
        $rawAmount = $record[$columns['amount']] ?? null;
        $amountCents = school_fee_amount_cents($rawAmount);
        $date = null;
        $issue = null;

        if ($reference === '' || strlen($reference) > 80 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            $issue = 'Enter a reference of 1–80 characters without control characters.';
        } elseif ($amountCents === null || $amountCents <= 0) {
            $issue = 'Enter a positive amount with no more than two decimal places.';
        } elseif (isset($columns['date'])) {
            $rawDate = trim((string) ($record[$columns['date']] ?? ''));
            if ($rawDate !== '') {
                $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $rawDate) {
                    $issue = 'Dates must use the YYYY-MM-DD format.';
                } else {
                    $date = $rawDate;
                }
            }
        }

        if ($issue === null) {
            $normalizedReference = strtoupper($reference);
            $referenceCounts[$normalizedReference] = ($referenceCounts[$normalizedReference] ?? 0) + 1;
        }

        $rows[] = [
            'row_number' => $rowNumber,
            'reference' => $reference,
            'normalized_reference' => strtoupper($reference),
            'amount_cents' => $amountCents,
            'date' => $date,
            'issue' => $issue,
        ];
    }

    foreach ($rows as &$row) {
        $row['duplicate_statement_reference'] = $row['issue'] === null
            && $referenceCounts[$row['normalized_reference']] > 1;
    }
    unset($row);

    if ($rows === []) {
        throw new InvalidArgumentException('The CSV statement has no transaction rows.');
    }

    return $rows;
}

function school_payment_reconciliation_results(array $statementRows, array $paymentsByReference, array $referenceMismatchCandidates = []): array
{
    $results = [];
    foreach ($statementRows as $row) {
        $rowNumber = (int) $row['row_number'];
        $payment = null;
        $status = 'unmatched';
        $issue = $row['issue'] ?? null;

        if ($issue !== null) {
            $status = 'invalid_statement_row';
        } elseif (!empty($row['duplicate_statement_reference'])) {
            $status = 'duplicate_statement_transaction';
        } else {
            $matches = $paymentsByReference[$row['normalized_reference']] ?? [];
            if (count($matches) > 1) {
                $status = 'duplicate_recorded_reference';
            } elseif (count($matches) === 1) {
                $payment = $matches[0];
                $recordedAmountCents = school_fee_amount_cents($payment['amount'] ?? null);
                if ($recordedAmountCents === null || $recordedAmountCents !== $row['amount_cents']) {
                    $status = 'amount_discrepancy';
                } elseif ((string) ($payment['status'] ?? '') === 'pending') {
                    $status = 'pending_verification';
                } elseif ((string) ($payment['status'] ?? '') === 'verified') {
                    $status = 'matched';
                } elseif ((string) ($payment['status'] ?? '') === 'rejected') {
                    $status = 'rejected_payment';
                } else {
                    $status = 'invalid_recorded_payment';
                }
            } else {
                $nearbyPayments = $referenceMismatchCandidates[$rowNumber] ?? [];
                if (!empty($nearbyPayments[0]['search_limited'])) {
                    $status = 'candidate_search_limited';
                } else {
                    $status = count($nearbyPayments) === 1
                        ? 'possible_reference_mismatch'
                        : (count($nearbyPayments) > 1 ? 'ambiguous_reference_mismatch' : 'unmatched');
                }
                if ($status === 'possible_reference_mismatch') {
                    $payment = $nearbyPayments[0];
                }
            }
        }

        $results[] = [
            'row_number' => $rowNumber,
            'reference' => (string) ($row['reference'] ?? ''),
            'date' => $row['date'] ?? null,
            'amount_cents' => $row['amount_cents'] ?? null,
            'status' => $status,
            'issue' => $issue,
            'payment' => $payment,
        ];
    }
    return $results;
}

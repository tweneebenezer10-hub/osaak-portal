<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/functions.php';
require_once dirname(__DIR__) . '/app/school_tenant.php';
require_once dirname(__DIR__) . '/app/student_class_history.php';
require_once dirname(__DIR__) . '/app/school_academic_calendar.php';
require_once dirname(__DIR__) . '/app/school_attendance.php';
require_once dirname(__DIR__) . '/app/school_staff_attendance.php';
require_once dirname(__DIR__) . '/app/school_fees.php';
require_once dirname(__DIR__) . '/app/school_results.php';
require_once dirname(__DIR__) . '/app/school_learning.php';
require_once dirname(__DIR__) . '/app/school_timetable.php';
require_once dirname(__DIR__) . '/app/school_payment_reconciliation.php';
require_once dirname(__DIR__) . '/app/storage_backup.php';
require_once dirname(__DIR__) . '/app/school_ai.php';

$passed = 0;

$test = static function (string $name, callable $case) use (&$passed): void {
    try {
        $case();
        $passed++;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
        exit(1);
    }
};

$same = static function (mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message !== ''
            ? $message
            : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
};

$throws = static function (string $expectedClass, callable $operation): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException('Expected ' . $expectedClass . ', got ' . $exception::class . '.');
    }
    throw new RuntimeException('Expected ' . $expectedClass . ' to be thrown.');
};

$test('email OTP is mandatory and TOTP cannot replace it', static function () use ($same): void {
    $same(false, login_factors_complete(false, false, false));
    $same(false, login_factors_complete(false, true, true));
    $same(true, login_factors_complete(true, false, false));
    $same(false, login_factors_complete(true, true, false));
    $same(true, login_factors_complete(true, true, true));
});

$test('login OTP email template has one brand header and complete instructions', static function () use ($same): void {
    $message = login_otp_email_template('123456');
    $same('Verify your sign-in', $message['subject']);
    $same(1, substr_count($message['html'], 'CONNEZER EDUTECH SYSTEMS'));
    $same(1, substr_count($message['html'], '<h1'));
    $same(true, str_contains($message['html'], 'Use this six-digit code to complete your login.'));
    $same(true, str_contains($message['html'], '>123456</span>'));
    $same(true, str_contains($message['html'], 'This code expires in <strong>5 minutes</strong>.'));
    $same(true, str_contains($message['html'], 'Security notice:</strong> Never share this code.'));
    $same(true, str_contains($message['html'], 'you can ignore this email.'));
    $same(true, str_contains($message['html'], 'mailto:connezeredutech@gmail.com'));
    $same(true, str_contains($message['html'], 'max-width:560px'));
    $same(true, str_contains($message['html'], 'role="presentation"'));
    $same(true, str_contains($message['text'], 'IT Support: connezeredutech@gmail.com'));
});

$test('email correction is restricted to invalid old addresses and valid replacements', static function () use ($same): void {
    $same(true, account_email_correction_allowed('invalid-address', 'learner@example.com'));
    $same(false, account_email_correction_allowed('current@example.com', 'replacement@example.com'));
    $same(false, account_email_correction_allowed('invalid-address', 'not-an-email'));
    $same(false, account_email_correction_allowed('invalid-address', str_repeat('a', 243) . '@example.com'));
});

$test('remember-me cookies cannot restore an authenticated session', static function () use ($same): void {
    $hadConfig = array_key_exists('config', $GLOBALS);
    $previousConfig = $GLOBALS['config'] ?? null;
    $previousSession = $_SESSION ?? [];
    $previousCookie = $_COOKIE['remember_auth'] ?? null;
    $hadCookie = array_key_exists('remember_auth', $_COOKIE);
    $GLOBALS['config'] = ['app_url' => 'http://localhost/school-dev'];
    $_SESSION = [];
    $_COOKIE['remember_auth'] = str_repeat('a', 24) . ':' . str_repeat('b', 64);
    try {
        restore_remembered_session();
        $same(false, array_key_exists('user_id', $_SESSION));
        $same(false, array_key_exists('remember_auth', $_COOKIE));
    } finally {
        $_SESSION = $previousSession;
        if ($hadCookie) {
            $_COOKIE['remember_auth'] = $previousCookie;
        } else {
            unset($_COOKIE['remember_auth']);
        }
        if ($hadConfig) {
            $GLOBALS['config'] = $previousConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }
});

$test('school subscription trials expire exactly 14 days after UTC activation', static function () use ($same): void {
    $activatedAt = new DateTimeImmutable('2026-11-15 10:30:00', new DateTimeZone('Africa/Accra'));
    $expiresAt = school_subscription_trial_expiration($activatedAt);
    $same('2026-11-29 10:30:00', $expiresAt->format('Y-m-d H:i:s'));
    $same('UTC', $expiresAt->getTimezone()->getName());
});

$test('assistant headmaster and secretary roles follow least-privilege permissions', static function () use ($same): void {
    $permissions = school_role_permissions();
    $same(true, in_array('attendance.manage', $permissions['school_assistant_headmaster'], true));
    $same(false, in_array('fees.manage', $permissions['school_assistant_headmaster'], true));
    $same(false, in_array('payroll.manage', $permissions['school_assistant_headmaster'], true));
    $same(true, in_array('timetable.manage', $permissions['school_assistant_headmaster'], true));
    $same(false, in_array('timetable.manage', $permissions['class_teacher'], true));
    $same(true, in_array('timetable.view', $permissions['class_teacher'], true));
    $same(false, in_array('timetable.view', $permissions['school_parent'], true));
    $same(true, in_array('students.manage', $permissions['school_secretary'], true));
    $same(false, in_array('fees.manage', $permissions['school_secretary'], true));
    $same(false, in_array('school.manage', $permissions['school_secretary'], true));
    $same('Assistant Headmaster', role_display_name('school_assistant_headmaster'));
    $same('Secretary / Administrator', role_display_name('school_secretary'));
});

$test('staff employment changes preserve scheduled effective dates', static function () use ($same, $throws): void {
    $same([
        'event_type' => 'terminated',
        'new_status' => 'terminated',
        'current_status' => 'terminated',
        'effective_on' => '2026-11-17',
        'event_state' => 'scheduled',
    ], school_staff_employment_transition('terminated', '2026-11-17', '2026-11-17'));
    $same([
        'event_type' => 'termination_scheduled',
        'new_status' => 'resigned',
        'current_status' => 'termination_scheduled',
        'effective_on' => '2026-12-01',
        'event_state' => 'scheduled',
    ], school_staff_employment_transition('resigned', '2026-12-01', '2026-11-17'));
    $throws(InvalidArgumentException::class, static fn() =>
        school_staff_employment_transition('suspended', '2026-12-01', '2026-11-17')
    );
    $throws(InvalidArgumentException::class, static fn() =>
        school_staff_employment_transition('retired', '2026-11-16', '2026-11-17')
    );
});

$test('student class history requires accurate non-future effective dates', static function () use ($same, $throws): void {
    $same('2026-10-12', student_class_history_effective_date('2026-10-12', '2026-11-17'));
    $throws(InvalidArgumentException::class, static fn() =>
        student_class_history_effective_date('2026-02-30', '2026-11-17')
    );
    $throws(InvalidArgumentException::class, static fn() =>
        student_class_history_effective_date('2026-11-18', '2026-11-17')
    );
});

$test('fee amounts convert to exact cents and reject ambiguous input', static function () use ($same): void {
    $same(0, school_fee_amount_cents('0'));
    $same(1250, school_fee_amount_cents('12.5'));
    $same(40000, school_fee_amount_cents('400.00'));
    $same(123456, school_fee_amount_cents(1234.56));
    $same(null, school_fee_amount_cents('01.00'));
    $same(null, school_fee_amount_cents('1e3'));
    $same(null, school_fee_amount_cents('1.001'));
    $same(null, school_fee_amount_cents([]));
});

$test('staff arrival punctuality uses the configured report time and grace', static function () use ($same): void {
    $schedule = [
        'reporting_time' => '06:00',
        'grace_minutes' => 15,
        'very_late_after_minutes' => 60,
    ];
    $arrival = static fn(string $time): DateTimeImmutable => new DateTimeImmutable(
        '2026-11-10 ' . $time,
        new DateTimeZone('Africa/Accra')
    );
    $same('early', school_staff_attendance_classify_arrival($arrival('05:45'), '2026-11-10', $schedule)['status']);
    $same('punctual', school_staff_attendance_classify_arrival($arrival('06:00'), '2026-11-10', $schedule)['status']);
    $same('punctual', school_staff_attendance_classify_arrival($arrival('06:15'), '2026-11-10', $schedule)['status']);
    $firstLate = school_staff_attendance_classify_arrival($arrival('06:16'), '2026-11-10', $schedule);
    $same('late', $firstLate['status']);
    $same(16, $firstLate['minutes_late']);
    $late = school_staff_attendance_classify_arrival($arrival('06:30'), '2026-11-10', $schedule);
    $same('late', $late['status']);
    $same(30, $late['minutes_late']);
    $veryLate = school_staff_attendance_classify_arrival($arrival('07:16'), '2026-11-10', $schedule);
    $same('very_late', $veryLate['status']);
    $same(76, $veryLate['minutes_late']);
    $noonArrival = school_staff_attendance_classify_arrival($arrival('12:00'), '2026-11-10', $schedule);
    $same('very_late', $noonArrival['status']);
    $same(360, $noonArrival['minutes_late']);
});

$test('staff kiosk check-in hours allow late arrivals but reject overnight and after 2 PM', static function () use ($same): void {
    $arrival = static fn(string $time): DateTimeImmutable => new DateTimeImmutable(
        '2026-11-10 ' . $time,
        new DateTimeZone('Africa/Accra')
    );
    $same(false, school_staff_attendance_check_in_time_allowed($arrival('02:00')));
    $same(false, school_staff_attendance_check_in_time_allowed($arrival('05:29')));
    $same(true, school_staff_attendance_check_in_time_allowed($arrival('05:30')));
    $same(true, school_staff_attendance_check_in_time_allowed($arrival('12:00')));
    $same(true, school_staff_attendance_check_in_time_allowed($arrival('13:59')));
    $same(false, school_staff_attendance_check_in_time_allowed($arrival('14:00')));
});

$test('staff attendance recovery hashes PINs and reset codes', static function () use ($same): void {
    $hadConfig = array_key_exists('config', $GLOBALS);
    $previousConfig = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = ['auth_pepper' => 'regression-test-pepper'];
    try {
        $oldPinHash = make_password_hash('123456');
        $newPinHash = make_password_hash('654321');
        $resetCodeHash = keyed_hash('12345678');
        $same(false, hash_equals('123456', $newPinHash));
        $same(true, password_verify(password_input('654321'), $newPinHash));
        $same(false, password_verify(password_input('123456'), $newPinHash));
        $same(true, password_verify(password_input('123456'), $oldPinHash));
        $same(false, $newPinHash === $oldPinHash);
        $same(false, hash_equals('12345678', $resetCodeHash));
        $same(64, strlen($resetCodeHash));
    } finally {
        if ($hadConfig) {
            $GLOBALS['config'] = $previousConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }
});

$test('staff attendance audit snapshots distinguish kiosk and administrator verification', static function () use ($same): void {
    $snapshot = school_staff_attendance_snapshot([
        'attendance_date' => '2026-11-10',
        'arrival_at' => '2026-11-10 06:12:00',
        'departure_at' => null,
        'status' => 'present',
        'punctuality_status' => 'punctual',
        'minutes_late' => 0,
        'working_minutes' => null,
        'early_departure' => 0,
        'verification_method' => 'kiosk_verified',
        'departure_verification_method' => null,
        'expected_reporting_time' => '06:00:00',
        'remarks' => null,
    ]);
    $same('kiosk_verified', $snapshot['verification_method']);
    $same('punctual', $snapshot['punctuality_status']);
    $manualSnapshot = school_staff_attendance_snapshot([
        'attendance_date' => '2026-11-10',
        'arrival_at' => '2026-11-10 06:12:00',
        'departure_at' => null,
        'status' => 'present',
        'punctuality_status' => 'punctual',
        'minutes_late' => 0,
        'working_minutes' => null,
        'early_departure' => 0,
        'verification_method' => 'administrator_recorded',
        'departure_verification_method' => null,
        'expected_reporting_time' => '06:00:00',
        'remarks' => 'Verified present at the office',
    ]);
    $same('administrator_recorded', $manualSnapshot['verification_method']);
    $same('Verified present at the office', $manualSnapshot['remarks']);
});

$test('staff attendance summaries count scheduled days, late arrivals, and missing records once', static function () use ($same): void {
    $dates = [
        '2026-11-09',
        '2026-11-10',
        '2026-11-11',
        '2026-11-12',
        '2026-11-13',
    ];
    $records = [
        '2026-11-09' => ['status' => 'present', 'punctuality_status' => 'punctual'],
        '2026-11-10' => ['status' => 'late', 'punctuality_status' => 'late'],
        '2026-11-11' => ['status' => 'absent', 'punctuality_status' => null],
        '2026-11-12' => ['status' => 'present', 'punctuality_status' => 'early'],
    ];
    $summary = school_staff_attendance_period_summary($dates, $records);
    $same(5, $summary['scheduled_days']);
    $same(3, $summary['present_days']);
    $same(2, $summary['absent_days']);
    $same(1, $summary['late_days']);
    $same(1, $summary['unmarked_days']);
    $same(60.0, $summary['attendance_rate']);
    $same(80.0, school_staff_attendance_period_summary(
        $dates,
        [
            '2026-11-09' => ['status' => 'present'],
            '2026-11-10' => ['status' => 'present'],
            '2026-11-11' => ['status' => 'absent'],
            '2026-11-12' => ['status' => 'present'],
            '2026-11-13' => ['status' => 'present'],
        ]
    )['attendance_rate']);
    $pending = school_staff_attendance_period_summary(
        ['2026-11-13'],
        ['2026-11-13' => ['status' => 'pending']]
    );
    $same(1, $pending['absent_days']);
    $same(1, $pending['unmarked_days']);
    $same(0.0, $pending['attendance_rate']);
});

$test('staff attendance periods include a recorded check-in but defer an unrecorded current-day absence', static function () use ($same): void {
    $timezone = new DateTimeZone('Africa/Accra');
    $same(true, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 10:00:00', $timezone),
        '15:00',
        ['status' => 'late']
    ));
    $same(true, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 10:00:00', $timezone),
        '15:00',
        ['status' => 'excused']
    ));
    $same(false, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 10:00:00', $timezone),
        '15:00'
    ));
    $same(false, school_staff_attendance_day_is_elapsed(
        '2026-11-11',
        new DateTimeImmutable('2026-11-10 16:00:00', $timezone),
        '15:00'
    ));
    $same(false, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 15:00:00', $timezone),
        '15:00'
    ));
    $same(false, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 15:59:00', $timezone),
        '13:00'
    ));
    $same(true, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 16:00:00', $timezone),
        '13:00'
    ));
    $same('16:00', school_staff_attendance_absence_cutoff('15:00'));
    $same('17:00', school_staff_attendance_absence_cutoff('17:00'));
    $same(false, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 16:00:00', $timezone),
        '17:00'
    ));
    $same(true, school_staff_attendance_day_is_elapsed(
        '2026-11-10',
        new DateTimeImmutable('2026-11-10 17:00:00', $timezone),
        '17:00'
    ));
});

$test('staff payroll attendance separates future days, absences, excuses, and make-up credits', static function () use ($same): void {
    $dates = ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'];
    $records = [
        '2026-11-09' => ['status' => 'present'],
        '2026-11-10' => ['status' => 'late'],
        '2026-11-11' => ['status' => 'present'],
        '2026-11-12' => ['status' => 'excused'],
    ];
    $afterClose = new DateTimeImmutable('2026-11-13 16:00:00', new DateTimeZone('Africa/Accra'));
    $summary = school_staff_attendance_payroll_month_summary($dates, $records, 0, $afterClose, '15:00');
    $same(5, $summary['required_days']);
    $same(5, $summary['elapsed_days']);
    $same(3, $summary['completed_days']);
    $same(1, $summary['excused_days']);
    $same(1, $summary['outstanding_days']);
    $same(false, $summary['eligible']);
    $credited = school_staff_attendance_payroll_month_summary($dates, $records, 2, $afterClose, '15:00');
    $same(0, $credited['outstanding_days']);
    $same(true, $credited['eligible']);

    $beforeClose = new DateTimeImmutable('2026-11-11 12:00:00', new DateTimeZone('Africa/Accra'));
    $incomplete = school_staff_attendance_payroll_month_summary(
        array_slice($dates, 0, 3),
        array_slice($records, 0, 2, true),
        0,
        $beforeClose,
        '15:00'
    );
    $same(2, $incomplete['elapsed_days']);
    $same(1, $incomplete['pending_days']);
    $same(0, $incomplete['outstanding_days']);
    $same(false, $incomplete['eligible']);
});

$test('staff payroll keeps a day pending until the 4 PM absence cutoff', static function () use ($same): void {
    $timezone = new DateTimeZone('Africa/Accra');
    $beforeCutoff = school_staff_attendance_payroll_month_summary(
        ['2026-11-10'],
        [],
        0,
        new DateTimeImmutable('2026-11-10 15:59:00', $timezone),
        '13:00'
    );
    $same(0, $beforeCutoff['elapsed_days']);
    $same(1, $beforeCutoff['pending_days']);
    $atCutoff = school_staff_attendance_payroll_month_summary(
        ['2026-11-10'],
        [],
        0,
        new DateTimeImmutable('2026-11-10 16:00:00', $timezone),
        '13:00'
    );
    $same(1, $atCutoff['elapsed_days']);
    $same(1, $atCutoff['outstanding_days']);
});

$test('fee payment status maps paid, partial, owing, and review states', static function () use ($same): void {
    $same('fee-status-paid', school_fee_status_class('paid'));
    $same('fee-status-paid', school_fee_status_class('Fully Paid'));
    $same('fee-status-partial', school_fee_status_class('partial'));
    $same('fee-status-partial', school_fee_status_class('Part payment'));
    $same('fee-status-owes', school_fee_status_class('issued'));
    $same('fee-status-owes', school_fee_status_class('overdue'));
    $same('fee-status-neutral', school_fee_status_class('pending financial review'));
});

$test('payment statement parser validates headers, amounts, dates, and duplicate references', static function () use ($same, $throws): void {
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, ['reference', 'amount', 'date']);
    fputcsv($stream, [' REF-1 ', '12.50', '2026-10-01']);
    fputcsv($stream, ['ref-1', '12.50', '2026-10-01']);
    fputcsv($stream, ['REF-3', 'invalid', '2026-10-01']);
    rewind($stream);
    $rows = school_payment_statement_parse($stream);
    fclose($stream);
    $same(3, count($rows));
    $same(1250, $rows[0]['amount_cents']);
    $same(true, $rows[0]['duplicate_statement_reference']);
    $same(true, $rows[1]['duplicate_statement_reference']);
    $same(false, $rows[2]['issue'] === null);

    $badDate = fopen('php://temp', 'r+');
    fputcsv($badDate, ['reference', 'amount', 'date']);
    fputcsv($badDate, ['BAD-DATE', '1.00', '2026-02-30']);
    rewind($badDate);
    $invalidDateRows = school_payment_statement_parse($badDate);
    fclose($badDate);
    $same(false, $invalidDateRows[0]['issue'] === null);

    $badHeader = fopen('php://temp', 'r+');
    fputcsv($badHeader, ['transaction', 'value']);
    rewind($badHeader);
    $throws(InvalidArgumentException::class, static fn() => school_payment_statement_parse($badHeader));
    fclose($badHeader);

    $tooManyRows = fopen('php://temp', 'r+');
    fputcsv($tooManyRows, ['reference', 'amount']);
    fputcsv($tooManyRows, ['one', '1.00']);
    fputcsv($tooManyRows, ['two', '1.00']);
    rewind($tooManyRows);
    $throws(InvalidArgumentException::class, static fn() => school_payment_statement_parse($tooManyRows, 1));
    fclose($tooManyRows);
});

$test('payment reconciliation classifies matches, discrepancies, pending, and reference errors', static function () use ($same): void {
    $rows = [
        ['row_number' => 2, 'reference' => 'MATCH', 'normalized_reference' => 'MATCH', 'amount_cents' => 1000, 'date' => '2026-10-01', 'issue' => null, 'duplicate_statement_reference' => false],
        ['row_number' => 3, 'reference' => 'PENDING', 'normalized_reference' => 'PENDING', 'amount_cents' => 1250, 'date' => '2026-10-01', 'issue' => null, 'duplicate_statement_reference' => false],
        ['row_number' => 4, 'reference' => 'DIFF', 'normalized_reference' => 'DIFF', 'amount_cents' => 1500, 'date' => '2026-10-01', 'issue' => null, 'duplicate_statement_reference' => false],
        ['row_number' => 5, 'reference' => 'TYPO', 'normalized_reference' => 'TYPO', 'amount_cents' => 2000, 'date' => '2026-10-01', 'issue' => null, 'duplicate_statement_reference' => false],
        ['row_number' => 6, 'reference' => 'NOT-RECORDED', 'normalized_reference' => 'NOT-RECORDED', 'amount_cents' => 500, 'date' => null, 'issue' => null, 'duplicate_statement_reference' => false],
        ['row_number' => 7, 'reference' => 'RECORD-DUP', 'normalized_reference' => 'RECORD-DUP', 'amount_cents' => 1000, 'date' => null, 'issue' => null, 'duplicate_statement_reference' => false],
        ['row_number' => 8, 'reference' => 'BANK-DUP', 'normalized_reference' => 'BANK-DUP', 'amount_cents' => 1000, 'date' => null, 'issue' => null, 'duplicate_statement_reference' => true],
        ['row_number' => 9, 'reference' => 'SEARCH-LIMIT', 'normalized_reference' => 'SEARCH-LIMIT', 'amount_cents' => 1000, 'date' => '2026-10-01', 'issue' => null, 'duplicate_statement_reference' => false],
    ];
    $payments = [
        'MATCH' => [['id' => 11, 'amount' => '10.00', 'status' => 'verified', 'received_at' => '2026-10-01 10:00:00']],
        'PENDING' => [['id' => 12, 'amount' => '12.50', 'status' => 'pending', 'received_at' => '2026-10-01 11:00:00']],
        'DIFF' => [['id' => 13, 'amount' => '14.00', 'status' => 'verified', 'received_at' => '2026-10-01 12:00:00']],
        'RECORD-DUP' => [
            ['id' => 15, 'amount' => '10.00', 'status' => 'verified', 'received_at' => '2026-10-01 12:00:00'],
            ['id' => 16, 'amount' => '10.00', 'status' => 'verified', 'received_at' => '2026-10-01 12:00:00'],
        ],
    ];
    $results = school_payment_reconciliation_results($rows, $payments, [
        5 => [['id' => 14, 'amount' => '20.00', 'status' => 'verified', 'received_at' => '2026-10-01 12:00:00']],
        9 => [['search_limited' => true]],
    ]);
    $same('matched', $results[0]['status']);
    $same('pending_verification', $results[1]['status']);
    $same('amount_discrepancy', $results[2]['status']);
    $same('possible_reference_mismatch', $results[3]['status']);
    $same('unmatched', $results[4]['status']);
    $same('duplicate_recorded_reference', $results[5]['status']);
    $same('duplicate_statement_transaction', $results[6]['status']);
    $same('candidate_search_limited', $results[7]['status']);
});

$test('uploaded-file backup includes private uploads and excludes volatile data', static function () use ($same): void {
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'connezer-storage-test-' . bin2hex(random_bytes(6));
    $backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'connezer-backup-test-' . bin2hex(random_bytes(6));
    if (!mkdir($fixtureRoot, 0700) || !mkdir($backupRoot, 0700)) {
        throw new RuntimeException('Could not create isolated storage-backup test directories.');
    }
    $archivePath = $backupRoot . DIRECTORY_SEPARATOR . 'uploads-test.tar';
    try {
        foreach (['school-logos', 'student-lifecycle', 'backups', 'logs', 'sessions'] as $directory) {
            if (!mkdir($fixtureRoot . DIRECTORY_SEPARATOR . $directory, 0700)) {
                throw new RuntimeException('Could not create storage fixture directory.');
            }
        }
        file_put_contents($fixtureRoot . DIRECTORY_SEPARATOR . 'school-logos' . DIRECTORY_SEPARATOR . 'logo.png', 'logo-fixture');
        file_put_contents($fixtureRoot . DIRECTORY_SEPARATOR . 'student-lifecycle' . DIRECTORY_SEPARATOR . 'private.pdf', 'document-fixture');
        file_put_contents($fixtureRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'old.sql', 'old-backup');
        file_put_contents($fixtureRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log', 'log-fixture');
        file_put_contents($fixtureRoot . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . 'session', 'session-fixture');
        file_put_contents($fixtureRoot . DIRECTORY_SEPARATOR . '.htaccess', 'Require all denied');

        $backup = create_storage_backup_archive($fixtureRoot, $archivePath);
        $same(2, $backup['file_count']);
        $same(strlen('logo-fixture') + strlen('document-fixture'), $backup['source_bytes']);
        $same(true, is_file($backup['path']));

        $archive = new PharData($backup['path']);
        $members = [];
        $iterator = new RecursiveIteratorIterator($archive);
        foreach ($iterator as $member) {
            $members[] = str_replace('\\', '/', $iterator->getSubPathName());
        }
        sort($members);
        $same(['school-logos/logo.png', 'student-lifecycle/private.pdf'], $members);
        unset($iterator, $archive);
    } finally {
        $cleanup = static function (string $path) use (&$cleanup): void {
            if (is_link($path) || is_file($path)) {
                unlink($path);
                return;
            }
            if (is_dir($path)) {
                foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                    $cleanup($entry->getPathname());
                }
                rmdir($path);
            }
        };
        $cleanup($fixtureRoot);
        $cleanup($backupRoot);
    }
});

$test('assessment marks validate bounds and round to two decimals', static function () use ($same, $throws): void {
    $same(87.35, school_assessment_mark(' 87.345 ', 100));
    $same(0.0, school_assessment_mark(0, 100));
    $throws(InvalidArgumentException::class, static fn() => school_assessment_mark('-0.01', 100));
    $throws(InvalidArgumentException::class, static fn() => school_assessment_mark('100.01', 100));
    $throws(InvalidArgumentException::class, static fn() => school_assessment_mark('not a mark', 100));
    $throws(InvalidArgumentException::class, static fn() => school_assessment_mark(INF, 100));
});

$test('assessment rankings handle competition and dense ties', static function () use ($same, $throws): void {
    $scores = [101 => 88, 102 => 75, 103 => 75, 104 => 60];
    $same([101 => 1, 102 => 2, 103 => 2, 104 => 4], school_rank_positions($scores, 'competition'));
    $same([101 => 1, 102 => 2, 103 => 2, 104 => 3], school_rank_positions($scores, 'dense'));
    $same([], school_rank_positions([], 'competition'));
    $throws(InvalidArgumentException::class, static fn() => school_rank_positions([1 => INF], 'competition'));
});

$test('ranking labels use correct ordinal suffixes', static function () use ($same): void {
    $same('1st', school_rank_position_label(1));
    $same('12th', school_rank_position_label(12));
    $same('21st', school_rank_position_label(21));
    $same('—', school_rank_position_label(null));
});

$test('academic year dates reject invalid calendar dates and mismatched labels', static function () use ($same): void {
    $same(true, school_academic_year_name_is_valid('2026/2027'));
    $same(false, school_academic_year_name_is_valid('2026/2028'));
    $same(true, school_academic_year_dates_match_label('2026/2027', '2026-09-01', '2027-07-31'));
    $same(false, school_academic_year_dates_match_label('2026/2027', '2025-09-01', '2027-07-31'));
    $same(null, school_academic_year_dates('2026-02-30', '2027-07-31'));
    $same(null, school_academic_year_dates('2027-01-01', '2026-12-31'));
});

$test('timetable settings build aligned periods and configured non-teaching blocks', static function () use ($same, $throws): void {
    $periods = school_timetable_build_periods([
        'weekdays' => [1, 2],
        'starts_at' => '08:00',
        'lesson_minutes' => 40,
        'lesson_count' => 3,
        'break_after_period' => 1,
        'break_minutes' => 10,
        'lunch_after_period' => null,
        'lunch_minutes' => 0,
        'assembly_after_period' => null,
        'assembly_minutes' => 0,
    ]);
    $same(8, count($periods));
    $same(['08:00:00', '08:40:00', 'lesson'], [
        $periods[0]['starts_at'], $periods[0]['ends_at'], $periods[0]['kind'],
    ]);
    $same(['08:40:00', '08:50:00', 'break'], [
        $periods[1]['starts_at'], $periods[1]['ends_at'], $periods[1]['kind'],
    ]);
    $same('08:50:00', $periods[2]['starts_at']);
    $same(2, $periods[2]['period_number']);
    $throws(InvalidArgumentException::class, static fn() => school_timetable_build_periods([
        'weekdays' => [],
        'starts_at' => '08:00',
        'lesson_minutes' => 40,
        'lesson_count' => 3,
    ]));
    $throws(InvalidArgumentException::class, static fn() => school_timetable_build_periods([
        'weekdays' => [1],
        'starts_at' => '23:30',
        'lesson_minutes' => 60,
        'lesson_count' => 1,
    ]));
});

$test('timetable generator respects capacity, shared teachers, doubles, and unavailability', static function () use ($same): void {
    $periods = school_timetable_build_periods([
        'weekdays' => [1, 2, 3],
        'starts_at' => '08:00',
        'lesson_minutes' => 40,
        'lesson_count' => 3,
    ]);
    $requirements = [
        [
            'id' => 1, 'class_id' => 10, 'subject_id' => 20, 'teacher_user_id' => 90,
            'periods_per_week' => 2, 'double_periods' => 1, 'preferred_room_id' => 1,
        ],
        [
            'id' => 2, 'class_id' => 11, 'subject_id' => 21, 'teacher_user_id' => 90,
            'periods_per_week' => 2, 'double_periods' => 0, 'preferred_room_id' => null,
        ],
    ];
    $rooms = [
        ['id' => 1, 'name' => 'Small', 'capacity' => 18],
        ['id' => 2, 'name' => 'Large', 'capacity' => 40],
    ];
    $result = school_timetable_generate_schedule(
        $requirements,
        $periods,
        $rooms,
        [10 => 25, 11 => 38],
        [],
        [
            'max_periods_per_teacher_day' => 2,
            'max_periods_per_teacher_week' => 10,
            'max_periods_per_class_day' => 2,
        ]
    );
    $same(true, $result['ok']);
    $same(4, count($result['entries']));
    $same([], school_timetable_validate_entries($result['entries'], [
        'max_periods_per_teacher_day' => 2,
        'max_periods_per_teacher_week' => 10,
        'max_periods_per_class_day' => 2,
    ]));
    $doubleEntries = array_values(array_filter(
        $result['entries'],
        static fn(array $entry): bool => $entry['double_period_group'] !== ''
    ));
    $same(2, count($doubleEntries));
    $same((int) $doubleEntries[0]['weekday'], (int) $doubleEntries[1]['weekday']);
    $same(1, abs((int) $doubleEntries[0]['period_number'] - (int) $doubleEntries[1]['period_number']));
    $same([2], array_values(array_unique(array_column($result['entries'], 'room_id'))));

    $blocked = [];
    foreach ([1, 2, 3] as $weekday) {
        foreach ([1, 2, 3] as $periodNumber) {
            $blocked[] = [
                'entity_type' => 'teacher',
                'entity_id' => 90,
                'weekday' => $weekday,
                'period_number' => $periodNumber,
            ];
        }
    }
    $unavailableResult = school_timetable_generate_schedule(
        [$requirements[0]],
        $periods,
        $rooms,
        [10 => 25],
        $blocked
    );
    $same(false, $unavailableResult['ok']);
    $same('no_available_period', $unavailableResult['errors'][0]['code']);

    $capacityResult = school_timetable_generate_schedule(
        [$requirements[0]],
        $periods,
        [['id' => 1, 'name' => 'Too small', 'capacity' => 20]],
        [10 => 25]
    );
    $same(false, $capacityResult['ok']);
    $same('no_suitable_room', $capacityResult['errors'][0]['code']);
});

$test('timetable validator reports class, teacher, room, and teacher-assignment conflicts', static function () use ($same): void {
    $entries = [
        ['class_id' => 1, 'subject_id' => 1, 'teacher_user_id' => 10, 'room_id' => 1, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        ['class_id' => 2, 'subject_id' => 2, 'teacher_user_id' => 10, 'room_id' => 2, 'weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:30'],
        ['class_id' => 3, 'subject_id' => 3, 'teacher_user_id' => 11, 'room_id' => 1, 'weekday' => 1, 'starts_at' => '08:15', 'ends_at' => '08:45'],
        ['class_id' => 1, 'subject_id' => 4, 'teacher_user_id' => 12, 'room_id' => 3, 'weekday' => 1, 'starts_at' => '08:10', 'ends_at' => '08:20', 'teacher_is_assigned' => false],
    ];
    $codes = array_column(school_timetable_validate_entries($entries), 'code');
    $same(true, in_array('teacher_conflict', $codes, true));
    $same(true, in_array('room_conflict', $codes, true));
    $same(true, in_array('class_conflict', $codes, true));
    $same(true, in_array('teacher_subject_assignment', $codes, true));
});

$test('homework input validates text, safe resource links, and exact due dates', static function () use ($same, $throws): void {
    $assignment = school_learning_validate_assignment_input(
        '  Fractions practice  ',
        "Complete questions 1-5.\nShow your working.",
        'https://example.edu/math/fractions',
        '2026-11-16T08:30',
        new DateTimeZone('Africa/Accra')
    );
    $same('Fractions practice', $assignment['title']);
    $same("Complete questions 1-5.\nShow your working.", $assignment['instructions']);
    $same('2026-11-16 08:30:00', $assignment['due_at']);
    $same(null, school_learning_validate_assignment_input(
        'Title',
        'Instructions',
        '',
        '',
        new DateTimeZone('Africa/Accra')
    )['due_at']);
    $throws(InvalidArgumentException::class, static fn() => school_learning_validate_assignment_input(
        '',
        'Instructions',
        '',
        '',
        new DateTimeZone('Africa/Accra')
    ));
    $throws(InvalidArgumentException::class, static fn() => school_learning_validate_assignment_input(
        'Title',
        'Instructions',
        'javascript:alert(1)',
        '',
        new DateTimeZone('Africa/Accra')
    ));
    $throws(InvalidArgumentException::class, static fn() => school_learning_validate_assignment_input(
        'Title',
        'Instructions',
        'https://student:secret@example.edu/resource',
        '',
        new DateTimeZone('Africa/Accra')
    ));
    $throws(InvalidArgumentException::class, static fn() => school_learning_validate_assignment_input(
        'Title',
        'Instructions',
        '',
        '2026-02-31T08:30',
        new DateTimeZone('Africa/Accra')
    ));
    $throws(InvalidArgumentException::class, static fn() => school_learning_validate_assignment_input(
        ['Title'],
        'Instructions',
        '',
        '',
        new DateTimeZone('Africa/Accra')
    ));
    $same(null, school_learning_external_http_url('javascript:alert(1)'));
    $same('https://example.edu/resource', school_learning_external_http_url('https://example.edu/resource'));
});

$test('attendance sync detects stale versions and accepts an exact retry', static function () use ($same): void {
    $syncToken = str_repeat('a', 32);
    $same(false, school_attendance_sync_row_conflicts(null, 0, 'present', $syncToken));
    $same(false, school_attendance_sync_row_conflicts(
        ['row_version' => 3, 'status' => 'present', 'last_sync_token' => null],
        3,
        'absent',
        $syncToken
    ));
    $same(true, school_attendance_sync_row_conflicts(
        ['row_version' => 3, 'status' => 'present', 'last_sync_token' => null],
        2,
        'absent',
        $syncToken
    ));
    $same(false, school_attendance_sync_row_conflicts(
        ['row_version' => 4, 'status' => 'absent', 'last_sync_token' => $syncToken],
        3,
        'absent',
        $syncToken
    ));
    $same(true, school_attendance_sync_row_conflicts(
        ['row_version' => 4, 'status' => 'present', 'last_sync_token' => $syncToken],
        3,
        'absent',
        $syncToken
    ));
});

$test('attendance summary preserves unmarked days and distinguishes late policy', static function () use ($same): void {
    $dates = ['mon', 'tue', 'wed', 'thu', 'fri'];
    $records = [
        'mon' => ['status' => 'present'],
        'tue' => ['status' => 'late'],
        'wed' => ['status' => 'absent'],
        'thu' => ['status' => 'excused'],
        'fri' => ['status' => 'pending'],
    ];

    $strict = school_attendance_count_period($dates, $records);
    $same(1, $strict['unmarked']);
    $same(4, $strict['recorded_days']);
    $same(null, $strict['percentage']);
    $same(true, $strict['incomplete']);

    $completeRecords = array_slice($records, 0, 4, true);
    $strict = school_attendance_count_period(array_slice($dates, 0, 4), $completeRecords);
    $same(25.0, $strict['percentage']);
    $same(1, $strict['attended']);

    $lateCounts = school_attendance_count_period(array_slice($dates, 0, 4), $completeRecords, true);
    $same(66.67, $lateCounts['percentage']);
    $same(2, $lateCounts['attended']);
});

$test('TOTP matches RFC 6238 SHA-1 test vectors', static function () use ($same, $throws): void {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $same('287082', totp_code($secret, 59));
    $same('081804', totp_code($secret, 1111111109));
    $throws(InvalidArgumentException::class, static fn() => base32_decode('INVALID0'));
});

$test('Connezer Ai profiles are branded, role-gated, and general mode has no school tools', static function () use ($same): void {
    $profiles = school_ai_assistant_profiles();
    $same(['school_management', 'general', 'teaching'], array_keys($profiles));
    $same([
        'Connezer Ai · School Management',
        'Connezer Ai · General',
        'Connezer Ai · Teaching & Learning',
    ], array_column($profiles, 'name'));
    $same(['general', 'school_management', 'teaching'], school_ai_assistant_keys_for_role('school_headmaster'));
    $same(['general', 'teaching'], school_ai_assistant_keys_for_role('school_student'));
    $same(['general'], school_ai_assistant_keys_for_role('school_parent'));
    $same([], school_ai_tools_for_assistant('general', [], 1, 'school_headmaster'));
});

$test('AI conversation titles use readable first-message text and omit attached context', static function () use ($same): void {
    $same(
        'What is gravity?',
        school_ai_conversation_title("  What is   gravity? \n\nUser-provided source material (untrusted):\nAttached notes")
    );
    $same('New conversation', school_ai_conversation_title(" \n "));
    $longTitle = school_ai_conversation_title(str_repeat('é', 80));
    $same(1, preg_match('/\A.{72}\.\.\.\z/us', $longTitle));
});

$test('custom class names, categories, academic stages, and capacity are validated', static function () use ($same, $throws): void {
    $same(true, school_class_level_name_is_valid('Creche'));
    $same(true, school_class_level_name_is_valid('Senior High School 4'));
    $same(false, school_class_level_name_is_valid(''));
    $same(false, school_class_level_name_is_valid(str_repeat('x', 81)));
    $same(true, school_class_category_is_valid('Vocational'));
    $same(false, school_class_academic_stage_is_valid('unrecognized'));
    $same(true, school_class_level_is_primary('Custom Early Years', 'primary'));
    $same(false, school_class_level_is_primary('Custom Level', 'other'));
    $same(true, school_class_level_is_jhs('Custom High School', 'secondary'));
    $same(true, school_class_capacity_allows(null, 500));
    $same(true, school_class_capacity_allows(2, 1));
    $same(false, school_class_capacity_allows(2, 2));
    $throws(InvalidArgumentException::class, static fn() => school_class_capacity_allows(0, 0));
    $throws(InvalidArgumentException::class, static fn() => school_class_capacity_allows(null, -1));
});

$test('custom class progression rejects cycles and permits terminal levels', static function () use ($same): void {
    $progression = [1 => 2, 2 => 3, 3 => 0];
    $same(false, school_class_progression_would_cycle($progression, 1, 2));
    $same(true, school_class_progression_would_cycle($progression, 3, 1));
    $same(true, school_class_progression_would_cycle([4 => 5, 5 => 4], 6, 4));
});

$test('teaching AI accepts school-scoped classes and bounded subject context', static function () use ($same, $throws): void {
    $same('', school_ai_learning_context('', '', 1));
    $throws(InvalidArgumentException::class, static fn() => school_ai_learning_context('', str_repeat('x', 121), 1));
    $throws(InvalidArgumentException::class, static fn() => school_ai_learning_context('Custom class', 'Science', 0));
});

$test('AI conversation encryption authenticates stored message contents', static function () use ($same, $throws): void {
    global $config;
    $oldKey = $config['app_key'] ?? null;
    $config['app_key'] = 'test-key-with-enough-entropy-for-regression-checks';
    try {
        $ciphertext = school_ai_encrypt_message('private conversation fixture');
        $same('private conversation fixture', school_ai_decrypt_message($ciphertext));
        $tampered = $ciphertext;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
        $throws(RuntimeException::class, static fn() => school_ai_decrypt_message($tampered));
    } finally {
        if ($oldKey === null) {
            unset($config['app_key']);
        } else {
            $config['app_key'] = $oldKey;
        }
    }
});

$test('AI tool execution uses only the supplied read-only allowlist', static function () use ($same): void {
    global $config;
    $oldAi = $config['ai'] ?? null;
    $config['ai'] = ['provider' => 'ollama', 'model' => 'granite3.3:2b'];
    $executed = [];
    $tools = [
        'fixture_lookup' => school_ai_tool(
            'fixture_lookup',
            'Read a synthetic fixture.',
            ['value' => ['type' => 'string']],
            ['value'],
            static function (array $arguments) use (&$executed): string {
                $executed[] = $arguments['value'];
                return 'fixture result';
            }
        ),
    ];
    $calls = 0;
    $transport = static function (array $payload) use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            return ['message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'function' => ['name' => 'fixture_lookup', 'arguments' => ['value' => 'allowed']],
                ]],
            ]];
        }
        $sameToolResult = json_decode((string) $payload['messages'][3]['content'], true);
        if (($sameToolResult['result'] ?? null) !== 'fixture result') {
            throw new RuntimeException('The fixed tool result was not returned to the model.');
        }
        return ['message' => ['role' => 'assistant', 'content' => 'Read-only result.']];
    };
    try {
        $answer = school_ai_run_turn('test system prompt', [['role' => 'user', 'content' => 'read']], $tools, $transport);
        $same('Read-only result.', $answer);
        $same(['allowed'], $executed);
        $same(2, $calls);

        $blockedCalls = 0;
        $blockedTransport = static function (array $payload) use (&$blockedCalls): array {
            $blockedCalls++;
            if ($blockedCalls === 1) {
                return ['message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'function' => ['name' => 'write_school_record', 'arguments' => ['value' => 'not allowed']],
                    ]],
                ]];
            }
            $toolError = json_decode((string) $payload['messages'][3]['content'], true);
            if (!isset($toolError['error'])) {
                throw new RuntimeException('An unregistered tool did not receive a denial result.');
            }
            return ['message' => ['role' => 'assistant', 'content' => 'No record was changed.']];
        };
        $same(
            'No record was changed.',
            school_ai_run_turn('test system prompt', [['role' => 'user', 'content' => 'write']], $tools, $blockedTransport)
        );
        $same(['allowed'], $executed);
        $same(2, $blockedCalls);
    } finally {
        if ($oldAi === null) {
            unset($config['ai']);
        } else {
            $config['ai'] = $oldAi;
        }
    }
});

$test('AI provider configuration rejects unapproved remote providers', static function () use ($same, $throws): void {
    global $config;
    $oldAi = $config['ai'] ?? null;
    $config['ai'] = ['provider' => 'remote', 'model' => 'granite3.3:2b'];
    try {
        $throws(SchoolAiProviderException::class, static fn() => school_ai_model_name());
    } finally {
        if ($oldAi === null) {
            unset($config['ai']);
        } else {
            $config['ai'] = $oldAi;
        }
    }
});

$test('AI Ollama endpoint requires HTTPS off loopback', static function () use ($same, $throws): void {
    global $config;
    $oldAi = $config['ai'] ?? null;
    try {
        $config['ai'] = ['provider' => 'ollama', 'base_url' => 'https://ollama.internal/'];
        $same('https://ollama.internal/api/chat', school_ai_ollama_endpoint());
        $config['ai']['base_url'] = 'http://127.0.0.1:11434';
        $same('http://127.0.0.1:11434/api/chat', school_ai_ollama_endpoint());
        $config['ai']['base_url'] = 'http://ollama.internal';
        $throws(SchoolAiProviderException::class, static fn() => school_ai_ollama_endpoint());
        $config['ai']['base_url'] = 'https://user@ollama.internal';
        $throws(SchoolAiProviderException::class, static fn() => school_ai_ollama_endpoint());
        $config['ai']['base_url'] = 'https://ollama.internal/path';
        $throws(SchoolAiProviderException::class, static fn() => school_ai_ollama_endpoint());
    } finally {
        if ($oldAi === null) {
            unset($config['ai']);
        } else {
            $config['ai'] = $oldAi;
        }
    }
});

fwrite(STDOUT, "All {$passed} regression checks passed.\n");

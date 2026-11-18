<?php
declare(strict_types=1);

function school_attendance_status_indicator(string $status): array
{
    return match ($status) {
        'present' => ['symbol' => '✓', 'label' => 'Present', 'class' => 'attendance-present'],
        'absent' => ['symbol' => '✗', 'label' => 'Absent', 'class' => 'attendance-absent'],
        'excused' => ['symbol' => '–', 'label' => 'Excused', 'class' => 'attendance-excused'],
        'late' => ['symbol' => '●', 'label' => 'Late', 'class' => 'attendance-late'],
        'pending' => ['symbol' => '·', 'label' => 'Not recorded', 'class' => 'attendance-unmarked'],
        default => ['symbol' => '·', 'label' => 'Unmarked', 'class' => 'attendance-unmarked'],
    };
}

function school_attendance_sync_row_conflicts(
    ?array $currentRecord,
    int $expectedVersion,
    string $requestedStatus,
    string $syncToken
): bool {
    if ($currentRecord !== null) {
        $lastSyncToken = (string) ($currentRecord['last_sync_token'] ?? '');
        if ($lastSyncToken !== ''
            && hash_equals($lastSyncToken, $syncToken)
            && (string) ($currentRecord['status'] ?? '') === $requestedStatus) {
            return false;
        }
    }

    $currentVersion = $currentRecord === null ? 0 : (int) ($currentRecord['row_version'] ?? 0);
    return $currentVersion !== $expectedVersion;
}

function school_attendance_save_sync_batch(
    PDO $pdo,
    int $schoolId,
    int $classId,
    string $attendanceDate,
    int $teacherId,
    string $syncToken,
    array $records
): array {
    if ($schoolId < 1 || $classId < 1 || $teacherId < 1
        || preg_match('/^[a-f0-9]{32}$/', $syncToken) !== 1 || $records === []) {
        throw new InvalidArgumentException('Invalid attendance synchronization batch.');
    }

    $pdo->beginTransaction();
    try {
        $existingStmt = $pdo->prepare(
            'SELECT status, legacy_status, row_version, last_sync_token
             FROM student_attendance
             WHERE school_id = ? AND student_id = ? AND attendance_date = ? LIMIT 1
             FOR UPDATE'
        );
        $attendanceColumns = array_column(
            $pdo->query('SHOW COLUMNS FROM student_attendance')->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        $insertColumns = ['school_id', 'student_id', 'class_id'];
        $insertValues = ['?', '?', '?'];
        if (in_array('created_by', $attendanceColumns, true)) {
            $insertColumns[] = 'created_by';
            $insertValues[] = '?';
        }
        if (in_array('updated_by', $attendanceColumns, true)) {
            $insertColumns[] = 'updated_by';
            $insertValues[] = '?';
        }
        array_push(
            $insertColumns,
            'attendance_date',
            'status',
            'row_version',
            'last_sync_token',
            'legacy_status',
            'recorded_by',
            'recorded_at'
        );
        array_push($insertValues, '?', '?', '1', '?', 'NULL', '?', 'NOW()');
        $insertStmt = $pdo->prepare(
            'INSERT INTO student_attendance (' . implode(', ', $insertColumns) . ')
             VALUES (' . implode(', ', $insertValues) . ')'
        );
        $updateFields = [
            'class_id = ?',
            'status = ?',
            'legacy_status = NULL',
            'recorded_by = ?',
            'recorded_at = NOW()',
            'row_version = row_version + 1',
            'last_sync_token = ?',
        ];
        if (in_array('updated_by', $attendanceColumns, true)) {
            $updateFields[] = 'updated_by = ?';
        }
        $updateStmt = $pdo->prepare(
            'UPDATE student_attendance SET ' . implode(', ', $updateFields) . '
             WHERE school_id = ? AND student_id = ? AND attendance_date = ? AND row_version = ?'
        );

        $preparedRecords = [];
        $conflicts = [];
        foreach ($records as $record) {
            $studentId = filter_var($record['student_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $record['status'] ?? null;
            $expectedVersion = filter_var($record['expected_version'] ?? null, FILTER_VALIDATE_INT);
            if ($studentId === false || $studentId < 1
                || !is_string($status) || !in_array($status, ['present', 'absent', 'excused', 'late'], true)
                || $expectedVersion === false || $expectedVersion < 0) {
                throw new InvalidArgumentException('Invalid student attendance entry.');
            }

            $existingStmt->execute([$schoolId, $studentId, $attendanceDate]);
            $oldRecord = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (school_attendance_sync_row_conflicts($oldRecord, $expectedVersion, $status, $syncToken)) {
                $conflicts[] = [
                    'student_id' => $studentId,
                    'current_status' => $oldRecord ? (string) $oldRecord['status'] : null,
                    'current_version' => $oldRecord ? (int) $oldRecord['row_version'] : 0,
                    'queued_status' => $status,
                ];
            }
            $preparedRecords[] = [
                'student_id' => $studentId,
                'status' => $status,
                'expected_version' => $expectedVersion,
                'current' => $oldRecord,
            ];
        }
        if ($conflicts !== []) {
            $pdo->rollBack();
            return ['records' => [], 'conflicts' => $conflicts, 'changed' => []];
        }

        $savedRecords = [];
        $changed = [];
        foreach ($preparedRecords as $record) {
            $studentId = $record['student_id'];
            $status = $record['status'];
            $oldRecord = $record['current'];
            $oldStatus = $oldRecord ? (string) $oldRecord['status'] : 'unmarked';
            $isExactRetry = $oldRecord !== null
                && (string) ($oldRecord['last_sync_token'] ?? '') !== ''
                && hash_equals((string) $oldRecord['last_sync_token'], $syncToken)
                && (string) $oldRecord['status'] === $status;
            if ($isExactRetry) {
                $savedRecords[] = [
                    'student_id' => $studentId,
                    'status' => $status,
                    'row_version' => (int) $oldRecord['row_version'],
                ];
                continue;
            }
            if ($oldStatus !== $status || ($oldRecord && $oldRecord['legacy_status'] !== null)) {
                $changed[] = $studentId . ':' . $oldStatus . '>' . $status;
            }
            if ($oldRecord === null) {
                $parameters = [$schoolId, $studentId, $classId];
                if (in_array('created_by', $attendanceColumns, true)) {
                    $parameters[] = $teacherId;
                }
                if (in_array('updated_by', $attendanceColumns, true)) {
                    $parameters[] = $teacherId;
                }
                array_push($parameters, $attendanceDate, $status, $syncToken, $teacherId);
                $insertStmt->execute($parameters);
                $rowVersion = 1;
            } else {
                $parameters = [$classId, $status, $teacherId, $syncToken];
                if (in_array('updated_by', $attendanceColumns, true)) {
                    $parameters[] = $teacherId;
                }
                array_push(
                    $parameters,
                    $schoolId,
                    $studentId,
                    $attendanceDate,
                    (int) $oldRecord['row_version']
                );
                $updateStmt->execute($parameters);
                if ($updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('Attendance changed while saving. Reload the register and review your choices.');
                }
                $rowVersion = (int) $oldRecord['row_version'] + 1;
            }
            $savedRecords[] = [
                'student_id' => $studentId,
                'status' => $status,
                'row_version' => $rowVersion,
            ];
        }
        $pdo->commit();
        return ['records' => $savedRecords, 'conflicts' => [], 'changed' => $changed];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function school_attendance_date_periods(?array $term = null, ?DateTimeImmutable $today = null, ?int $schoolId = null): array
{
    $today ??= new DateTimeImmutable('today');
    $weekStart = $today->modify('monday this week');
    $monthStart = $today->modify('first day of this month');
    $periods = [
        'week' => ['label' => 'This week', 'start' => $weekStart->format('Y-m-d'), 'end' => $today->format('Y-m-d')],
        'month' => ['label' => 'This month', 'start' => $monthStart->format('Y-m-d'), 'end' => $today->format('Y-m-d')],
    ];
    if ($term !== null) {
        $termEnd = min((string) $term['ends_on'], $today->format('Y-m-d'));
        if ((string) $term['starts_on'] <= $termEnd) {
            $periods['term'] = [
                'label' => (string) $term['term_name'] . ' (' . (string) $term['academic_year'] . ')',
                'start' => (string) $term['starts_on'],
                'end' => $termEnd,
            ];
        }
        $schoolId ??= (int) ($term['school_id'] ?? 0);
        if ($schoolId > 0) {
            $yearStmt = db()->prepare(
                'SELECT MIN(starts_on) AS starts_on, MAX(ends_on) AS ends_on
                 FROM school_terms WHERE school_id = ? AND academic_year_id = ? AND starts_on <= ?'
            );
            $yearStmt->execute([$schoolId, (int) $term['academic_year_id'], $today->format('Y-m-d')]);
            $academicYear = $yearStmt->fetch();
            if ($academicYear && $academicYear['starts_on'] !== null) {
                $yearEnd = min((string) $academicYear['ends_on'], $today->format('Y-m-d'));
                if ((string) $academicYear['starts_on'] <= $yearEnd) {
                    $periods['year'] = [
                        'label' => 'Academic year ' . (string) $term['academic_year'],
                        'start' => (string) $academicYear['starts_on'],
                        'end' => $yearEnd,
                    ];
                }
            }
        }
    }
    return $periods;
}

function school_attendance_scheduled_dates(
    int $schoolId,
    array $periods,
    ?DateTimeImmutable $today = null
): array {
    if ($schoolId < 1 || $periods === []) {
        return [];
    }
    $today ??= new DateTimeImmutable('today');
    $validPeriods = array_filter($periods, static function (array $period) use ($today): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($period['start'] ?? '')) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($period['end'] ?? '')) === 1
            && (string) $period['start'] <= (string) $period['end']
            && (string) $period['start'] <= $today->format('Y-m-d');
    });
    if ($validPeriods === []) {
        return [];
    }
    $start = min(array_column($validPeriods, 'start'));
    $end = min(max(array_column($validPeriods, 'end')), $today->format('Y-m-d'));

    $termStmt = db()->prepare(
        'SELECT starts_on, ends_on FROM school_terms
         WHERE school_id = ? AND starts_on <= ? AND ends_on >= ?'
    );
    $termStmt->execute([$schoolId, $end, $start]);
    $terms = $termStmt->fetchAll();
    $hasTermsStmt = db()->prepare('SELECT 1 FROM school_terms WHERE school_id = ? LIMIT 1');
    $hasTermsStmt->execute([$schoolId]);
    $hasConfiguredTerms = (bool) $hasTermsStmt->fetchColumn();
    $calendarStmt = db()->prepare(
        'SELECT calendar_date, day_type FROM school_calendar_days
         WHERE school_id = ? AND calendar_date BETWEEN ? AND ?'
    );
    $calendarStmt->execute([$schoolId, $start, $end]);
    $calendarDays = [];
    foreach ($calendarStmt->fetchAll() as $calendarDay) {
        $calendarDays[(string) $calendarDay['calendar_date']] = (string) $calendarDay['day_type'];
    }
    $holidayEventStmt = db()->prepare(
        'SELECT starts_on, ends_on FROM school_calendar_events
         WHERE school_id = ? AND status = "published"
                     AND category IN ("holiday", "public_holiday", "school_closure")
                     AND audience = "school"
           AND starts_on <= ? AND ends_on >= ?'
    );
    $holidayEventStmt->execute([$schoolId, $end, $start]);
    $eventHolidayDates = [];
    foreach ($holidayEventStmt->fetchAll() as $holidayEvent) {
        $holidayStart = new DateTimeImmutable(max($start, (string) $holidayEvent['starts_on']));
        $holidayEnd = new DateTimeImmutable(min($end, (string) $holidayEvent['ends_on']));
        for ($holidayDate = $holidayStart; $holidayDate <= $holidayEnd; $holidayDate = $holidayDate->modify('+1 day')) {
            $eventHolidayDates[$holidayDate->format('Y-m-d')] = true;
        }
    }
    $weekdaysStmt = db()->prepare(
        'SELECT setting_value FROM school_settings
         WHERE school_id = ? AND setting_key = "attendance_working_weekdays" LIMIT 1'
    );
    $weekdaysStmt->execute([$schoolId]);
    $configuredWeekdays = $weekdaysStmt->fetchColumn();
    $workingWeekdays = [1, 2, 3, 4, 5];
    if (is_string($configuredWeekdays)) {
        $decodedWeekdays = json_decode($configuredWeekdays, true);
        if (is_array($decodedWeekdays)) {
            $validatedWeekdays = array_values(array_unique(array_filter(
                array_map('intval', $decodedWeekdays),
                static fn (int $weekday): bool => $weekday >= 1 && $weekday <= 7
            )));
            if ($validatedWeekdays !== []) {
                $workingWeekdays = $validatedWeekdays;
            }
        }
    }

    $scheduled = [];
    foreach ($validPeriods as $key => $period) {
        $periodEnd = min((string) $period['end'], $today->format('Y-m-d'));
        $date = new DateTimeImmutable((string) $period['start']);
        $lastDate = new DateTimeImmutable($periodEnd);
        $days = [];
        while ($date <= $lastDate) {
            $dateString = $date->format('Y-m-d');
            $inTerm = !$hasConfiguredTerms;
            if ($hasConfiguredTerms) {
                foreach ($terms as $term) {
                    if ($dateString >= (string) $term['starts_on'] && $dateString <= (string) $term['ends_on']) {
                        $inTerm = true;
                        break;
                    }
                }
            }
            $calendarType = $calendarDays[$dateString] ?? null;
            $isScheduledOverride = $calendarType === 'school_day';
            if ($inTerm
                && !in_array($calendarType, ['holiday', 'closure'], true)
                && !isset($eventHolidayDates[$dateString])
                && ($isScheduledOverride
                    || in_array((int) $date->format('N'), $workingWeekdays, true))) {
                $days[] = $dateString;
            }
            $date = $date->modify('+1 day');
        }
        $scheduled[$key] = $days;
    }
    return $scheduled;
}

function school_attendance_count_period(array $scheduledDates, array $records, bool $lateCountsAsPresent = false): array
{
    $counts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'late' => 0, 'unmarked' => 0];
    foreach ($scheduledDates as $date) {
        $record = $records[$date] ?? null;
        if (!is_array($record)) {
            $counts['unmarked']++;
            continue;
        }
        $status = ($record['legacy_status'] ?? null) === 'excused'
            ? 'excused'
            : (($record['legacy_status'] ?? null) === 'late' ? 'late' : (string) ($record['status'] ?? ''));
        if (!isset($counts[$status]) || $status === 'unmarked') {
            $counts['unmarked']++;
            continue;
        }
        $counts[$status]++;
    }
    $counts['scheduled_days'] = count($scheduledDates);
    $counts['recorded_days'] = $counts['scheduled_days'] - $counts['unmarked'];
    $counts['finalized_days'] = $counts['recorded_days'];
    $counts['attended'] = $counts['present'] + ($lateCountsAsPresent ? $counts['late'] : 0);
    $counts['incomplete'] = $counts['recorded_days'] < $counts['scheduled_days'];
    $percentageDenominator = $lateCountsAsPresent
        ? $counts['scheduled_days'] - $counts['excused']
        : $counts['scheduled_days'];
    $counts['percentage'] = $percentageDenominator <= 0 || $counts['incomplete']
        ? null
        : round(($counts['attended'] / $percentageDenominator) * 100, 2);
    return $counts;
}

function school_attendance_summaries(
    int $schoolId,
    array $studentIds,
    array $periods,
    ?DateTimeImmutable $today = null
): array {
    $studentIds = array_values(array_unique(array_filter(
        array_map('intval', $studentIds),
        static fn (int $id): bool => $id > 0
    )));
    $summaries = [];
    foreach ($studentIds as $studentId) {
        $summaries[$studentId] = [];
    }
    if ($schoolId < 1 || $studentIds === [] || $periods === []) {
        return $summaries;
    }

    $today ??= new DateTimeImmutable('today');
    $scheduledDates = school_attendance_scheduled_dates($schoolId, $periods, $today);
    $validPeriods = array_filter($periods, static fn (array $period, string|int $key): bool => isset($scheduledDates[$key]), ARRAY_FILTER_USE_BOTH);
    if ($validPeriods === []) {
        return $summaries;
    }
    $start = min(array_column($validPeriods, 'start'));
    $end = min(max(array_column($validPeriods, 'end')), $today->format('Y-m-d'));
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $attendanceStmt = db()->prepare(
        'SELECT student_id, attendance_date, status, legacy_status
         FROM student_attendance
         WHERE school_id = ? AND student_id IN (' . $placeholders . ')
           AND attendance_date BETWEEN ? AND ?'
    );
    $attendanceStmt->execute(array_merge([$schoolId], $studentIds, [$start, $end]));
    $records = [];
    foreach ($attendanceStmt->fetchAll() as $record) {
        $records[(int) $record['student_id']][(string) $record['attendance_date']] = [
            'status' => (string) $record['status'],
            'legacy_status' => $record['legacy_status'] === null ? null : (string) $record['legacy_status'],
        ];
    }

    foreach ($validPeriods as $key => $period) {
        $days = $scheduledDates[$key] ?? [];
        foreach ($studentIds as $studentId) {
            $summaries[$studentId][$key] = school_attendance_count_period(
                $days,
                $records[$studentId] ?? []
            );
        }
    }
    return $summaries;
}

function school_current_attendance_term(int $schoolId): ?array
{
    return school_academic_period_for_date($schoolId);
}

function school_staff_attendance_policy(int $schoolId): array
{
    $stmt = db()->prepare(
        'SELECT setting_key, setting_value FROM school_settings
         WHERE school_id = ? AND setting_key IN ("staff_reporting_time", "staff_late_grace_minutes")'
    );
    $stmt->execute([$schoolId]);
    $settings = [];
    foreach ($stmt->fetchAll() as $setting) {
        $settings[(string) $setting['setting_key']] = (string) $setting['setting_value'];
    }
    $reportingTime = $settings['staff_reporting_time'] ?? '08:00';
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $reportingTime) !== 1) {
        $reportingTime = '08:00';
    }
    $graceMinutes = filter_var($settings['staff_late_grace_minutes'] ?? '15', FILTER_VALIDATE_INT);
    if ($graceMinutes === false || $graceMinutes < 0 || $graceMinutes > 180) {
        $graceMinutes = 15;
    }
    return ['reporting_time' => $reportingTime, 'late_grace_minutes' => $graceMinutes];
}

<?php
declare(strict_types=1);

function school_staff_employee_number_review_reason(
    string $abbreviation,
    ?string $employeeNumber,
    ?string $appointedOn,
    bool $duplicate
): ?string {
    $abbreviation = strtoupper(trim($abbreviation));
    if (preg_match('/^[A-Z0-9]+$/D', $abbreviation) !== 1) {
        return 'School abbreviation needs review.';
    }
    $employeeNumber = trim((string) $employeeNumber);
    $appointedOn = trim((string) $appointedOn);
    if ($employeeNumber === '') {
        if ($appointedOn === '') {
            return 'Appointment date is missing.';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appointedOn);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d') !== $appointedOn
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            return 'Appointment date is invalid.';
        }
        if ($date > new DateTimeImmutable('today')) {
            return 'Appointment date is in the future.';
        }
        return 'Ready for number generation.';
    }
    if ($duplicate) {
        return 'Employee number is duplicated within this school.';
    }
    if (preg_match('/^' . preg_quote($abbreviation . 'T/', '/') . '([0-9]{2})([0-9]{4})$/D', $employeeNumber, $matches) !== 1) {
        return 'Existing employee number does not match the configured format.';
    }
    if ($appointedOn === '') {
        return 'Appointment date is missing; joining year cannot be verified.';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appointedOn);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $appointedOn
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        return 'Appointment date is invalid.';
    }
    if ($date > new DateTimeImmutable('today')) {
        return 'Appointment date is in the future.';
    }
    if ($date->format('y') !== $matches[1]) {
        return 'Employee-number year differs from the recorded appointment year.';
    }
    return null;
}

function school_staff_employee_number_backfill(int $schoolId, int $actorUserId, bool $dryRun = false): array
{
    if ($schoolId < 1 || $actorUserId < 1) {
        throw new InvalidArgumentException('A valid school and authorized actor are required to assign employee numbers.');
    }

    $pdo = db();
    $result = ['assigned' => 0, 'would_assign' => 0, 'already_valid' => 0, 'needs_review' => [], 'dry_run' => $dryRun];
    $pdo->beginTransaction();
    try {
        $schoolStmt = $pdo->prepare('SELECT id, short_name, admission_abbreviation FROM schools WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $schoolStmt->execute([$schoolId]);
        $school = $schoolStmt->fetch();
        if (!$school) {
            throw new RuntimeException('The school is unavailable.');
        }
        $abbreviation = strtoupper(trim((string) ($school['admission_abbreviation'] ?: $school['short_name'])));
        $validAbbreviation = preg_match('/^[A-Z0-9]+$/D', $abbreviation) === 1;
        $numberPattern = $validAbbreviation
            ? '/^' . preg_quote($abbreviation . 'T/', '/') . '([0-9]{2})([0-9]{4})$/D'
            : null;

                $seedStaffStmt = $pdo->prepare(
                        'INSERT IGNORE INTO school_staff (school_id, user_id, role_name, status)
                         SELECT membership.school_id, membership.user_id, MIN(membership.role_name), "active"
                         FROM school_memberships membership
                         JOIN users account ON account.id = membership.user_id
                             AND account.status = "active" AND account.email_verified_at IS NOT NULL
                         WHERE membership.school_id = ? AND membership.status = "active"
                             AND membership.role_name IN (
                                     "school_proprietor", "school_headmaster", "class_teacher",
                                     "subject_teacher", "school_accountant",
                                     "school_assistant_headmaster", "school_secretary"
                             )
                         GROUP BY membership.school_id, membership.user_id'
                );
                $seedStaffStmt->execute([$schoolId]);

        $allStaffStmt = $pdo->prepare(
            'SELECT id, employee_number FROM school_staff WHERE school_id = ? FOR UPDATE'
        );
        $allStaffStmt->execute([$schoolId]);
        $numberCounts = [];
        $nextSequenceByYear = [];
        foreach ($allStaffStmt->fetchAll() as $staffRow) {
            $existingNumber = trim((string) ($staffRow['employee_number'] ?? ''));
            if ($existingNumber === '') {
                continue;
            }
            $numberCounts[$existingNumber] = ($numberCounts[$existingNumber] ?? 0) + 1;
            if ($numberPattern !== null && preg_match($numberPattern, $existingNumber, $matches) === 1) {
                $yearSuffix = $matches[1];
                $nextSequenceByYear[$yearSuffix] = max(
                    $nextSequenceByYear[$yearSuffix] ?? 0,
                    (int) $matches[2]
                );
            }
        }

        $eligibleStaffStmt = $pdo->prepare(
            'SELECT staff.id, staff.user_id, staff.employee_number, staff.appointed_on
             FROM school_staff staff
                         JOIN users account ON account.id = staff.user_id
                             AND account.status = "active" AND account.email_verified_at IS NOT NULL
             WHERE staff.school_id = ? AND staff.status = "active"
               AND EXISTS (
                   SELECT 1 FROM school_memberships membership
                   WHERE membership.school_id = staff.school_id
                     AND membership.user_id = staff.user_id AND membership.status = "active"
                     AND membership.role_name IN (
                         "school_proprietor", "school_headmaster", "class_teacher",
                         "subject_teacher", "school_accountant",
                         "school_assistant_headmaster", "school_secretary"
                     )
               )
             ORDER BY staff.appointed_on, staff.id
             FOR UPDATE'
        );
        $eligibleStaffStmt->execute([$schoolId]);

        foreach ($eligibleStaffStmt->fetchAll() as $staffRow) {
            $staffId = (int) $staffRow['id'];
            $existingNumber = trim((string) ($staffRow['employee_number'] ?? ''));
            $appointmentDate = trim((string) ($staffRow['appointed_on'] ?? ''));

            if (!$validAbbreviation) {
                $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'School abbreviation is missing or contains unsupported characters.'];
                continue;
            }

            if ($existingNumber !== '') {
                if (($numberCounts[$existingNumber] ?? 0) > 1) {
                    $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Employee number is duplicated within this school; the existing value was preserved.'];
                    continue;
                }
                if (preg_match($numberPattern, $existingNumber, $matches) !== 1) {
                    $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Existing employee number does not match this school’s configured format; the existing value was preserved.'];
                    continue;
                }
                if ($appointmentDate === '') {
                    $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Appointment date is missing; the existing number was preserved but its joining year cannot be verified.'];
                    continue;
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appointmentDate);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if ($date === false || $date->format('Y-m-d') !== $appointmentDate
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                    $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Appointment date is invalid; the existing number was preserved.'];
                    continue;
                }
                if ($date > new DateTimeImmutable('today')) {
                    $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Appointment date is in the future; the existing number was preserved for review.'];
                    continue;
                }
                if ($date->format('y') !== $matches[1]) {
                    $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Employee-number year differs from the recorded appointment year; the existing value was preserved.'];
                    continue;
                }
                $result['already_valid']++;
                continue;
            }

            if ($appointmentDate === '') {
                $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Appointment date is missing; provide the actual date before generating a number.'];
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appointmentDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($date === false || $date->format('Y-m-d') !== $appointmentDate
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Appointment date is invalid; review the staff record.'];
                continue;
            }
            if ($date > new DateTimeImmutable('today')) {
                $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'Appointment date is in the future; no employee number was generated.'];
                continue;
            }

            $yearSuffix = $date->format('y');
            $sequence = ($nextSequenceByYear[$yearSuffix] ?? 0) + 1;
            if ($sequence > 9999) {
                $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'The four-digit sequence is exhausted for this school and joining year.'];
                continue;
            }
            $employeeNumber = $abbreviation . 'T/' . $yearSuffix . sprintf('%04d', $sequence);
            if (isset($numberCounts[$employeeNumber])) {
                $result['needs_review'][] = ['staff_id' => $staffId, 'reason' => 'The next employee number is already in use; review the existing number sequence.'];
                continue;
            }

            if ($dryRun) {
                $numberCounts[$employeeNumber] = 1;
                $nextSequenceByYear[$yearSuffix] = $sequence;
                $result['would_assign']++;
                continue;
            }
            $update = $pdo->prepare(
                'UPDATE school_staff SET employee_number = ?
                 WHERE id = ? AND school_id = ? AND (employee_number IS NULL OR TRIM(employee_number) = "")'
            );
            $update->execute([$employeeNumber, $staffId, $schoolId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('A staff record changed during number generation. Run the process again.');
            }
            $numberCounts[$employeeNumber] = 1;
            $nextSequenceByYear[$yearSuffix] = $sequence;
            $result['assigned']++;
            audit(
                $actorUserId,
                'school_employee_number_assigned',
                'school_id=' . $schoolId . ';staff_id=' . $staffId . ';employee_number=' . $employeeNumber
            );
        }

        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $result;
}
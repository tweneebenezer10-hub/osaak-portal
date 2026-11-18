<?php
declare(strict_types=1);

function school_timetable_schema_ready(): bool
{
    $requiredTables = [
        'school_timetable_rooms',
        'school_timetable_settings',
        'school_timetable_periods',
        'school_timetable_versions',
        'school_timetable_requirements',
        'school_timetable_unavailable_slots',
        'school_timetable_edit_history',
        'school_timetable_substitutions',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $tables = db()->prepare(
        'SELECT COUNT(DISTINCT table_name) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')'
    );
    $tables->execute($requiredTables);
    if ((int) $tables->fetchColumn() !== count($requiredTables)) {
        return false;
    }
    $columns = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = "school_timetable_entries"
           AND column_name IN ("version_id", "period_id", "teacher_user_id", "room_id", "double_period_group")'
    );
    $columns->execute();
    return (int) $columns->fetchColumn() === 5;
}

function school_timetable_time_to_minutes(string $time): ?int
{
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $time, $parts) !== 1) {
        return null;
    }
    return ((int) $parts[1] * 60) + (int) $parts[2];
}

function school_timetable_minutes_to_time(int $minutes): string
{
    if ($minutes < 0 || $minutes > 1439) {
        throw new InvalidArgumentException('Timetable periods must remain within the same day.');
    }
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

function school_timetable_build_periods(array $settings): array
{
    $weekdays = $settings['weekdays'] ?? null;
    if (is_string($weekdays)) {
        $decoded = json_decode($weekdays, true);
        $weekdays = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($weekdays)) {
        throw new InvalidArgumentException('Choose at least one valid school day.');
    }
    $weekdays = array_values(array_unique(array_filter(
        array_map('intval', $weekdays),
        static fn(int $weekday): bool => $weekday >= 1 && $weekday <= 7
    )));
    sort($weekdays);
    if ($weekdays === []) {
        throw new InvalidArgumentException('Choose at least one valid school day.');
    }

    $start = school_timetable_time_to_minutes((string) ($settings['starts_at'] ?? ''));
    $lessonMinutes = filter_var($settings['lesson_minutes'] ?? null, FILTER_VALIDATE_INT);
    $lessonCount = filter_var($settings['lesson_count'] ?? null, FILTER_VALIDATE_INT);
    if ($start === null || $lessonMinutes === false || $lessonMinutes < 15 || $lessonMinutes > 180
        || $lessonCount === false || $lessonCount < 1 || $lessonCount > 20) {
        throw new InvalidArgumentException('Enter a valid start time, lesson duration, and number of periods.');
    }

    $events = [];
    foreach ([
        'assembly' => ['after' => 'assembly_after_period', 'minutes' => 'assembly_minutes'],
        'break' => ['after' => 'break_after_period', 'minutes' => 'break_minutes'],
        'lunch' => ['after' => 'lunch_after_period', 'minutes' => 'lunch_minutes'],
    ] as $kind => $fields) {
        $after = $settings[$fields['after']] ?? null;
        $duration = filter_var($settings[$fields['minutes']] ?? 0, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 0 || $duration > 180) {
            throw new InvalidArgumentException('Break, lunch, and assembly durations must be between 0 and 180 minutes.');
        }
        if ($after === '' || $after === null) {
            if ($duration > 0) {
                throw new InvalidArgumentException('Choose the period after which each non-teaching block occurs.');
            }
            continue;
        }
        $after = filter_var($after, FILTER_VALIDATE_INT);
        if ($after === false || $after < 1 || $after >= $lessonCount || $duration < 5) {
            throw new InvalidArgumentException('Choose a valid period and duration for each non-teaching block.');
        }
        $events[(int) $after][] = [
            'kind' => $kind,
            'minutes' => $duration,
            'label' => ucfirst($kind),
        ];
    }

    $periods = [];
    foreach ($weekdays as $weekday) {
        $cursor = $start;
        $slotNumber = 0;
        for ($periodNumber = 1; $periodNumber <= $lessonCount; $periodNumber++) {
            $slotNumber++;
            $periodEnd = $cursor + $lessonMinutes;
            $periods[] = [
                'weekday' => $weekday,
                'slot_number' => $slotNumber,
                'period_number' => $periodNumber,
                'label' => 'Period ' . $periodNumber,
                'kind' => 'lesson',
                'starts_at' => school_timetable_minutes_to_time($cursor),
                'ends_at' => school_timetable_minutes_to_time($periodEnd),
            ];
            $cursor = $periodEnd;
            foreach ($events[$periodNumber] ?? [] as $event) {
                $slotNumber++;
                $eventEnd = $cursor + $event['minutes'];
                $periods[] = [
                    'weekday' => $weekday,
                    'slot_number' => $slotNumber,
                    'period_number' => null,
                    'label' => $event['label'],
                    'kind' => $event['kind'],
                    'starts_at' => school_timetable_minutes_to_time($cursor),
                    'ends_at' => school_timetable_minutes_to_time($eventEnd),
                ];
                $cursor = $eventEnd;
            }
        }
    }
    return $periods;
}

function school_timetable_validate_entries(array $entries, array $limits = []): array
{
    $errors = [];
    $seenErrors = [];
    $teacherDayLoads = [];
    $teacherWeekLoads = [];
    $classDayLoads = [];
    $unavailable = [];
    foreach (($limits['unavailable_slots'] ?? []) as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $key = implode(':', [
            (string) ($slot['entity_type'] ?? ''),
            (int) ($slot['entity_id'] ?? 0),
            (int) ($slot['weekday'] ?? 0),
            (int) ($slot['period_number'] ?? 0),
        ]);
        $unavailable[$key] = true;
    }
    $addError = static function (string $code, string $message, array $indexes = []) use (&$errors, &$seenErrors): void {
        sort($indexes);
        $key = $code . ':' . implode(',', $indexes);
        if (!isset($seenErrors[$key])) {
            $seenErrors[$key] = true;
            $errors[] = ['code' => $code, 'message' => $message, 'entries' => $indexes];
        }
    };

    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            $addError('invalid_entry', 'A timetable entry has invalid data.', [(int) $index]);
            continue;
        }
        $weekday = filter_var($entry['weekday'] ?? null, FILTER_VALIDATE_INT);
        $start = school_timetable_time_to_minutes((string) ($entry['starts_at'] ?? ''));
        $end = school_timetable_time_to_minutes((string) ($entry['ends_at'] ?? ''));
        if ($weekday === false || $weekday < 1 || $weekday > 7 || $start === null || $end === null || $end <= $start
            || (int) ($entry['class_id'] ?? 0) < 1 || (int) ($entry['subject_id'] ?? 0) < 1
            || (int) ($entry['teacher_user_id'] ?? 0) < 1 || (int) ($entry['room_id'] ?? 0) < 1) {
            $addError('invalid_entry', 'Every lesson needs a valid class, subject, assigned teacher, room, day, and time.', [(int) $index]);
            continue;
        }
        if (($entry['teacher_is_assigned'] ?? true) !== true) {
            $addError('teacher_subject_assignment', 'The selected teacher is not assigned to teach this subject in the class.', [(int) $index]);
        }
        $periodNumber = (int) ($entry['period_number'] ?? 0);
        if ($periodNumber > 0) {
            foreach ([
                'class' => (int) $entry['class_id'],
                'teacher' => (int) $entry['teacher_user_id'],
                'room' => (int) $entry['room_id'],
            ] as $entityType => $entityId) {
                $blockedKey = implode(':', [$entityType, $entityId, (int) $weekday, $periodNumber]);
                if (isset($unavailable[$blockedKey])) {
                    $addError('unavailable_slot', 'A class, teacher, or room is unavailable during a scheduled lesson.', [(int) $index]);
                }
            }
        }
        $teacherDayKey = $entry['teacher_user_id'] . ':' . $weekday;
        $teacherDayLoads[$teacherDayKey] = ($teacherDayLoads[$teacherDayKey] ?? 0) + 1;
        $teacherWeekLoads[(string) $entry['teacher_user_id']] = ($teacherWeekLoads[(string) $entry['teacher_user_id']] ?? 0) + 1;
        $classDayKey = $entry['class_id'] . ':' . $weekday;
        $classDayLoads[$classDayKey] = ($classDayLoads[$classDayKey] ?? 0) + 1;
    }

    for ($left = 0, $count = count($entries); $left < $count; $left++) {
        if (!is_array($entries[$left] ?? null)) {
            continue;
        }
        $leftStart = school_timetable_time_to_minutes((string) ($entries[$left]['starts_at'] ?? ''));
        $leftEnd = school_timetable_time_to_minutes((string) ($entries[$left]['ends_at'] ?? ''));
        if ($leftStart === null || $leftEnd === null) {
            continue;
        }
        for ($right = $left + 1; $right < $count; $right++) {
            if (!is_array($entries[$right] ?? null)
                || (int) ($entries[$left]['weekday'] ?? 0) !== (int) ($entries[$right]['weekday'] ?? 0)) {
                continue;
            }
            $rightStart = school_timetable_time_to_minutes((string) ($entries[$right]['starts_at'] ?? ''));
            $rightEnd = school_timetable_time_to_minutes((string) ($entries[$right]['ends_at'] ?? ''));
            if ($rightStart === null || $rightEnd === null || $leftStart >= $rightEnd || $rightStart >= $leftEnd) {
                continue;
            }
            foreach ([
                ['class_id', 'class_conflict', 'A class cannot have overlapping lessons.'],
                ['teacher_user_id', 'teacher_conflict', 'A teacher cannot teach overlapping lessons.'],
                ['room_id', 'room_conflict', 'A room cannot host overlapping lessons.'],
            ] as [$field, $code, $message]) {
                $resourceId = (int) ($entries[$left][$field] ?? 0);
                if ($resourceId > 0 && $resourceId === (int) ($entries[$right][$field] ?? 0)) {
                    $addError($code, $message, [$left, $right]);
                }
            }
        }
    }

    $teacherDayLimit = max(1, (int) ($limits['max_periods_per_teacher_day'] ?? 6));
    $teacherWeekLimit = max(1, (int) ($limits['max_periods_per_teacher_week'] ?? 30));
    $classDayLimit = max(1, (int) ($limits['max_periods_per_class_day'] ?? 8));
    foreach ($teacherDayLoads as $key => $load) {
        if ($load > $teacherDayLimit) {
            $addError('teacher_daily_limit', 'A teacher exceeds the maximum periods allowed in a day.', []);
        }
    }
    foreach ($teacherWeekLoads as $load) {
        if ($load > $teacherWeekLimit) {
            $addError('teacher_weekly_limit', 'A teacher exceeds the maximum periods allowed in a week.', []);
        }
    }
    foreach ($classDayLoads as $load) {
        if ($load > $classDayLimit) {
            $addError('class_daily_limit', 'A class exceeds the maximum periods allowed in a day.', []);
        }
    }

    $doubleGroups = [];
    foreach ($entries as $index => $entry) {
        $group = is_array($entry) ? trim((string) ($entry['double_period_group'] ?? '')) : '';
        if ($group !== '') {
            $doubleGroups[$group][] = (int) $index;
        }
    }
    foreach ($doubleGroups as $indexes) {
        if (count($indexes) !== 2) {
            $addError('invalid_double_period', 'A double period must contain exactly two lessons.', $indexes);
            continue;
        }
        [$firstIndex, $secondIndex] = $indexes;
        $first = $entries[$firstIndex];
        $second = $entries[$secondIndex];
        $sameLesson = (int) $first['class_id'] === (int) $second['class_id']
            && (int) $first['subject_id'] === (int) $second['subject_id']
            && (int) $first['teacher_user_id'] === (int) $second['teacher_user_id']
            && (int) $first['room_id'] === (int) $second['room_id']
            && (int) $first['weekday'] === (int) $second['weekday'];
        $firstStart = school_timetable_time_to_minutes((string) $first['starts_at']);
        $firstEnd = school_timetable_time_to_minutes((string) $first['ends_at']);
        $secondStart = school_timetable_time_to_minutes((string) $second['starts_at']);
        $secondEnd = school_timetable_time_to_minutes((string) $second['ends_at']);
        $adjacentPeriods = (int) ($second['period_number'] ?? 0) === (int) ($first['period_number'] ?? 0) + 1
            || (int) ($first['period_number'] ?? 0) === (int) ($second['period_number'] ?? 0) + 1;
        $contiguousTimes = $firstEnd === $secondStart || $secondEnd === $firstStart;
        if (!$sameLesson || !$adjacentPeriods || !$contiguousTimes) {
            $addError('invalid_double_period', 'Double periods must remain together in consecutive periods with the same class, teacher, and room.', $indexes);
        }
    }
    return $errors;
}

function school_timetable_generate_schedule(
    array $requirements,
    array $periods,
    array $rooms,
    array $classSizes,
    array $unavailableSlots = [],
    array $limits = []
): array {
    $slotsByDay = [];
    foreach ($periods as $period) {
        if (!is_array($period) || (string) ($period['kind'] ?? 'lesson') !== 'lesson') {
            continue;
        }
        $weekday = filter_var($period['weekday'] ?? null, FILTER_VALIDATE_INT);
        $periodNumber = filter_var($period['period_number'] ?? null, FILTER_VALIDATE_INT);
        $start = school_timetable_time_to_minutes((string) ($period['starts_at'] ?? ''));
        $end = school_timetable_time_to_minutes((string) ($period['ends_at'] ?? ''));
        if ($weekday === false || $weekday < 1 || $weekday > 7 || $periodNumber === false || $periodNumber < 1
            || $start === null || $end === null || $end <= $start) {
            return ['ok' => false, 'entries' => [], 'errors' => [['code' => 'invalid_period', 'message' => 'The configured lesson periods are invalid.']], 'nodes' => 0];
        }
        $period['weekday'] = (int) $weekday;
        $period['period_number'] = (int) $periodNumber;
        $slotsByDay[(int) $weekday][] = $period;
    }
    foreach ($slotsByDay as &$daySlots) {
        usort($daySlots, static fn(array $left, array $right): int => (int) $left['period_number'] <=> (int) $right['period_number']);
        for ($index = 1, $length = count($daySlots); $index < $length; $index++) {
            if ((int) $daySlots[$index]['period_number'] === (int) $daySlots[$index - 1]['period_number']
                || school_timetable_time_to_minutes((string) $daySlots[$index]['starts_at'])
                    < school_timetable_time_to_minutes((string) $daySlots[$index - 1]['ends_at'])) {
                return ['ok' => false, 'entries' => [], 'errors' => [['code' => 'overlapping_periods', 'message' => 'Configured lesson periods overlap or repeat a period number.']], 'nodes' => 0];
            }
        }
    }
    unset($daySlots);
    if ($slotsByDay === []) {
        return ['ok' => false, 'entries' => [], 'errors' => [['code' => 'no_periods', 'message' => 'Save at least one lesson period before generating a timetable.']], 'nodes' => 0];
    }

    $roomById = [];
    foreach ($rooms as $room) {
        if (is_array($room) && (int) ($room['id'] ?? 0) > 0 && (int) ($room['capacity'] ?? 0) > 0) {
            $roomById[(int) $room['id']] = $room;
        }
    }
    if ($roomById === []) {
        return ['ok' => false, 'entries' => [], 'errors' => [['code' => 'no_rooms', 'message' => 'Add an active classroom with a capacity before generating a timetable.']], 'nodes' => 0];
    }

    $unavailable = [];
    foreach ($unavailableSlots as $slot) {
        if (is_array($slot)) {
            $key = implode(':', [
                (string) ($slot['entity_type'] ?? ''),
                (int) ($slot['entity_id'] ?? 0),
                (int) ($slot['weekday'] ?? 0),
                (int) ($slot['period_number'] ?? 0),
            ]);
            $unavailable[$key] = true;
        }
    }

    $tasks = [];
    $errors = [];
    $teacherLoads = [];
    $classLoads = [];
    foreach ($requirements as $index => $requirement) {
        if (!is_array($requirement)) {
            $errors[] = ['code' => 'invalid_requirement', 'message' => 'A timetable requirement is invalid.'];
            continue;
        }
        $classId = (int) ($requirement['class_id'] ?? 0);
        $subjectId = (int) ($requirement['subject_id'] ?? 0);
        $teacherId = (int) ($requirement['teacher_user_id'] ?? 0);
        $periodCount = filter_var($requirement['periods_per_week'] ?? null, FILTER_VALIDATE_INT);
        $doubleCount = filter_var($requirement['double_periods'] ?? 0, FILTER_VALIDATE_INT);
        if ($classId < 1 || $subjectId < 1 || $teacherId < 1 || $periodCount === false
            || $periodCount < 1 || $periodCount > 50 || $doubleCount === false
            || $doubleCount < 0 || $doubleCount > 25 || $doubleCount * 2 > $periodCount) {
            $errors[] = ['code' => 'invalid_requirement', 'message' => 'A subject needs a valid class, assigned teacher, and feasible weekly period count.'];
            continue;
        }
        if (($requirement['teacher_is_assigned'] ?? true) !== true) {
            $errors[] = ['code' => 'teacher_subject_assignment', 'message' => 'A selected teacher is not assigned to the required class and subject.'];
            continue;
        }
        $classSize = max(0, (int) ($classSizes[$classId] ?? 0));
        $eligibleRooms = [];
        foreach ($roomById as $roomId => $room) {
            if ((int) $room['capacity'] < $classSize) {
                continue;
            }
            $roomType = trim((string) ($requirement['room_type'] ?? ''));
            if ($roomType !== '' && strcasecmp($roomType, trim((string) ($room['room_type'] ?? ''))) !== 0) {
                continue;
            }
            $eligibleRooms[] = (int) $roomId;
        }
        $preferredRoom = (int) ($requirement['preferred_room_id'] ?? 0);
        usort($eligibleRooms, static function (int $left, int $right) use ($preferredRoom): int {
            if ($left === $preferredRoom) {
                return -1;
            }
            if ($right === $preferredRoom) {
                return 1;
            }
            return $left <=> $right;
        });
        if ($eligibleRooms === []) {
            $errors[] = [
                'code' => 'no_suitable_room',
                'message' => 'No active room can hold the class for one of its required subjects.',
                'class_id' => $classId,
                'subject_id' => $subjectId,
            ];
            continue;
        }

        $teacherLoads[$teacherId] = ($teacherLoads[$teacherId] ?? 0) + (int) $periodCount;
        $classLoads[$classId] = ($classLoads[$classId] ?? 0) + (int) $periodCount;
        $blockNumber = 0;
        for ($doubleIndex = 0; $doubleIndex < $doubleCount; $doubleIndex++) {
            $blockNumber++;
            $tasks[] = school_timetable_build_task(
                $requirement,
                $eligibleRooms,
                $slotsByDay,
                $unavailable,
                $classId,
                $subjectId,
                $teacherId,
                2,
                'r' . ((int) ($requirement['id'] ?? $index + 1)) . 'd' . $blockNumber
            );
        }
        for ($singleIndex = 0; $singleIndex < $periodCount - ($doubleCount * 2); $singleIndex++) {
            $tasks[] = school_timetable_build_task(
                $requirement,
                $eligibleRooms,
                $slotsByDay,
                $unavailable,
                $classId,
                $subjectId,
                $teacherId,
                1,
                ''
            );
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'entries' => [], 'errors' => $errors, 'nodes' => 0];
    }

    $teacherWeekLimit = max(1, (int) ($limits['max_periods_per_teacher_week'] ?? 30));
    foreach ($teacherLoads as $teacherId => $load) {
        if ($load > $teacherWeekLimit) {
            $errors[] = ['code' => 'teacher_weekly_limit', 'message' => 'Required lessons exceed a teacher weekly period limit.', 'teacher_user_id' => $teacherId];
        }
    }
    $classDayLimit = max(1, (int) ($limits['max_periods_per_class_day'] ?? 8));
    foreach ($classLoads as $classId => $load) {
        if ($load > count($slotsByDay) * $classDayLimit) {
            $errors[] = ['code' => 'class_weekly_capacity', 'message' => 'Required lessons exceed the available class periods for the week.', 'class_id' => $classId];
        }
    }
    foreach ($tasks as $task) {
        if ($task['candidates'] === []) {
            $errors[] = [
                'code' => 'no_available_period',
                'message' => 'A subject has no available period placement under the current availability rules.',
                'class_id' => $task['class_id'],
                'subject_id' => $task['subject_id'],
            ];
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'entries' => [], 'errors' => $errors, 'nodes' => 0];
    }
    if (count($tasks) > 400) {
        return ['ok' => false, 'entries' => [], 'errors' => [['code' => 'schedule_too_large', 'message' => 'This schedule has too many lesson blocks for a single generation run. Reduce the workload or generate fewer classes at a time.']], 'nodes' => 0];
    }

    usort($tasks, static function (array $left, array $right): int {
        $candidateOrder = count($left['candidates']) <=> count($right['candidates']);
        if ($candidateOrder !== 0) {
            return $candidateOrder;
        }
        $blockOrder = $right['period_count'] <=> $left['period_count'];
        if ($blockOrder !== 0) {
            return $blockOrder;
        }
        return [$left['class_id'], $left['subject_id'], $left['teacher_user_id']]
            <=> [$right['class_id'], $right['subject_id'], $right['teacher_user_id']];
    });

    $state = [
        'class' => [],
        'teacher' => [],
        'room' => [],
        'class_day' => [],
        'teacher_day' => [],
        'class_day_slots' => [],
        'teacher_day_slots' => [],
        'teacher_week' => [],
        'subject_day' => [],
        'placed' => [],
    ];
    $nodes = 0;
    $nodeLimit = max(100, min(50000, (int) ($limits['node_limit'] ?? 20000)));
    $teacherDayLimit = max(1, (int) ($limits['max_periods_per_teacher_day'] ?? 6));
    $generated = [];
    $search = null;
    $search = static function (int $taskIndex) use (
        &$search,
        &$nodes,
        $nodeLimit,
        $tasks,
        &$state,
        &$generated,
        $teacherDayLimit,
        $teacherWeekLimit,
        $classDayLimit,
        $slotsByDay
    ): bool {
        if ($taskIndex >= count($tasks)) {
            return true;
        }
        if ($nodes >= $nodeLimit) {
            return false;
        }
        $task = $tasks[$taskIndex];
        $choices = [];
        foreach ($task['candidates'] as $candidate) {
            $available = true;
            foreach ($candidate['periods'] as $period) {
                $weekday = (int) $period['weekday'];
                $periodNumber = (int) $period['period_number'];
                $classKey = $task['class_id'] . ':' . $weekday . ':' . $periodNumber;
                $teacherKey = $task['teacher_user_id'] . ':' . $weekday . ':' . $periodNumber;
                $roomKey = $candidate['room_id'] . ':' . $weekday . ':' . $periodNumber;
                if (isset($state['class'][$classKey]) || isset($state['teacher'][$teacherKey]) || isset($state['room'][$roomKey])) {
                    $available = false;
                    break;
                }
            }
            if (!$available) {
                continue;
            }
            $weekday = (int) $candidate['periods'][0]['weekday'];
            $classDayKey = $task['class_id'] . ':' . $weekday;
            $teacherDayKey = $task['teacher_user_id'] . ':' . $weekday;
            $teacherWeekKey = (string) $task['teacher_user_id'];
            $newClassLoad = (int) ($state['class_day'][$classDayKey] ?? 0) + $task['period_count'];
            $newTeacherDayLoad = (int) ($state['teacher_day'][$teacherDayKey] ?? 0) + $task['period_count'];
            $newTeacherWeekLoad = (int) ($state['teacher_week'][$teacherWeekKey] ?? 0) + $task['period_count'];
            if ($newClassLoad > $classDayLimit || $newTeacherDayLoad > $teacherDayLimit
                || $newTeacherWeekLoad > $teacherWeekLimit) {
                continue;
            }
            $subjectDayKey = $task['class_id'] . ':' . $task['subject_id'] . ':' . $weekday;
            $score = ((int) ($state['class_day'][$classDayKey] ?? 0) ** 2) * 2
                + ((int) ($state['teacher_day'][$teacherDayKey] ?? 0) ** 2) * 3
                + ((int) ($state['subject_day'][$subjectDayKey] ?? 0) * 40);
            foreach ([
                [$state['class_day_slots'][$classDayKey] ?? [], $candidate['periods']],
                [$state['teacher_day_slots'][$teacherDayKey] ?? [], $candidate['periods']],
            ] as [$existingSlots, $addedSlots]) {
                $periodNumbers = array_map('intval', array_keys($existingSlots));
                foreach ($addedSlots as $addedSlot) {
                    $periodNumbers[] = (int) $addedSlot['period_number'];
                }
                $periodNumbers = array_values(array_unique($periodNumbers));
                sort($periodNumbers);
                $gaps = $periodNumbers === []
                    ? 0
                    : ($periodNumbers[count($periodNumbers) - 1] - $periodNumbers[0] + 1 - count($periodNumbers));
                $score += $gaps * 12;
            }
            foreach ($candidate['periods'] as $period) {
                $periodNumber = (int) $period['period_number'];
                foreach ([$periodNumber - 1, $periodNumber + 1] as $neighbor) {
                    $neighborKey = $task['class_id'] . ':' . $weekday . ':' . $neighbor;
                    $neighborSubject = $state['class'][$neighborKey]['subject_id'] ?? null;
                    if ($neighborSubject !== null && (int) $neighborSubject === $task['subject_id']) {
                        $score += 60;
                    }
                }
            }
            if ((int) ($task['preferred_room_id'] ?? 0) > 0
                && (int) $candidate['room_id'] !== (int) $task['preferred_room_id']) {
                $score += 25;
            }
            $candidate['score'] = $score;
            $choices[] = $candidate;
        }
        usort($choices, static function (array $left, array $right): int {
            return [$left['score'], $left['weekday'], $left['first_period'], $left['room_id']]
                <=> [$right['score'], $right['weekday'], $right['first_period'], $right['room_id']];
        });
        foreach ($choices as $candidate) {
            if (++$nodes > $nodeLimit) {
                return false;
            }
            $weekday = (int) $candidate['weekday'];
            $classDayKey = $task['class_id'] . ':' . $weekday;
            $teacherDayKey = $task['teacher_user_id'] . ':' . $weekday;
            $teacherWeekKey = (string) $task['teacher_user_id'];
            $subjectDayKey = $task['class_id'] . ':' . $task['subject_id'] . ':' . $weekday;
            foreach ($candidate['periods'] as $period) {
                $periodNumber = (int) $period['period_number'];
                $classKey = $task['class_id'] . ':' . $weekday . ':' . $periodNumber;
                $teacherKey = $task['teacher_user_id'] . ':' . $weekday . ':' . $periodNumber;
                $roomKey = $candidate['room_id'] . ':' . $weekday . ':' . $periodNumber;
                $state['class'][$classKey] = ['subject_id' => $task['subject_id']];
                $state['teacher'][$teacherKey] = true;
                $state['room'][$roomKey] = true;
                $state['class_day_slots'][$classDayKey][$periodNumber] = true;
                $state['teacher_day_slots'][$teacherDayKey][$periodNumber] = true;
                $generated[] = [
                    'class_id' => $task['class_id'],
                    'subject_id' => $task['subject_id'],
                    'teacher_user_id' => $task['teacher_user_id'],
                    'room_id' => (int) $candidate['room_id'],
                    'weekday' => $weekday,
                    'period_number' => $periodNumber,
                    'starts_at' => (string) $period['starts_at'],
                    'ends_at' => (string) $period['ends_at'],
                    'double_period_group' => $task['double_period_group'],
                ];
            }
            $state['class_day'][$classDayKey] = (int) ($state['class_day'][$classDayKey] ?? 0) + $task['period_count'];
            $state['teacher_day'][$teacherDayKey] = (int) ($state['teacher_day'][$teacherDayKey] ?? 0) + $task['period_count'];
            $state['teacher_week'][$teacherWeekKey] = (int) ($state['teacher_week'][$teacherWeekKey] ?? 0) + $task['period_count'];
            $state['subject_day'][$subjectDayKey] = (int) ($state['subject_day'][$subjectDayKey] ?? 0) + $task['period_count'];
            if ($search($taskIndex + 1)) {
                return true;
            }
            array_splice($generated, -$task['period_count']);
            foreach ($candidate['periods'] as $period) {
                $periodNumber = (int) $period['period_number'];
                unset(
                    $state['class'][$task['class_id'] . ':' . $weekday . ':' . $periodNumber],
                    $state['teacher'][$task['teacher_user_id'] . ':' . $weekday . ':' . $periodNumber],
                    $state['room'][$candidate['room_id'] . ':' . $weekday . ':' . $periodNumber]
                );
                unset($state['class_day_slots'][$classDayKey][$periodNumber]);
                unset($state['teacher_day_slots'][$teacherDayKey][$periodNumber]);
            }
            if (($state['class_day_slots'][$classDayKey] ?? []) === []) {
                unset($state['class_day_slots'][$classDayKey]);
            }
            if (($state['teacher_day_slots'][$teacherDayKey] ?? []) === []) {
                unset($state['teacher_day_slots'][$teacherDayKey]);
            }
            $state['class_day'][$classDayKey] -= $task['period_count'];
            $state['teacher_day'][$teacherDayKey] -= $task['period_count'];
            $state['teacher_week'][$teacherWeekKey] -= $task['period_count'];
            $state['subject_day'][$subjectDayKey] -= $task['period_count'];
        }
        return false;
    };

    $solved = $search(0);
    if (!$solved) {
        $limited = $nodes >= $nodeLimit;
        return [
            'ok' => false,
            'entries' => [],
            'errors' => [[
                'code' => $limited ? 'search_limit' : 'unsatisfiable',
                'message' => $limited
                    ? 'The scheduling search reached its safe work limit. Reduce the workload or relax constraints, then retry.'
                    : 'No valid schedule satisfies all class, teacher, room, availability, and workload constraints.',
            ]],
            'nodes' => $nodes,
        ];
    }
    usort($generated, static function (array $left, array $right): int {
        return [$left['class_id'], $left['weekday'], $left['starts_at'], $left['period_number']]
            <=> [$right['class_id'], $right['weekday'], $right['starts_at'], $right['period_number']];
    });
    $validationErrors = school_timetable_validate_entries($generated, array_merge($limits, ['unavailable_slots' => $unavailableSlots]));
    if ($validationErrors !== []) {
        return ['ok' => false, 'entries' => [], 'errors' => $validationErrors, 'nodes' => $nodes];
    }
    return ['ok' => true, 'entries' => $generated, 'errors' => [], 'nodes' => $nodes];
}

function school_timetable_build_task(
    array $requirement,
    array $roomIds,
    array $slotsByDay,
    array $unavailable,
    int $classId,
    int $subjectId,
    int $teacherId,
    int $periodCount,
    string $doubleGroup
): array {
    $candidates = [];
    foreach ($slotsByDay as $weekday => $daySlots) {
        for ($index = 0, $length = count($daySlots); $index < $length; $index++) {
            $selected = [$daySlots[$index]];
            if ($periodCount === 2) {
                if (!isset($daySlots[$index + 1])
                    || (int) $daySlots[$index + 1]['period_number'] !== (int) $daySlots[$index]['period_number'] + 1
                    || school_timetable_time_to_minutes((string) $daySlots[$index]['ends_at'])
                        !== school_timetable_time_to_minutes((string) $daySlots[$index + 1]['starts_at'])) {
                    continue;
                }
                $selected[] = $daySlots[$index + 1];
            }
            $blocked = false;
            foreach ($selected as $period) {
                $periodNumber = (int) $period['period_number'];
                foreach ([
                    'class' => $classId,
                    'teacher' => $teacherId,
                ] as $entityType => $entityId) {
                    if (isset($unavailable[$entityType . ':' . $entityId . ':' . $weekday . ':' . $periodNumber])) {
                        $blocked = true;
                        break 2;
                    }
                }
            }
            if ($blocked) {
                continue;
            }
            foreach ($roomIds as $roomId) {
                foreach ($selected as $period) {
                    $roomSlotKey = 'room:' . $roomId . ':' . $weekday . ':' . (int) $period['period_number'];
                    if (isset($unavailable[$roomSlotKey])) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    $blocked = false;
                    continue;
                }
                $candidates[] = [
                    'weekday' => (int) $weekday,
                    'first_period' => (int) $selected[0]['period_number'],
                    'periods' => $selected,
                    'room_id' => (int) $roomId,
                ];
            }
        }
    }
    return [
        'class_id' => $classId,
        'subject_id' => $subjectId,
        'teacher_user_id' => $teacherId,
        'period_count' => $periodCount,
        'double_period_group' => $doubleGroup,
        'preferred_room_id' => (int) ($requirement['preferred_room_id'] ?? 0),
        'candidates' => $candidates,
    ];
}

function school_timetable_entry_snapshot(PDO $pdo, int $schoolId, int $versionId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, class_id, subject_id, period_id, teacher_user_id, room, room_id,
                weekday, starts_at, ends_at, double_period_group
         FROM school_timetable_entries
         WHERE school_id = ? AND version_id = ?
         ORDER BY id'
    );
    $stmt->execute([$schoolId, $versionId]);
    return $stmt->fetchAll();
}

function school_timetable_record_edit(PDO $pdo, int $schoolId, int $versionId, int $userId, string $action, array $before, array $after): int
{
    $revisionStmt = $pdo->prepare(
        'SELECT current_revision FROM school_timetable_versions
         WHERE id = ? AND school_id = ? AND status = "draft" FOR UPDATE'
    );
    $revisionStmt->execute([$versionId, $schoolId]);
    $currentRevision = $revisionStmt->fetchColumn();
    if ($currentRevision === false) {
        throw new RuntimeException('Only a draft timetable can be edited.');
    }
    $nextRevision = (int) $currentRevision + 1;
    $deleteFuture = $pdo->prepare(
        'DELETE FROM school_timetable_edit_history WHERE school_id = ? AND version_id = ? AND revision_number > ?'
    );
    $deleteFuture->execute([$schoolId, $versionId, (int) $currentRevision]);
    $history = $pdo->prepare(
        'INSERT INTO school_timetable_edit_history
         (school_id, version_id, revision_number, action_name, snapshot_json, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $history->execute([
        $schoolId,
        $versionId,
        $nextRevision,
        $action,
        json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $userId,
    ]);
    $update = $pdo->prepare(
        'UPDATE school_timetable_versions SET current_revision = ?
         WHERE id = ? AND school_id = ? AND status = "draft"'
    );
    $update->execute([$nextRevision, $versionId, $schoolId]);
    return $nextRevision;
}

function school_timetable_restore_entry_snapshot(PDO $pdo, int $schoolId, int $versionId, array $snapshot): void
{
    $update = $pdo->prepare(
        'UPDATE school_timetable_entries
         SET class_id = ?, subject_id = ?, period_id = ?, teacher_user_id = ?,
             room = ?, room_id = ?, weekday = ?, starts_at = ?, ends_at = ?,
             double_period_group = ?
         WHERE id = ? AND school_id = ? AND version_id = ?'
    );
    foreach ($snapshot as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('Timetable edit history contains invalid data.');
        }
        $update->execute([
            (int) $entry['class_id'],
            (int) $entry['subject_id'],
            $entry['period_id'] === null ? null : (int) $entry['period_id'],
            $entry['teacher_user_id'] === null ? null : (int) $entry['teacher_user_id'],
            $entry['room'] === null ? null : (string) $entry['room'],
            $entry['room_id'] === null ? null : (int) $entry['room_id'],
            (int) $entry['weekday'],
            (string) $entry['starts_at'],
            (string) $entry['ends_at'],
            $entry['double_period_group'] === null ? null : (string) $entry['double_period_group'],
            (int) $entry['id'],
            $schoolId,
            $versionId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Timetable history no longer matches the draft entries.');
        }
    }
}

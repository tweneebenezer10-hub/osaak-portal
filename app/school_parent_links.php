<?php
declare(strict_types=1);

function school_parent_has_verified_link(int $schoolId, int $studentId, int $userId): bool
{
    if ($schoolId < 1 || $studentId < 1 || $userId < 1) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1
         FROM school_parent_links link
         JOIN school_guardians guardian
           ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
         JOIN school_students student
           ON student.id = link.student_id AND student.school_id = link.school_id
         JOIN users parent_account
           ON parent_account.id = link.user_id AND parent_account.status = "active"
              AND parent_account.email_verified_at IS NOT NULL
         JOIN school_memberships membership
           ON membership.user_id = link.user_id AND membership.school_id = link.school_id
              AND membership.role_name = "school_parent" AND membership.status = "active"
         WHERE link.school_id = ? AND link.student_id = ? AND link.user_id = ?
           AND link.status = "verified"
           AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
         LIMIT 1'
    );
    $stmt->execute([$schoolId, $studentId, $userId]);

    return (bool) $stmt->fetchColumn();
}

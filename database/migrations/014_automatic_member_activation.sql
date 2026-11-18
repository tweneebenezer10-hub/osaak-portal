USE secure_auth_system;

START TRANSACTION;

INSERT INTO security_logs (user_id, event_type, details)
SELECT u.id, 'membership_auto_activated', 'migration=014_remove_membership_approval'
FROM users u
JOIN member_profiles mp ON mp.user_id = u.id
WHERE mp.membership_status = 'pending';

UPDATE member_profiles mp
JOIN users u ON u.id = mp.user_id
SET mp.membership_status = 'active',
    mp.membership_number = COALESCE(
        mp.membership_number,
        CONCAT('OSA-', YEAR(UTC_DATE()), '-', UPPER(LPAD(HEX(mp.user_id), 12, '0')))
    ),
    mp.joined_at = COALESCE(mp.joined_at, u.created_at, UTC_TIMESTAMP())
WHERE mp.membership_status = 'pending';

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT pending_assignments.user_id, member_role.id
FROM (
    SELECT ur.user_id
    FROM user_roles ur
    JOIN roles pending_role ON pending_role.id = ur.role_id
    WHERE pending_role.name = 'pending_member'
) AS pending_assignments
CROSS JOIN roles member_role
WHERE member_role.name = 'ordinary_member';

DELETE ur
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id
WHERE r.name = 'pending_member';

DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.name = rp.permission_name
WHERE p.name = 'members.approve';

DELETE FROM permissions WHERE name = 'members.approve';
DELETE FROM roles WHERE name = 'pending_member';

COMMIT;

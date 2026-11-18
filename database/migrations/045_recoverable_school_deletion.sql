ALTER TABLE schools
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status,
    ADD COLUMN deleted_previous_status ENUM('pending', 'active', 'suspended', 'expired') NULL DEFAULT NULL AFTER deleted_at,
    ADD COLUMN deleted_previous_subscription_status ENUM('trial', 'active', 'expired', 'suspended', 'cancelled') NULL DEFAULT NULL AFTER deleted_previous_status,
    ADD KEY idx_schools_deleted_at (deleted_at);

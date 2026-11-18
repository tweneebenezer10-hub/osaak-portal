USE secure_auth_system;

ALTER TABLE payment_records
    ADD COLUMN IF NOT EXISTS receipt_issued_at DATETIME NULL AFTER reviewed_at;

UPDATE payment_records
SET receipt_issued_at = COALESCE(reviewed_at, paid_at)
WHERE status = 'confirmed' AND receipt_issued_at IS NULL;

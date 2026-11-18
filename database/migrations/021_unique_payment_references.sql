USE secure_auth_system;

ALTER TABLE payment_records
    ADD UNIQUE INDEX IF NOT EXISTS uq_payment_reference_method (payment_method, reference);

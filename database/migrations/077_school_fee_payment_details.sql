ALTER TABLE school_payments
    MODIFY COLUMN payment_method ENUM('cash','bank_deposit','bank_transfer','mobile_money','card','offline') NOT NULL DEFAULT 'cash',
    ADD COLUMN payer_name VARCHAR(120) NULL AFTER payment_reference;

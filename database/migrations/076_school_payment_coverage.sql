ALTER TABLE school_payments
    ADD COLUMN payment_coverage ENUM('unspecified','part','term','academic_year') NOT NULL DEFAULT 'unspecified'
        AFTER amount;

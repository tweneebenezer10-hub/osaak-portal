ALTER TABLE schools
    ADD COLUMN subscription_term_fee DECIMAL(12,2) NOT NULL DEFAULT 400.00
    AFTER annual_fee;

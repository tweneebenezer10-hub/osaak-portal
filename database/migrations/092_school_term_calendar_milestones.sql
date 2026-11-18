ALTER TABLE school_terms
    ADD COLUMN vacation_starts_on DATE NULL AFTER ends_on,
    ADD COLUMN vacation_ends_on DATE NULL AFTER vacation_starts_on,
    ADD COLUMN reopening_on DATE NULL AFTER vacation_ends_on,
    ADD COLUMN examination_starts_on DATE NULL AFTER reopening_on,
    ADD COLUMN examination_ends_on DATE NULL AFTER examination_starts_on,
    ADD COLUMN report_preparation_on DATE NULL AFTER examination_ends_on,
    ADD COLUMN report_publication_on DATE NULL AFTER report_preparation_on;

USE secure_auth_system;

ALTER TABLE election_voters
    DROP COLUMN IF EXISTS submitted_at;

USE secure_auth_system;

ALTER TABLE association_settings
    DROP FOREIGN KEY fk_association_settings_editor;

ALTER TABLE association_settings
    MODIFY updated_by BIGINT UNSIGNED NULL;

ALTER TABLE association_settings
    ADD CONSTRAINT fk_association_settings_editor
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

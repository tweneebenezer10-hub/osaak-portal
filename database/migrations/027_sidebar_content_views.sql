USE secure_auth_system;

CREATE TABLE IF NOT EXISTS sidebar_badge_tracking (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    tracking_started_at DATETIME NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO sidebar_badge_tracking (singleton_id, tracking_started_at)
VALUES (1, UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS user_content_views (
    user_id BIGINT UNSIGNED NOT NULL,
    content_type VARCHAR(32) NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    content_updated_at DATETIME NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, content_type, content_id),
    CONSTRAINT fk_user_content_views_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_content_views_content (content_type, content_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_request_attachments_request_id (request_id),
    CONSTRAINT fk_request_attachments_request
        FOREIGN KEY (request_id) REFERENCES requests (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_request_attachments_user
        FOREIGN KEY (uploaded_by) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
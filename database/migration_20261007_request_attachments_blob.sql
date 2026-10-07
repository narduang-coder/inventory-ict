ALTER TABLE request_attachments
    ADD COLUMN file_data LONGBLOB NULL AFTER file_size;
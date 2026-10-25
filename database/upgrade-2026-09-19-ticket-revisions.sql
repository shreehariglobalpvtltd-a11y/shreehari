-- Ticket revision marks (19 Sep 2026): Ticket::reissue() counts, the renderer prints CORRECTED · REV n.
-- Idempotent: skips if columns already exist.
SET @done = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'reissue_count');
SET @q = IF(@done, 'SELECT 1', 'ALTER TABLE tickets ADD COLUMN reissue_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_void, ADD COLUMN reissued_at DATETIME NULL AFTER reissue_count');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;

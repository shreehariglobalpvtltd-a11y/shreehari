-- Ticket revision marks (19 Sep 2026): Ticket::reissue() counts, the renderer prints CORRECTED · REV n.
ALTER TABLE tickets
  ADD COLUMN reissue_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_void,
  ADD COLUMN reissued_at DATETIME NULL AFTER reissue_count;

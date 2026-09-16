-- =====================================================================
--  Upgrade: SHG Sahayak AI fallback (v4.0 · 2026-08)
--  Run ONCE on the database (phpMyAdmin -> SQL tab -> paste -> Go).
--
--  Safe to re-run: INSERT IGNORE only adds missing rows.
--
--  Fill anthropic_api_key in Admin -> Settings (leave empty to keep the
--  bot rule-based only — the proxy answers 503 and the app degrades
--  silently). is_public = 0: the key never reaches a browser.
-- =====================================================================

-- NOTE: no semicolons inside string literals — tests/apply-sql.php splits on them.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('anthropic_api_key','','string','notify','Anthropic API key for SHG Sahayak AI fallback (empty = rule-based only)',0),
('ai_model','claude-sonnet-4-6','string','notify','AI model for the Sahayak fallback',0);

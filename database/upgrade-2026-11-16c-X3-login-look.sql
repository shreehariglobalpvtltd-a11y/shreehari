-- =====================================================================
--  upgrade-2026-11-16c-X3-login-look.sql  (16 Nov 2026, Master v2.0 unit X3)
--
--  P1-2e + P1-7a + P1-7a2  the optional gold look on the staff door
--         (admin/login.php). Switch login_theme:
--           'classic' = the door exactly as today (the default)
--           'gold'    = dark navy glass card, circular PIN keys with a gold
--                       ring, gold active dot / primary button, and on a
--                       phone the PIN / pattern screen full-screen dark.
--         Colours only - no new font, no layout or text change.
--
--  Nothing changes for anyone after this runs: the row is stored at
--  'classic'. Until it runs the page reads the same default (a missing row
--  = classic). Additive and safe to re-run: INSERT IGNORE keeps any value
--  already stored.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('login_theme', 'classic', 'string', 'app', 'Staff login look: classic (today''s blue + white card) or gold (dark navy glass card, gold PIN keys; phone PIN / pattern screen full-screen dark)', 0);

-- =====================================================================
--  Upgrade 2026-09-26 — the sign-in middle way
--
--      php tests/apply-sql.php database/upgrade-2026-09-26-login-otp.sql
--
--  Idempotent (INSERT IGNORE). Safe while live.
--
--  Sign-in has been name + mobile with no code since 4 Sep, which is what
--  makes it one tap. The cost, stated plainly: anyone who knew a customer's
--  mobile could sign in as them, read their whole booking history, and
--  overwrite the name on file (Auth::loginUser writes the typed name).
--
--  The owner chose the middle way on 26 Sep: a NEW number is still one tap;
--  a number that already has confirmed tickets is proven once with a code.
--  A sale by signed-in STAFF is exempt in code — the clerk is the proof.
--
--  This row exists so the rule can be switched off from Admin -> Settings:
--  Settings::setMany() ignores a key that has no row, so a switch with no
--  row cannot be turned off when it matters.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
 ('login_otp_for_returning','1','bool','security',
  'Ask a WhatsApp code when a number that already has tickets signs in. A new number stays one tap, and a counter sale is never asked.',0);

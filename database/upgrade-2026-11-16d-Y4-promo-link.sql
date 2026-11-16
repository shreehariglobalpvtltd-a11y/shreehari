-- =====================================================================
--  upgrade-2026-11-16d-Y4-promo-link.sql  (16 Nov 2026, Master v2.0 unit Y4)
--
--  P6-9d  "🎁 अफर · Offer" in the WhatsApp inbox chat header
--         (admin/wa-inbox.php): a link to a NEW campaign on
--         admin/campaigns.php for that ONE number
--         (?new=1&aud=phone%3D<10-digit local number>, CustomerDirectory's
--         exact phone filter). Shown only to someone who may create a
--         campaign (marketing.send or the owner; never a counter agent).
--         The link sends nothing: the Campaigns page keeps its STOP /
--         opt-in, staff-number, quiet-hours, WhatsApp daily cap, template
--         and test-send rules, and nothing goes out before Launch.
--
--  Switch wa_inbox_promo_link_on, stored OFF (0): nothing changes for
--  anyone after this runs. Until it runs the inbox reads the same default
--  (a missing row = OFF). Additive and safe to re-run: INSERT IGNORE keeps
--  any value already stored.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('wa_inbox_promo_link_on', '0', 'bool', 'whatsapp', '🎁 इनबक्सबाट अफर लिङ्क · WhatsApp inbox Offer link: a new campaign for that one number (sends nothing by itself)', 0);

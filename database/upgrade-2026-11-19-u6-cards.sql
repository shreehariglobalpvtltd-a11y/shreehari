-- =====================================================================
--  19 Nov 2026 (u6: CD-1 / NP-4 render) — ID card + visiting card designs.
--
--  The code defaults are the same as these rows; the rows only make the
--  switches visible / editable in Admin -> Settings. INSERT IGNORE: a value
--  the office already chose is never overwritten. Idempotent.
--
--    ap_id_front_style   royal     ID card design: royal | modern | heritage |
--                                  portrait (= the 13 Nov card, the rollback)
--    ap_vc_style         royal     visiting card design: royal | modern | heritage |
--                                  theme (= each member's own vc_theme, the rollback)
--    ap_vc_back_qr       web       visiting card back QR: web (shreehariglobal.in) |
--                                  webref (website + ?ref=agent code) | wa (member WhatsApp)
--    ap_card_route_np    नेपालगञ्ज/Rupaidiha → Surat   route line on Nepal-first cards
--    ap_card_route_in    Surat → Rupaidiha / Nepalgunj route line on India cards (heritage)
--    ap_card_cache       1         keep rendered card PNGs (tickets/apcard/)
--    ap_card_prerender   1         after a card is shown, draw the member's other designs
--    ap_card_harfbuzz    1         shape Nepali with HarfBuzz (DevShape) when the server has it
--
--  (ap_card_nepal_first is added by upgrade-2026-11-19-u5-agent-payout.sql.)
--
--    ap_card_prerender_print 1     the warm-up also draws the default design at 300 dpi
--    ap_card_bilingual   1         Nepali + English labels on every design
--    ap_card_signature_np ''       signatory on Nepal-company cards (empty = as before)
--    ap_card_classic     0         ONE switch back to the 13 Nov cards
--
--  Rollback (one switch): UPDATE settings SET svalue='1' WHERE skey='ap_card_classic';
--  (or the three separately: ap_id_front_style=portrait, ap_vc_style=theme, ap_vc_back_qr=wa)
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
 ('ap_id_front_style', 'royal', 'string', 'agentpro', 'ID card design: royal (Royal Gold) | modern (Modern Blue) | heritage (Nepal-India Heritage) | portrait (old 13 Nov card)', 0),
 ('ap_vc_style', 'royal', 'string', 'agentpro', 'Visiting card design: royal | modern | heritage | theme (each member''s own old design)', 0),
 ('ap_vc_back_qr', 'web', 'string', 'agentpro', 'Visiting card back QR: web (shreehariglobal.in) | webref (website + agent code) | wa (member WhatsApp)', 0),
 ('ap_card_route_np', 'नेपालगञ्ज/Rupaidiha → Surat', 'string', 'agentpro', 'Route line printed on Nepal-first (+977) cards', 0),
 ('ap_card_route_in', 'Surat → Rupaidiha / Nepalgunj', 'string', 'agentpro', 'Route line printed on India cards (Heritage design)', 0),
 ('ap_card_cache', '1', 'bool', 'agentpro', 'Keep rendered ID / visiting card pictures (faster print and WhatsApp)', 0),
 ('ap_card_prerender', '1', 'bool', 'agentpro', 'After a card is opened, draw the member''s other card designs in the background', 0),
 ('ap_card_harfbuzz', '1', 'bool', 'agentpro', 'Shape Nepali text on cards with HarfBuzz when the server has it', 0),
 ('ap_card_prerender_print', '1', 'bool', 'agentpro', 'The background warm-up also draws the default design at 300 dpi (A4 sheets / PDF open fast)', 0),
 ('ap_card_bilingual', '1', 'bool', 'agentpro', 'Card labels in Nepali + English (Nepali label · Roman value) on every design; 0 = English-only on Royal / Modern', 0),
 ('ap_card_signature_np', '', 'string', 'agentpro', 'Name under Authorised signatory on Nepal-company cards (empty = ap_card_signature, else the CEO)', 0),
 ('ap_card_classic', '0', 'bool', 'agentpro', 'ONE switch back to the 13 Nov cards: old portrait ID + own visiting-card theme + WhatsApp QR', 0);

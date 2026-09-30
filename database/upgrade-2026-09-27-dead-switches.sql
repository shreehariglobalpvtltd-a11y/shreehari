-- =====================================================================
--  upgrade-2026-09-27-dead-switches.sql — eleven switches nothing reads
--
--  Idempotent: deleting rows that are already gone changes nothing.
--
--  Each of these settings rows exists on the live database and NO PHP or
--  JavaScript on this tree reads it (grep -w over includes/ api/ admin/
--  cron/ assets/js/ index.php on 27 Sep 2026, and tests/dead-switches-test.php
--  keeps that true). Every one of them is a knob in Admin -> Settings that
--  did nothing when turned, so the honest thing is to remove the knob.
--
--    trust_card_on, refund_ladder_on   the 25 Sep trust layer is not on this
--                                      lineage (there is no includes/trust.php)
--    women_layer_on                    women-only berths are a seat RULE
--                                      (tests/women-only-test.php), never a switch
--    social_publish_on                 the marketing queue was not merged here
--    ai_examples_on, ai_memory_on,     read only by the gen-2 includes/ai/*
--    ai_refresh_on, ai_web_on          that was never wired and is now deleted
--                                      (ai_web_agent_on is a DIFFERENT key with
--                                      four readers and STAYS)
--    notice_on, temples_default_on     seeded by database/seed.sql, read by nothing
--    pin_lock_enabled                  inert since the client-side PIN went (shg-v56)
--
--  bundle_assets_on is NOT in this list: includes/assetbundle.php reads it.
--
--      php tests/apply-sql.php database/upgrade-2026-09-27-dead-switches.sql
-- =====================================================================

DELETE FROM `settings` WHERE `skey` IN (
  'trust_card_on', 'refund_ladder_on', 'women_layer_on', 'social_publish_on',
  'ai_examples_on', 'ai_memory_on', 'ai_refresh_on', 'ai_web_on',
  'notice_on', 'temples_default_on', 'pin_lock_enabled'
);

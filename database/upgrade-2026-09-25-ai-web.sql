-- =====================================================================
--  upgrade-2026-09-25-ai-web.sql — the assistant reads the internet,
--  the office decides what becomes a fact.
--
--  Additive, idempotent. Four rows, all OFF or empty, none public:
--
--   ai_web_on          the whole feature. Off = cron/ai-web-refresh.php
--                      does nothing and includes/aiweb.php fetches nothing.
--   ai_web_hosts       JSON array of hostnames that may be read at all,
--                      e.g. ["nepalpolice.gov.np","imd.gov.in"]. Empty
--                      means nothing can be fetched, which is the safe
--                      default: a URL nobody listed is refused.
--   ai_web_watch       JSON array of {"topic":"…","url":"https://…"} the
--                      nightly job follows.
--   ai_web_cache_hours how long a fetched page is reused (default 12).
--
--  What the job writes is always a knowledge-base DRAFT. AiKb answers
--  from rows that are published AND verified, so until a person opens
--  Admin -> Knowledge and publishes it, the assistant cannot repeat it.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('ai_web_on',          '0',  'bool',   'ai', 'Assistant may read the pages the office lists (drafts only, never published by itself)', 0),
  ('ai_web_hosts',       '[]', 'json',   'ai', 'Hostnames the assistant may read, JSON array', 0),
  ('ai_web_watch',       '[]', 'json',   'ai', 'Pages to read nightly: [{"topic":"…","url":"https://…"}]', 0),
  ('ai_web_cache_hours', '12', 'int',    'ai', 'Hours a fetched page is reused before reading it again', 0);

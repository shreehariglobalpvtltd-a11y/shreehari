-- =====================================================================
--  SHARI NAV Phase 4 (22 Oct 2026): arrival notification. A person who
--  follows a shared trip (navigate.html?follow=<token>) can ask for a push
--  when the rider arrives - the phone subscription itself lives in the
--  existing push_subscriptions table (WebPush::subscribe), this only links
--  it to the shared trip. New table only.
--  Apply by hand:  mysql shari < database/upgrade-2026-10-nav-watch.sql
-- =====================================================================
CREATE TABLE IF NOT EXISTS nav_share_watch (
  token      CHAR(32)        NOT NULL,
  sub_id     BIGINT UNSIGNED NOT NULL,
  created_at DATETIME        NOT NULL,
  PRIMARY KEY (token, sub_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

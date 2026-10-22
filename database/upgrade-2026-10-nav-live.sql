-- =====================================================================
--  SHARI NAV live (Phase 2, 22 Oct 2026): trip share, crowd reports,
--  device sync. New tables only - nothing existing is altered.
--  Apply by hand:  mysql shari < database/upgrade-2026-10-nav-live.sql
--  Old rows are purged hourly by cron/nav-live.php (positions > 24 h).
-- =====================================================================

-- A rider shares a live trip. token = the follow link (128-bit, hex),
-- the phone that shares keeps the secret, only its sha256 is stored.
CREATE TABLE IF NOT EXISTS nav_shares (
  token        CHAR(32)      NOT NULL,
  secret_hash  CHAR(64)      NOT NULL,
  dest_name    VARCHAR(120)  NOT NULL DEFAULT '',
  dest_lat     DECIMAL(10,7) NOT NULL,
  dest_lng     DECIMAL(10,7) NOT NULL,
  mode         VARCHAR(12)   NOT NULL DEFAULT 'car',
  route        MEDIUMTEXT    NULL,
  last_lat     DECIMAL(10,7) NULL,
  last_lng     DECIMAL(10,7) NULL,
  last_speed   DECIMAL(6,2)  NULL,
  last_heading SMALLINT      NULL,
  left_m       INT           NULL,
  eta_at       DATETIME      NULL,
  status       VARCHAR(10)   NOT NULL DEFAULT 'live',
  created_at   DATETIME      NOT NULL,
  updated_at   DATETIME      NOT NULL,
  expires_at   DATETIME      NOT NULL,
  PRIMARY KEY (token),
  KEY idx_nav_shares_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Road reports from riders (accident, police, landslide ...). No personal
-- data: reporter_hash is a keyed hash of a random id the phone made up.
CREATE TABLE IF NOT EXISTS nav_reports (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind          VARCHAR(16)   NOT NULL,
  lat           DECIMAL(10,7) NOT NULL,
  lng           DECIMAL(10,7) NOT NULL,
  geohash6      CHAR(6)       NOT NULL,
  heading       SMALLINT      NULL,
  note          VARCHAR(140)  NOT NULL DEFAULT '',
  created_at    DATETIME      NOT NULL,
  updated_at    DATETIME      NOT NULL,
  expires_at    DATETIME      NOT NULL,
  up            SMALLINT      NOT NULL DEFAULT 0,
  down          SMALLINT      NOT NULL DEFAULT 0,
  reporter_hash CHAR(64)      NOT NULL,
  status        VARCHAR(10)   NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_nav_reports_geo (geohash6),
  KEY idx_nav_reports_live (status, expires_at),
  KEY idx_nav_reports_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved places / home / work / trips / parking of a signed-in customer,
-- one JSON document per person (last write wins per item).
CREATE TABLE IF NOT EXISTS nav_sync (
  user_key    VARCHAR(64) NOT NULL,
  data        JSON        NOT NULL,
  updated_at  DATETIME    NOT NULL,
  PRIMARY KEY (user_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

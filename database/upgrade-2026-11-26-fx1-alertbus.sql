-- 26 Nov 2026 (fx1-alertbus): call-style alerts for the office - "a new ticket rings like a phone
-- until somebody taps Seen". Additive and idempotent: four new tables + settings seed rows, nothing
-- existing is altered. includes/alertbus.php returns quietly (AlertBus::on() = false) until this has run,
-- and the panel then keeps its old short chime.

-- One row per thing the office is told. src_key is the dedupe ('j:<automation_log.id>' for an event
-- read from the EventBus journal, 'bell:<notifications.id>' for a bell alert, 'credit:<admin>:<day>',
-- 'test:<random>'). acked_at = the moment someone tapped "Seen" - that stops the ring for everybody.
CREATE TABLE IF NOT EXISTS alert_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event          VARCHAR(40)  NOT NULL,
    src_key        VARCHAR(80)  NOT NULL,
    booking_id     BIGINT UNSIGNED NULL DEFAULT NULL,
    pnr            VARCHAR(40)  NULL DEFAULT NULL,
    actor_admin_id BIGINT UNSIGNED NULL DEFAULT NULL,
    desk_code      VARCHAR(16)  NULL DEFAULT NULL,
    amount         DECIMAL(12,2) NULL DEFAULT NULL,
    title          VARCHAR(191) NOT NULL,
    body           VARCHAR(500) NOT NULL DEFAULT '',
    link           VARCHAR(255) NULL DEFAULT NULL,
    icon           VARCHAR(10)  NOT NULL DEFAULT '',
    ring           TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL,
    ring_until     DATETIME     NULL DEFAULT NULL,
    acked_at       DATETIME     NULL DEFAULT NULL,
    acked_by       BIGINT UNSIGNED NULL DEFAULT NULL,
    ack_via        VARCHAR(12)  NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alert_src (src_key),
    KEY ix_alert_open (acked_at, ring_until),
    KEY ix_alert_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (alert, person, channel, attempt): the exactly-once claim for every push repeat and the
-- delivery log in one. admin_id 0 = the office WhatsApp number (one message per alert, attempt 0).
-- channel 'seen' = that person's PERSONAL Seen (an agent, or a counter on anything but a new ticket):
-- quiet for them only, the office still hears the alert.
CREATE TABLE IF NOT EXISTS alert_deliveries (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id   BIGINT UNSIGNED NOT NULL,
    admin_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    channel    VARCHAR(12)  NOT NULL,
    attempt    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ok         TINYINT(1)   NOT NULL DEFAULT 0,
    detail     VARCHAR(191) NULL DEFAULT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alert_delivery (alert_id, admin_id, channel, attempt),
    KEY ix_alert_delivery_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small counters: 'journal' = the last automation_log id already read (set to the newest id on first
-- use, so nothing old ever rings), 'sweep_at' / 'credit_at' = unix time of the last pass.
CREATE TABLE IF NOT EXISTS alert_bus_state (
    k VARCHAR(40) NOT NULL,
    v BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The phones that RING: one row per handset whose owner tapped "Ring on this phone"
-- (endpoint_hash = push_subscriptions.endpoint_hash). A phone that only ever allowed Office chat
-- notifications has no row here and is never rung. pw = the stamp of that person's password when the
-- ring was switched on: after a password change / office reset the phone stops ringing until its
-- owner opens the panel on it again. "Stop ringing on this phone" and the office's Remove delete the row.
CREATE TABLE IF NOT EXISTS alert_push_optin (
    endpoint_hash CHAR(40)        NOT NULL,
    admin_id      BIGINT UNSIGNED NOT NULL,
    pw            VARCHAR(16)     NOT NULL DEFAULT '',
    created_at    DATETIME        NOT NULL,
    PRIMARY KEY (endpoint_hash),
    KEY ix_alert_optin_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Switches and numbers (group 'alerts', none public). Edited on admin/alert-settings.php.
INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('alert_bus_on',          '1',  'bool', 'alerts', 'Alert bus: record ticket / payment / request / SOS alerts for the staff panel (off = nothing recorded, nothing rings)', 0),
  ('alert_ring_on',         '1',  'bool', 'alerts', 'Ring like a call on a new ticket until someone taps Seen (off = the old short chime and green bar)', 0),
  ('alert_ring_seconds',    '60', 'int',  'alerts', 'Ring for this many seconds each time', 0),
  ('alert_ring_repeat_min', '2',  'int',  'alerts', 'Ring again after this many minutes when nobody tapped Seen', 0),
  ('alert_ring_repeat_max', '3',  'int',  'alerts', 'How many times to ring again (0 = ring once only)', 0),
  ('alert_ring_burst',      '6',  'int',  'alerts', 'More unanswered rings than this are sent to a phone as ONE notification with a count', 0),
  ('alert_ring_self',       '0',  'bool', 'alerts', 'Also ring the person who cut the ticket (default off: a seller is not rung for their own sale)', 0),
  ('alert_push_on',         '1',  'bool', 'alerts', 'Send the ring to staff phones that tapped "Ring on this phone" (web push; also needs push_enabled)', 0),
  ('alert_wa_on',           '0',  'bool', 'alerts', 'Also send ringing alerts to the office WhatsApp number (max 6 an hour; default off)', 0),
  ('alert_matrix',          '',   'json', 'alerts', 'Who gets which alert, and which alerts ring (empty = built-in defaults)', 0),
  ('alert_role_prefs',      '',   'json', 'alerts', 'Per role: sound on/off, volume, quiet hours (empty = sound on, 80 %, no quiet hours)', 0);

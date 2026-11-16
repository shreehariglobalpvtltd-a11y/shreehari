-- =====================================================================
--  upgrade-2026-11-16b-W5-notify.sql  (16 Nov 2026, Master v2.0 unit W5)
--
--  P8-4b  the 24-hour trip reminder. trip_events.event is an ENUM; the new
--         value 'reminder_24h' is APPENDED, every existing value kept in its
--         order (an instant metadata change on InnoDB). Until this runs the
--         code never claims the event (TripNotify::eventStorable), so files
--         that go live first cannot write a '' row.
--         Switch reminder_24h_enabled - 0 = off (the default).
--  P8-12b trip_alert_agents_on - a driver's SOS / breakdown also posts one
--         line in the office chat of every agent / counter with passengers
--         on that bus. 0 = off (the default).
--  P6-11e wa_global_daily_cap - all outbound WhatsApp a day; 0 = no cap
--         (the default). At the cap only offers / campaigns / broadcasts and
--         the agents' good-morning wait; tickets, codes, bot replies, trip
--         updates and office alerts always go. Office told once at 80 %.
--
--  Nothing changes for any customer, agent or office number after this
--  runs: every switch is stored at its off value. Additive and safe to
--  re-run: MODIFY lists the same values again, INSERT IGNORE keeps any
--  value already stored.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `trip_events`
  MODIFY `event` ENUM('reminder_12h','reminder_2h','departed','border','arrived','reminder_24h') NOT NULL;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('reminder_24h_enabled', '0', 'bool', 'notify', 'Send the 24-hours-before-pickup reminder (Nepali first: pickup time, stop, seat, ticket link). Off = only the 12 h and 2 h reminders', 0),
  ('trip_alert_agents_on', '0', 'bool', 'notify', 'Bus breakdown / SOS from the driver app: also tell the agents and counters who have passengers on that bus (office chat line + push)', 0),
  ('wa_global_daily_cap', '0', 'int', 'whatsapp', 'All WhatsApp per day (0 = no cap). At the cap only offers / campaigns / good-morning wait; tickets, OTP, bot replies and office alerts always go. Office is told once at 80 %', 0);

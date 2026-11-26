# Android lock-screen ring for a new ticket - what the staff app needs (fx1, 26 Nov 2026)

**Status: NOT BUILT. Nothing in this document is in any APK.** The web side is built
(`includes/alertbus.php`, `sw.js`) and tested on the local machine only - it has not
been tried on a real phone yet. This is the list of changes the Android wrapper needs
so a new ticket rings like an incoming call on a locked phone.

## What the web side does without touching the app

A staff phone that tapped "यो फोनमा घण्टी बजाउनुहोस् · Ring on this phone" gets a web push
for every ring:

- it stays on screen until tapped (`requireInteraction`), has two buttons
  "देखेँ · Seen" and "खोल्नुहोस् · Open", vibrates with a long pattern, and comes again
  every `alert_ring_repeat_min` minutes up to `alert_ring_repeat_max` times;
- "Seen" on the notification stops the ring on every phone and panel
  (POST `/api/alert-ack.php` with the signed token that came inside the push).

What a web push can **not** do on Android: play a looping custom ringtone, wake the
screen with a full-screen call card, or bypass Do-Not-Disturb. It uses the sound and
importance of whatever notification channel the app posts it to.

## Where the wrapper is

Not in git. On the owner's computer: `C:\S HARI PRJECT'\android-apps\manage`

- package `com.shreehariglobal.manage`, versionCode 2 / versionName 1.0.1,
  compileSdk 36, minSdk 21, targetSdk 36
- a Trusted Web Activity: `com.google.androidbrowserhelper:androidbrowserhelper:2.6.2`
  (+ `locationdelegation:1.1.2`), host `shreehariglobal.network`
- `app/src/main/java/com/shreehariglobal/manage/DelegationService.java` extends the
  helper's `DelegationService` (this is the service Chrome hands web notifications to)
- `POST_NOTIFICATIONS` is already declared

## Step 1 (small, recommended): a loud channel for ring notifications

Web notifications are delivered to the app through
`TrustedWebActivityService.onNotifyNotificationWithChannel(String platformTag, int platformId, Notification notification, String channelName)`.
The stock implementation creates one default-importance channel. Override it in
`DelegationService`:

1. In `onCreate()` create a channel once:
   - id `booking_ring`, name "नयाँ टिकट · New ticket ring"
   - `NotificationManager.IMPORTANCE_HIGH`
   - sound = `RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)` with
     `AudioAttributes` usage `USAGE_NOTIFICATION_RINGTONE`
   - `enableVibration(true)`, pattern `{0, 400, 200, 400, 200, 400, 600, 400, 200, 400}`
   - `setLockscreenVisibility(Notification.VISIBILITY_PUBLIC)`
2. In `onNotifyNotificationWithChannel(...)`: when `platformTag` contains `shg-ring`
   (the web tag is `shg-ring-<id>` or `shg-ring-many`), rebuild the notification on
   the `booking_ring` channel
   (`Notification.Builder.recoverBuilder(this, notification).setChannelId("booking_ring").setCategory(Notification.CATEGORY_CALL).build()`),
   post it with the same tag and id, and return `true`. Every other notification goes
   to `super`.
3. Check on a real phone how Chrome spells `platformTag` for a tagged web notification
   before relying on `contains("shg-ring")` (log it once).

Result: heads-up banner + the phone's ringtone + vibration on the lock screen, with
the web Seen / Open buttons still working. A channel's sound cannot be changed after
it is created - if the sound must change later, use a new channel id.

## Step 2 (bigger, optional): a true incoming-call screen

A full-screen call card needs native code that runs without Chrome:

1. **Wake-up**: Firebase Cloud Messaging *data* messages (high priority) to a
   `FirebaseMessagingService`. This needs a Firebase project, `google-services.json`,
   the app's FCM token stored per staff member, and a server sender in PHP next to
   `WebPush::sendToAdmin` (a new channel in `AlertBus::deliver`). A foreground service
   that polls is the alternative, but it costs battery and a permanent notification.
2. **Permissions** in `AndroidManifest.xml`: `USE_FULL_SCREEN_INTENT`, `WAKE_LOCK`,
   `VIBRATE`. On Android 14+ `USE_FULL_SCREEN_INTENT` is granted by default only to
   calling / alarm apps - a sideloaded staff app must send the person to
   `Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT` once and check
   `NotificationManager.canUseFullScreenIntent()`.
3. **`RingActivity`** (`showWhenLocked="true"`, `turnScreenOn="true"`, excluded from
   recents): shows the title and body from the message, loops the ringtone
   (`USAGE_NOTIFICATION_RINGTONE`) for `alert_ring_seconds`, and has two buttons:
   - "देखेँ · Seen" -> POST `https://shreehariglobal.network/api/alert-ack.php`
     JSON `{ "id": <alertId>, "admin": <adminId>, "t": "<ackToken>" }`
     (all three come in the message; no login needed), then finish
   - "खोल्नुहोस् · Open" -> the same POST, then launch the TWA on the message's `url`
4. The notification that carries the full-screen intent uses the `booking_ring`
   channel from step 1, `CATEGORY_CALL`, `setOngoing(true)` while ringing, and is
   cancelled when the server says the alert was acked (send a second data message
   `{"type":"ring_stop","alertId":..}` from `AlertBus::ack`).

## Release steps (either step)

- bump `versionCode` to 3 (and `versionName`), rebuild, sign with the **same** upload
  key (a different key cannot update the installed app and breaks
  `.well-known/assetlinks.json`)
- copy the APK to `/downloads/` by hand as before (APKs are not in git)
- test on a real locked phone: ring, Seen from the lock screen, Open, Do-Not-Disturb,
  battery saver, a second ring 2 minutes later

## What the payload carries (built on the web side: `AlertBus::pushPayload`)

`title`, `body`, `url` (a `/admin/...` link), `tag` (`shg-ring-<id>`), `ring: 1`,
`requireInteraction`, `renotify`, `vibrate[]`, `actions[]` (seen / open), `alertId`,
`adminId`, `ackUrl` (`/api/alert-ack.php`), `ackToken`, `ackAll` (1 on a merged
"N new alerts" notification: Seen then marks every open ring this person can see).

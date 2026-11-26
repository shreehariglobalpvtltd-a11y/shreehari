# Staff panel sound kit (fx1, 26 Nov 2026)

There are **no audio files** in this folder on purpose. Every sound the staff panel
plays is made in the browser with WebAudio oscillators by `assets/js/admin-ring.js`
(`VOICES`). Nothing is downloaded, nothing is cached, nothing is licensed from anyone:
the sounds are a few lines of arithmetic written for this project (self-made =
licence-free).

Why no files: the site's Content-Security-Policy has no `media-src`, so only
same-origin files could play at all, and a synthesised sound costs 0 KB, works
offline and never goes stale in the service-worker cache.

## The seven sounds

| Name    | Used for                               | Recipe (all gains pass one master gain = 0.6 x the role's volume) |
|---------|----------------------------------------|--------------------------------------------------------------------|
| `ring`  | new ticket / agent ticket (the call ring) | Telephone bell "brr-brr": two bursts of 0.42 s at 0 s and 0.55 s. Each burst = sine 440 Hz + sine 480 Hz, peak 0.34, amplitude wobbled by a 20 Hz sine (the bell clapper). One bar = 3 s (bursts, then silence); the bar loops while the ring runs. |
| `chime` | payment confirmed                      | Three rising triangle notes E5 659 Hz, G5 784 Hz, C6 1047 Hz, 0.14 s apart, 0.34 s each, peak 0.30. Same notes as the older px1 "new ticket" chime. |
| `tick`  | counter ticket; a ring on a selling screen | One square-wave blip, 1200 Hz, 0.07 s, peak 0.12. |
| `alert` | cancel / date-change request, bus late, agent credit low | Two falling triangle notes: F#5 740 Hz 0.22 s, then B4 494 Hz 0.34 s, peak 0.30. |
| `siren` | driver SOS                             | Sawtooth sweep 600 -> 1200 Hz over 0.8 s, then 1200 -> 600 Hz over 0.8 s, peak 0.26. One bar = 1.6 s, loops while the SOS rings. |
| `ding`  | day report ready                       | A struck bell: sine E6 1319 Hz, 10 ms attack, 1.2 s exponential decay, peak 0.34, plus its octave 2637 Hz for 0.6 s at peak 0.10. |
| `pop`   | hold expired, anything else small      | One sine glide 420 -> 880 Hz in 0.11 s, peak 0.26. |

Every note has a 20 ms attack and a short exponential release so nothing clicks.

## Rules the player follows

- **One** `AudioContext` for the whole page. It is created lazily and resumed on the
  first tap / key press (browsers keep audio locked until then); while it is still
  locked the ring card shows "आवाज खोल्न ट्याप गर्नुहोस् · Tap for sound".
- The drawer's sound button (`localStorage shg_admin_sound = '0'`) silences
  everything, including vibration. The card still shows.
- Per-role sound on/off, volume and quiet hours come from the server
  (`alert_role_prefs`, edited on Admin -> Ring & alerts). A driver SOS rings through
  quiet hours.
- Only one open tab makes the noise (`BroadcastChannel('shg-ring')`); a hidden tab is silent.
- On SmartBook / quick ticket / new booking a ring is one `tick` and a slim bar, never
  the loop - a clerk in the middle of a sale is not interrupted.

## Hear them

Admin -> घण्टी र सूचना · Ring & alerts -> "आवाज सुन्नुहोस् · Hear the sounds", or in the
browser console on any panel page: `SHGRing.play('ring')`.

## If real audio files are ever wanted

Put them here (same origin), keep each under 40 KB, record the source and licence in
this file, and load them through `admin_asset_url()` so they are versioned. A web push
notification on a locked phone can **not** use them either way - it plays the phone's
own notification sound (see `docs/fx1-android-booking-ring.md`).

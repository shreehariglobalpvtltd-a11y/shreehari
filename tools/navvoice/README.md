# SHARI NAV voice packs

`navigate.html` speaks turn-by-turn directions from pre-recorded clips:

    assets/navvoice/phrases.json          id -> words, per language (ne, hi)
    assets/navvoice/<lang>-<voice>/       <id>.mp3 + index.json (the ids that exist)

A prompt is joined from clips (`d_500` + `a_turn_right` = "पाँच सय मिटरपछि दायाँ
मोड्नुहोस्"). If any clip of a prompt is missing, or a pack has no `index.json`, the
phone's own voice says the same words, so a half-recorded pack is safe to ship.

## Recording (on the VPS — the Gemini key never leaves it)

    cd /var/www/shreehariglobal.in/public_html
    sudo -u www-data env NAVVOICE_MODELS=gemini-3.1-flash-tts-preview NAVVOICE_BATCH=18 NAVVOICE_ASR=clip \
      php tools/navvoice/build-voice.php assets/navvoice/phrases.json ne Leda /tmp/navvoice/out/ne-leda [ids]

- Writes trimmed, loudness-matched 24 kHz WAVs plus `index.json` with what each clip
  was heard as (`sim` 1.0 = exact).
- **Quota:** the key is on Gemini's free tier — about 10 TTS requests per model per
  day, reset at midnight Pacific (about 12:30 IST). Use one model per pack so it all
  sounds like the same voice. The generator stops by itself when the quota runs out.
  Its checks use the *lite* flash models; WhatsApp and the ID checks use the full ones.
- Numbers are written as words so the voice cannot misread them, **except** `d_300`
  and `d_400`. Written as words they come out sounding like "सेन्टिमिटर", so
  phrases.json gives them as the numerals `३०० मिटरपछि` / `४०० मिटरपछि`. The
  words the check should hear are in `ne_check`.
- A re-run continues where the last one stopped: clips already recorded and checked
  are skipped (`NAVVOICE_FORCE=1` records them again). The better take is always
  kept. A clip the check could not hear is not saved unless `NAVVOICE_ALLOW_UNCHECKED=1`.

## Publishing

1. Copy the WAVs to a PC and encode them to 40 kbps MP3 (the VPS has no ffmpeg). The
   session used `@breezystack/lamejs` from Node.
2. Put them in `assets/navvoice/<pack>/` and write `index.json`:
   `{"ids":[...],"sec":{...}}`.
3. Change `VOICE_VER` in `navigate.html` and `v` in `phrases.json` whenever any clip
   changes, then deploy as usual with `/root/deploy-prod.sh`.

Status on 21 Oct 2026:
- `ne-leda` is live with 102 of 104 clips. `d_300` and `d_400` are still to record;
  until then those prompts round to the nearest distance that has a clip.
- `hi-leda` and `ne-achernar` are not recorded yet. Until they are, Hindi uses the
  phone's voice and the soft voice option stays hidden.

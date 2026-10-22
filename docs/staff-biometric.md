# Staff applications — biometric stubs (phase 2)

22 Oct 2026. Behind the setting **`staff_biometric_on`** (default **0 = off**).
The staff form `/agreements/staff.html` works end to end with it off; with it
on, both pieces below are still optional for the applicant and never block
the form. Turning it on is an owner decision (Admin → Settings →
`staff_biometric_on`).

## What is captured

| Piece | Where | Stored | Never stored |
|---|---|---|---|
| Face frame | the selfie the applicant already took, redrawn on the phone as a **256×256 greyscale JPEG** (centre square, slightly above middle) | the JPEG as `uploads/staff/<id>/face-<random>.jpg` and its **SHA-256** in `staff_applications.biometric_face_hash` (the server hashes the bytes it received — the phone's own hash is not trusted) | — |
| Phone passkey | `navigator.credentials.create()` (WebAuthn, platform authenticator, user verification required) | the **public** credential as JSON in `staff_applications.biometric_webauthn_cred`: `id`, `type`, `clientDataJSON`, `attestationObject`, `transports`, `registered_at` | the fingerprint / face unlock itself — it never leaves the phone; the browser only proves the person unlocked it |
| Fingerprint hash | `biometric_fingerprint_hash` column | nothing (reserved) | phones do not give apps a fingerprint template, by design |

## Flow

1. `GET /api/staff-apply.php` → `"biometric": true` shows the optional
   "🔐 Phone lock" card on the review step (only when the browser has
   `PublicKeyCredential`).
2. Tap **Add phone lock** → `POST {"action":"challenge"}` → 32 random bytes
   (base64url) kept in the session for 10 minutes, plus `rpId` (the host of
   `APP_URL`) and `rpName`.
3. The page calls `navigator.credentials.create` with that challenge,
   `authenticatorAttachment: "platform"`, `userVerification: "required"`,
   `attestation: "none"`, algorithms ES256 (-7) and RS256 (-257). The phone
   asks for its own fingerprint / face / PIN.
4. On send, the multipart form carries `webauthn` (the JSON above) and `face`
   (the grey JPEG). `StaffApps::webauthnCred()` keeps the credential only if
   `clientDataJSON.type` is `webauthn.create`, its `challenge` equals the
   session's, and its `origin` equals `APP_URL`'s origin. Anything else is
   dropped silently — the application still goes through.

## What is NOT built yet (the Python worker)

- **Face match**: compare `face-*.jpg` with the ID photo (`id_front_path`)
  and later with duty selfies. The hash is only an integrity fingerprint of
  the stored frame — two photos of the same face have different hashes, so
  matching needs the image, which is why the frame is kept.
- **Passkey sign-in / attendance**: verifying an assertion
  (`navigator.credentials.get`) needs the public key out of
  `attestationObject` (CBOR → COSE key) and a signature check. Store the
  parsed key next to the credential when this is built.
- Office view shows only "Face frame: <first 16 hex>…" and "Phone passkey:
  ✓ registered" in the application drawer.

## Privacy notes

- Nothing biometric is public: `/api/staff-list.php` builds its rows key by
  key from `staff_public_fields`, which cannot name these columns.
- The face frame lives under `uploads/staff/`, denied by nginx and readable
  only through `admin/staff-file.php` on a signed link (super-admin /
  manager).
- Deleting an application's folder removes the frame; the hash alone cannot
  rebuild a face.

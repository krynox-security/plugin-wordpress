# Krynox Captcha for WordPress

Privacy-first, proof-of-work CAPTCHA plugin for WordPress — protects **login,
registration, lost-password and comment** forms. No cookies, no puzzles, no tracking.

## Install

1. Copy this folder to `wp-content/plugins/krynox-captcha/` (or zip & upload via the
   Plugins screen), then **Activate**.
2. **Settings → Krynox Captcha** → paste your **Site key** (`kcpt_…`) and **Secret key**
   (`kcps_…`) from [app.krynox.net](https://app.krynox.net).
3. Tick the forms to protect.

## How it works

- Renders `<krynox-captcha>` on the enabled forms (script loaded from
  `cdn.krynox.net` as an ES module).
- On submit, verifies the solved token **server-to-server** against
  `https://api.krynox.net/siteverify` with your secret key (`wp_remote_post`). Transient failures
  (network / 429 / 5xx) are retried automatically with a per-verify idempotency key.
- Failures block the action with a clear error (`authenticate`, `registration_errors`,
  `lostpassword_post`, `preprocess_comment`). Login enforcement is limited to the
  standard `wp-login.php` form so XML-RPC / REST / application-password logins keep working.

### Advanced: act on the full result

Hook the `krynox_captcha_verified` filter to inspect the server response (score, risk, `reasons`,
verified `agent`, attested `human`) or to force-reject a structurally-valid solution:

```php
add_filter( 'krynox_captcha_verified', function ( $success, $data ) {
    if ( ! empty( $data['reasons'] ) && in_array( 'tor-exit', $data['reasons'], true ) ) {
        return false; // reject Tor exits, even if the PoW was valid
    }
    return $success;
}, 10, 2 );
```

## Settings

Site key · secret key · per-form toggles · API host · CDN host (override the last two
for self-hosting).

## Honeypot

Enable **Honeypot** for the site in the Krynox dashboard and the widget injects an invisible decoy
field (`krynox-hp`) that only bots fill in. The plugin forwards it to `/siteverify` as `honeypot`
automatically on every protected form (login, register, lost-password, comments) — no configuration
needed. The data plane then floors the score (report mode) or rejects with `honeypot-tripped`
(enforce mode). See the [Honeypot docs](https://docs.krynox.net/server-side/honeypot/).

## License

GPL-2.0-or-later (WordPress-compatible). See [LICENSE](./LICENSE).
Built for [Krynox Captcha](https://krynox.net) · docs: <https://krynox.net/docs>

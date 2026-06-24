# Krynox Captcha for WordPress

Privacy-first, proof-of-work CAPTCHA plugin for WordPress — protects **login,
registration, lost-password and comment** forms. No cookies, no puzzles, no tracking.

## Install

1. Copy this folder to `wp-content/plugins/krynox-captcha/` (or zip & upload via the
   Plugins screen), then **Activate**.
2. **Settings → Krynox Captcha** → paste your **Site key** (`kcpt_…`) and **Secret key**
   (`kcps_…`) from [app.krynox.id](https://app.krynox.id).
3. Tick the forms to protect.

## How it works

- Renders `<krynox-captcha>` on the enabled forms (script loaded from
  `cdn.krynox.id` as an ES module).
- On submit, verifies the solved token **server-to-server** against
  `https://api.krynox.id/siteverify` with your secret key (`wp_remote_post`).
- Failures block the action with a clear error (`authenticate`, `registration_errors`,
  `lostpassword_post`, `preprocess_comment`). Login enforcement is limited to the
  standard `wp-login.php` form so XML-RPC / REST / application-password logins keep working.

## Settings

Site key · secret key · per-form toggles · API host · CDN host (override the last two
for self-hosting).

## License

GPL-2.0-or-later (WordPress-compatible). See [LICENSE](./LICENSE).
Built for [Krynox Captcha](https://krynox.id) · docs: <https://krynox.id/docs>

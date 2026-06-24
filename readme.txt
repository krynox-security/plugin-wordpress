=== Krynox Captcha ===
Contributors: krynox
Tags: captcha, spam, security, proof-of-work, privacy
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first, proof-of-work CAPTCHA for login, registration, lost-password and comments. No cookies, no puzzles, no tracking.

== Description ==

Krynox Captcha protects your WordPress forms from bots and spam using an invisible
**proof-of-work** challenge — no image puzzles, no cookies, no fingerprinting, no
third-party tracking. The browser solves a small cryptographic challenge in the
background; suspicious traffic gets an accessible code challenge.

Protects, out of the box:

* Login form
* Registration form
* Lost-password form
* Comment form

Each is toggleable in **Settings → Krynox Captcha**. Verification happens
server-to-server against the Krynox data plane using your secret key.

You need a free Krynox account and a site's keys from https://app.krynox.id.

== Installation ==

1. Upload the `krynox-captcha` folder to `/wp-content/plugins/`, or install the
   plugin through the WordPress Plugins screen.
2. Activate the plugin.
3. Go to **Settings → Krynox Captcha** and paste your **Site key** (`kcpt_…`) and
   **Secret key** (`kcps_…`).
4. Choose which forms to protect. Done.

== Frequently Asked Questions ==

= Where do I get my keys? =
Create a free account at https://app.krynox.id, add a site, and copy its site key
(public) and secret key (private).

= Does it use cookies or track users? =
No. Krynox is privacy-first: no tracking cookies, no fingerprinting, and the
end-user IP is never sent to a third-party service.

= Can I self-host the data plane? =
Yes — override the **API host** and **CDN host** in settings.

== Changelog ==

= 0.1.0 =
* Initial release: login, registration, lost-password and comment protection.

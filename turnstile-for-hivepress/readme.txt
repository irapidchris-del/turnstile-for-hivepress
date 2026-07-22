=== Turnstile for HivePress ===
Tags: hivepress, cloudflare, turnstile, captcha, spam
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protects HivePress forms with Cloudflare Turnstile, using HivePress's own native captcha field system for full modal and AJAX support.

== Description ==

Turnstile for HivePress adds a Cloudflare Turnstile widget to the HivePress forms you choose — including the login, register and reset-password **modal popups** — and verifies the token server-side on every submission.

It mirrors HivePress's own reCAPTCHA integration (the same three form filters core uses), so the widget is a genuine HivePress form field rather than injected HTML. All keys and widget settings (theme, language, size, appearance) come from the Simple Cloudflare Turnstile plugin, so its configuration applies automatically.

**Requires both:**

* [HivePress](https://wordpress.org/plugins/hivepress/)
* [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/)

**Protectable forms** (auto-discovered from HivePress, so captcha-capable forms from any extension appear automatically):

* Login User, Register User, Reset Password, Submit Listing, Report Listing (core)
* Confirm Booking (Bookings), Dispute Order (Marketplace)
* Write a Review, Reply to Review (Reviews), Send Message (Messages)

== Installation ==

1. Install and activate HivePress and Simple Cloudflare Turnstile.
2. Install and activate this plugin.
3. Go to **Settings → Simple Cloudflare Turnstile** and enter your Site Key and Secret Key.
4. Open the **HivePress** accordion at the bottom of that page.
5. Tick the forms you want to protect under **Protected Forms** and save.

== Frequently Asked Questions ==

= The widget does not appear on my site =

If you use a caching or JS-optimisation plugin (FlyingPress, Perfmatters, WP Rocket, ...), add these keywords to its "Exclude from Delay/Defer JavaScript" list: `challenges.cloudflare.com`, `turnstile-render.js`. The plugin registers exclusion filters for these plugins automatically, but some setups still need the manual entries.

= Can I use it together with HivePress's built-in reCAPTCHA? =

Both share HivePress's captcha field system. If HivePress reCAPTCHA keys are configured, any form protected by this plugin will show BOTH widgets and require both to pass. If you are migrating to Turnstile, remove the reCAPTCHA keys from HivePress settings. The settings panel shows a warning when this situation is detected.

= Does it work in the login / register / reset-password popups? =

Yes. The widget is added as a real HivePress form field and rendered only after the modal has finished opening, so it works reliably in the FancyBox popups, including when switching between login, register and reset password.

== Changelog ==

= 2.0.8 =
* Pre-release audit: all integration points verified against the actual HivePress and Simple Cloudflare Turnstile source code.
* Removed the `cf-turnstile` class from the widget container so an auto-render copy of the Cloudflare API (loaded by SCT for other forms on the page) can never break our widgets.
* Only one copy of the Cloudflare API is loaded when both plugins enqueue one on the same page.
* Settings-panel warning when HivePress's built-in reCAPTCHA is also configured.
* Frontend scripts are skipped when HivePress is inactive.
* Capability check added to the settings save handler; uninstall cleanup moved to uninstall.php; notices made translatable; plugin headers completed.

= 2.0.7 =
* Fixed the widget going missing when switching between the login / register / reset-password modals and back; every modal open now tears down any stale widget and renders a fresh one.

= 2.0.6 =
* Eliminated the postMessage origin error storm: modal widgets render only after FancyBox finishes opening and are removed on close.

= 2.0.5 =
* Fixed a fatal JS error in the submit handler; render is skipped until the widget container has real layout.

= 2.0.4 =
* Restored footer loading of the Cloudflare API; made the render script independent of jQuery; expanded JS-optimiser exclusions.

= 2.0.3 =
* Submission is blocked until a fresh Turnstile token is present, fixing retry-after-wrong-password failures.

= 2.0.2 =
* Widget is reset after every HivePress submission so a fresh single-use token is issued; removed turnstile.ready() for defer-safety.

= 2.0.0 =
* Rewritten to use HivePress's native captcha field system with a single dynamic Protected Forms selector and full modal support.

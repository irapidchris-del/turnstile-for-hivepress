=== Turnstile for HivePress ===
Tags: hivepress, cloudflare, turnstile, captcha, spam
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protects HivePress forms with Cloudflare Turnstile, using HivePress's own native captcha field system for full modal and AJAX support.

== Description ==

Turnstile for HivePress adds a Cloudflare Turnstile widget to the HivePress forms you choose, including the login, register and reset-password **modal popups**, and verifies the token server-side on every submission.

It mirrors HivePress's own reCAPTCHA integration (the same three form filters core uses), so the widget is a genuine HivePress form field rather than injected HTML. All keys and widget settings (theme, language, size, appearance, failure message, whitelist, failsafe) come from the Simple Cloudflare Turnstile plugin, so its configuration applies automatically.

It also tidies up the WordPress login page. Where Simple Cloudflare Turnstile is protecting wp-login.php, the widget there is placed and sized to match the username and password fields instead of overhanging them.

**Requires both:**

* [HivePress](https://wordpress.org/plugins/hivepress/)
* [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/)

**Protectable forms** (auto-discovered from HivePress, so captcha-capable forms from any extension appear automatically):

* Login User, Register User, Reset Password, Submit Listing, Report Listing (core)
* Confirm Booking (Bookings), Claim Listing (Claim Listings), Dispute Order (Marketplace)
* Submit Request, Submit Offer (Requests)
* Write a Review, Reply to Review (Reviews), Send Message (Messages)

== Installation ==

1. Install and activate HivePress and Simple Cloudflare Turnstile.
2. Install and activate this plugin.
3. Go to **Settings → Simple Cloudflare Turnstile** and enter your Site Key and Secret Key.
4. Open the **HivePress** accordion at the bottom of that page.
5. Tick the forms you want to protect under **Protected Forms** and save.

== Frequently Asked Questions ==

= The widget does not appear on my site =

If you use a caching or JS-optimisation plugin (FlyingPress, Perfmatters, WP Rocket and similar), add these keywords to its "Exclude from Delay/Defer JavaScript" list: `challenges.cloudflare.com`, `turnstile-render.js`. The plugin registers exclusion filters for these plugins automatically, but some setups still need the manual entries.

= Can I use it together with HivePress's built-in reCAPTCHA? =

Both share HivePress's captcha field system. If HivePress reCAPTCHA keys are configured, every form protected by this plugin will show BOTH widgets and require both to pass, even when that form is not ticked under HivePress Settings → Integrations. If you are migrating to Turnstile, remove the reCAPTCHA keys from the HivePress settings. The settings panel shows a warning when this situation is detected.

= Does it work in the login / register / reset-password popups? =

Yes. The widget is added as a real HivePress form field and rendered only after the modal has finished opening, so it works reliably in the FancyBox popups, including when switching between login, register and reset password.

= The widget on the WordPress login page does not line up with the fields =

It does now. Cloudflare draws the standard widget at a fixed 300px while the username and password fields on wp-login.php are 270px, and Simple Cloudflare Turnstile nudges the widget a further 15px to the left on that page, so it ended up wider than the fields and hanging over both sides of them. The widget is now placed and sized to exactly the same column as the fields, and stays fully clickable. This applies wherever Simple Cloudflare Turnstile is protecting the WordPress login, registration or password reset form, whether or not you use its HivePress forms. If your login page has been restyled to a different width, filter `tfhp_login_form_width` to the width your fields are drawn at.

= What happens if Cloudflare itself is down? =

The plugin honours Simple Cloudflare Turnstile's failsafe setting. With the failsafe enabled, protected HivePress forms temporarily submit without a challenge while Cloudflare is unreachable, exactly like SCT's own forms. Without the failsafe, submissions are rejected until Cloudflare recovers.

= How does the plugin get updates? =

It updates itself from its GitHub Releases using WordPress's native update mechanism (the update_plugins_github.com filter, WP 5.8+), with no third-party library. New versions appear on your Plugins screen with an update notice, a "View details" changelog, and one-click update, just like any other plugin. WordPress checks automatically; you can force a check via Dashboard → Updates → Check again, or the plugin's "Check for updates" link.

== Changelog ==

= 2.4.0 =
* Tidied the HivePress panel on the Turnstile settings page. The longer notes now say the same
  thing in a sentence or two, and no longer stretch the full width of the screen, where on a wide
  monitor a single line ran to a couple of hundred characters and was genuinely hard to read.
* Nothing about the captcha itself has changed: the same forms are protected, and every
  verification works exactly as before.

= 2.3.1 =
* A solved captcha is no longer wiped while you are still filling the form in. The widget was
  reset by any background request HivePress made, which on the listing submission form meant every
  photo upload and every tag suggestion, so pressing Submit could answer "please verify that you
  are human" to somebody who just had. Only the form that was actually submitted is reset now.
* The captcha is no longer shown when Simple Cloudflare Turnstile is not active. Its settings stay
  in the database when it is deactivated, so a live Cloudflare widget carried on appearing on
  every protected form while nothing checked the answer, and any submission was accepted. The
  forms are now left unprotected and visibly so, and the existing admin notice explains what to
  reinstall.
* Simple Cloudflare Turnstile's "Failsafe Mode" no longer holds up page loading. Its check on
  whether Cloudflare is reachable was run while the page was being built, and with Cloudflare slow
  that added five seconds to the page for every visitor. The answer is now looked up in the
  background.
* Deleting the plugin now also clears the update check's own leftovers and cancels its background
  update check.

= 2.3.0 =
* The Turnstile widget on the WordPress login page now lines up with the username and password fields. Cloudflare draws the standard widget at a fixed 300px, which is 30px wider than the fields WordPress gives that page, and Simple Cloudflare Turnstile nudges it a further 15px to the left there, so it hung over both sides of the fields and ran into the edge of the login box. The widget is now placed and sized to the same column as the fields, and stays fully clickable. It applies only where Simple Cloudflare Turnstile is set to protect the WordPress login, registration or password reset form, nothing else on that page is changed, and no JavaScript is added to it. Sites whose login page has been restyled to a different width can set that width with the new `tfhp_login_form_width` filter.

= 2.2.3 =
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.

= 2.2.2 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 2.2.1 =
* Fixed: the plugin's own link on the Plugins screen pointed at the community forum home page rather than the plugin's source.

= 2.2.0 =
* Deleting the plugin now keeps your settings. Your list of protected forms survives a delete and reinstall, and is only erased if you tick the new "Delete all data when this plugin is deleted" box in the HivePress section of the Turnstile settings page. WordPress shows its own warning about deleting data on the delete screen, but it does not apply here unless you tick that box.
* Renamed the internal captcha field class so it can never clash with HivePress core or a future official extension. HivePress has said it may add its own Turnstile integration, and without this an update to HivePress could have quietly replaced this plugin's widget with a different one.
* Added a Donate link to the plugin's row on the Plugins screen and to its "View details" popup.
* Update checks no longer send your site address or WordPress version to GitHub; the request now identifies only the plugin and its version.
* Corrected the author credit shown on the Plugins screen.

= 2.1.3 =
* Fixed the widget overflowing narrow modals on small phones. Cloudflare draws the standard widget at a fixed 300px, so inside tight popups (viewports around 350px and below) it ran past the modal edge and clipped. The widget now scales down to fit its container, stays fully interactive, and re-fits on rotation or resize. Verified at 320px, 360px and 390px. Found during the staging pass on a real-device viewport.

= 2.1.2 =
* Translations now load through WordPress's own just-in-time mechanism from the plugin headers, matching HivePress core and every official extension. Translate via Loco Translate into the WordPress languages folder; the bundled template file (.pot) is regenerated with the official WordPress tooling.
* Full compatibility sweep against every captcha-capable form from all 18 HivePress extensions (14 forms), all six official themes, the Social Login extension, and the Autoptimize JS/CSS optimiser.

= 2.1.1 =
* The plugin now honours Simple Cloudflare Turnstile's Cloudflare-down failsafe: when it is enabled and Cloudflare is unreachable, protected forms submit without a challenge instead of being blocked for the whole outage. The submit gate also stands down client-side if the Turnstile script never loads, letting the server decide.
* Submitting a protected form before completing the widget now shows a clear message in the form instead of silently doing nothing.
* The SCT "failure message" option now works on HivePress forms: the message appears below the widget when Turnstile reports an error and clears on success.
* Fixed the coexistence warning wording: with HivePress reCAPTCHA keys set, every Turnstile-protected form shows both captchas regardless of the HivePress form selection.
* The manual update check now distinguishes "GitHub unreachable" from "no installable release published yet".
* Added Claim Listing, Submit Request and Submit Offer to the documented form list (auto-discovery already found them).
* Added a Settings quick link on the Plugins screen; script versions now include the file modification time so updates can never serve stale JavaScript from browser caches.
* Housekeeping: author credit links to the HivePress community profile, WordPress/PHP requirement headers corrected, uninstall also removes the cached release lookup.

= 2.1.0 =
* Added self-updating from GitHub Releases using WordPress's native update_plugins_github.com filter (WP 5.8+), with no third-party library. Update notifications, "View details" changelog, one-click updates, and a "Check for updates" link on the Plugins screen.
* Updates install a fixed-name release asset with a version-less top folder, so WordPress always installs into the correct directory.
* Added a GitHub Actions workflow that builds and attaches the release asset automatically.

= 2.0.8 =
* Pre-release audit: all integration points verified against the actual HivePress and Simple Cloudflare Turnstile source code.
* Removed the `cf-turnstile` class from the widget container so an auto-render copy of the Cloudflare API, loaded by Simple Cloudflare Turnstile for other forms on the page, can never break this plugin's widgets.
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

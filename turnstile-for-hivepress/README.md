# Turnstile for HivePress

**Version:** 2.1.2
**Author:** [ChrisB](https://community.hivepress.io/u/chrisb/summary)
**License:** GPLv2 or later

Protects HivePress forms with Cloudflare Turnstile, using HivePress's own native captcha field system for full modal and AJAX support.

---

## Requirements

| Plugin | Required |
|--------|----------|
| [HivePress](https://wordpress.org/plugins/hivepress/) | yes |
| [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) | yes |
| HivePress Bookings | optional (adds Confirm Booking) |
| HivePress Claim Listings | optional (adds Claim Listing) |
| HivePress Marketplace | optional (adds Dispute Order) |
| HivePress Requests | optional (adds Submit Request / Submit Offer) |
| HivePress Reviews | optional (adds Write a Review / Reply to Review) |
| HivePress Messages | optional (adds Send Message) |

Enter your Turnstile Site Key and Secret Key in **Settings → Simple Cloudflare Turnstile** first.

---

## Setup

1. Install and activate the plugin.
2. Go to **Settings → Simple Cloudflare Turnstile** and enter your keys.
3. Open the **HivePress** accordion at the bottom of that page.
4. Tick the forms you want to protect under **Protected Forms** and Save.

---

## Protected forms

The form list is built dynamically from whatever HivePress reports as
captcha-capable, so any extension that registers a captcha-enabled form appears
automatically. With the common extensions installed the options are:

| Form | Source |
|------|--------|
| Login User | Core |
| Register User | Core |
| Reset Password | Core |
| Submit Listing | Core |
| Report Listing | Core |
| Confirm Booking | Bookings |
| Claim Listing | Claim Listings |
| Dispute Order | Marketplace |
| Submit Request | Requests |
| Submit Offer | Requests |
| Write a Review | Reviews |
| Reply to Review | Reviews |
| Send Message | Messages |

---

## How it works

This plugin mirrors HivePress's own reCAPTCHA implementation
(`includes/components/class-form.php`), but renders a Cloudflare Turnstile
widget instead of Google reCAPTCHA.

For each protected form it uses the same three HivePress hooks core uses:

- `hivepress/v1/forms/{form}/meta` - flips the form's `captcha` meta flag to true.
- `hivepress/v1/forms/{form}` - injects a real HivePress field of type `turnstile`.
- `hivepress/v1/forms/{form}/errors` - verifies the token via `cfturnstile_check()`.

Because the widget is added as a genuine form **field** (rendered inside
`hp-form__fields`), rather than as footer HTML, it appears correctly in every
context HivePress supports - including the login, register and reset-password
**modal popups** - with no per-modal special-casing.

The custom field class is declared in HivePress's own `\HivePress\Fields`
namespace (as `\HivePress\Fields\Turnstile`) so HivePress resolves the field
type `turnstile` to it directly.

### Modal rendering

Cloudflare's auto-render breaks on elements that are hidden at page load, which
is the case for modal forms (HivePress prints them hidden in the page footer).
To handle this, the Cloudflare API is loaded in **explicit** mode
(`api.js?render=explicit`, so nothing auto-renders) and `js/turnstile-render.js`
renders each widget itself:

- **Page widgets** render as soon as they become visible (IntersectionObserver,
  with a MutationObserver re-scan for AJAX-injected forms).
- **Modal widgets** render only on FancyBox's `afterShow` - after HivePress has
  moved the modal's DOM node into the FancyBox overlay - and are torn down on
  close, so every open renders a fresh, working widget.
- Form submission is gated until a fresh token is present, and widgets are
  reset after each HivePress AJAX submission (tokens are single-use).

The widget div deliberately does **not** use Cloudflare's `cf-turnstile`
class name, so that if the Simple Cloudflare Turnstile plugin loads its own
auto-render copy of the API on the same page (e.g. to protect the comment
form), it never touches this plugin's widgets. If both API copies do end up
enqueued on one page, this plugin drops its own copy and shares SCT's.

All keys, theme, language, size, appearance and server-side verification come
from the Simple Cloudflare Turnstile plugin, so its settings apply automatically.

### Using HivePress's built-in reCAPTCHA at the same time

HivePress's own reCAPTCHA (Settings → Integrations) and this plugin share
HivePress's captcha field system. If reCAPTCHA keys are configured, **every**
form protected by this plugin will show **both** widgets and require **both**
to pass, even when that form is not ticked in HivePress's own Protected Forms
list (HivePress keys its captcha handling off the shared meta flag this plugin
flips). If you are migrating to Turnstile, remove the reCAPTCHA keys from
HivePress settings. A warning is shown in the settings panel when this
situation is detected.

### Cloudflare outage behaviour

The plugin honours Simple Cloudflare Turnstile's failsafe setting. When the
failsafe is enabled and Cloudflare is unreachable, protected HivePress forms
temporarily submit without a challenge, exactly like SCT's own forms. Without
the failsafe, submissions are rejected until Cloudflare recovers. Client-side,
the submit gate also stands down if the Turnstile script never loads, so forms
are never bricked by an outage; the server remains the authority.

---

## Updates

The plugin updates itself from this repository's **GitHub Releases** using
WordPress's own native update mechanism (the `update_plugins_github.com` filter,
WP 5.8+, keyed off the plugin's `Update URI` header) - **no third-party library**.
New versions appear on your **Plugins** screen like any other plugin: update
notice, "View details" changelog, and one-click update, with no WordPress.org
listing needed.

WordPress checks automatically; you can force a check via **Dashboard → Updates →
Check again**, or the plugin's **Check for updates** link on the Plugins screen.

To always download the latest version directly:

```
https://github.com/irapidchris-del/turnstile-for-hivepress/releases/latest/download/turnstile-for-hivepress.zip
```

(Maintainers: see [`RELEASING.md`](../RELEASING.md) for the release process.)

---

## Changelog

### 2.1.2
- Translations now load through WordPress's own just-in-time mechanism from the
  plugin headers, matching HivePress core and every official extension.
  Translate via Loco Translate into the WordPress languages folder; the bundled
  template file (.pot) is regenerated with the official WordPress tooling.
- Full compatibility sweep against every captcha-capable form from all 18
  HivePress extensions (14 forms), all six official themes, the Social Login
  extension, and the Autoptimize JS/CSS optimiser.

### 2.1.1
- The plugin now honours Simple Cloudflare Turnstile's Cloudflare-down
  failsafe: when it is enabled and Cloudflare is unreachable, protected forms
  submit without a challenge instead of being blocked for the whole outage.
  The submit gate also stands down client-side if the Turnstile script never
  loads, letting the server decide.
- Submitting a protected form before completing the widget now shows a clear
  message in the form instead of silently doing nothing.
- The SCT "failure message" option now works on HivePress forms: the message
  appears below the widget when Turnstile reports an error and clears on
  success.
- Fixed the coexistence warning wording: with HivePress reCAPTCHA keys set,
  every Turnstile-protected form shows both captchas regardless of the
  HivePress form selection.
- The manual update check now distinguishes "GitHub unreachable" from "no
  installable release published yet".
- Added Claim Listing, Submit Request and Submit Offer to the documented form
  list (auto-discovery already found them).
- Added a Settings quick link on the Plugins screen; script versions now
  include the file modification time so updates can never serve stale
  JavaScript from browser caches.
- Housekeeping: author credit links to the HivePress community profile,
  WordPress/PHP requirement headers corrected, uninstall also removes the
  cached release lookup.

### 2.1.0
- Added self-updating from GitHub Releases using WordPress's native
  `update_plugins_github.com` filter (WP 5.8+) - no third-party library. New
  versions show on the Plugins screen with update notifications, a "View details"
  changelog, one-click updates, and a "Check for updates" row action.
- Updates install a fixed-name release asset built with a version-less top
  folder, so WordPress always lands the plugin in the correct directory.
- Added a GitHub Actions release workflow that builds and attaches the release
  asset automatically.

### 2.0.8
- Pre-release audit: every integration point was verified against the actual
  HivePress and Simple Cloudflare Turnstile source code (form filter cascade,
  field class contract, settings hooks, option names and verification API).
- Removed the `cf-turnstile` class from the widget container. When SCT loads
  its own auto-render copy of the Cloudflare API on the same page (e.g. for
  the comment form), it auto-renders every `.cf-turnstile` element - including
  our hidden modal widgets, breaking them. Our widgets are rendered explicitly
  via `.tfhp-turnstile` and no longer need (or want) Cloudflare's class name.
- If both this plugin's and SCT's copies of the Cloudflare API end up on the
  same page, ours is now dropped before printing so only one copy loads
  (Cloudflare recommends a single `api.js` per page).
- Added a settings-panel warning when HivePress's built-in reCAPTCHA is also
  configured: forms protected by both will show and enforce both captchas.
- Frontend scripts and resource hints are no longer output when HivePress is
  inactive but form selections remain saved.
- Hardening: explicit `manage_options` capability check in the settings save
  handler (the WordPress options flow already enforces it).
- Uninstall cleanup moved to `uninstall.php` (WordPress's preferred mechanism).
- Dependency notices are now translatable; added `Requires at least`,
  `Requires PHP` and `Domain Path` headers and textdomain loading.
- Fixed the README version (it still said 2.0.0) and corrected the stale
  `data-execution="execute"` description of how modal rendering works.

### 2.0.7
- Fixed the widget going missing when switching between login / register /
  reset-password and back. HivePress switches modals with
  $.fancybox.close() + $.fancybox.open() in quick succession; in that race
  FancyBox's afterClose can be skipped, leaving the modal's widget flagged as
  "already rendered" with a now-broken iframe, so returning to it showed no
  widget. Each modal open is now fully self-healing: it tears down any stale
  widget in that modal and renders a fresh one every time, so the widget shows
  reliably no matter how many times you switch back and forth.

### 2.0.6
- Eliminated the "postMessage origin" error storm. HivePress modals are opened
  with FancyBox, which MOVES the modal's DOM node into its overlay (and back on
  close). Rendering the Turnstile widget before/during that move broke the
  widget's iframe handshake. Modal widgets now render only after FancyBox
  finishes opening (afterShow), and are fully removed on close so the next open
  renders cleanly in the relocated node.
- The DOM observer no longer watches attribute changes (it was firing on every
  class/style change during the modal animation), removing the render storm.
- Page (non-modal) forms are unchanged: they render as soon as they're visible.

### 2.0.5
- Fixed a fatal JS error ("':submit' is not a valid selector") that crashed the
  submit handler - :submit is a jQuery-only selector and is invalid in native
  querySelector. This was breaking the submit-gating and token handling.
- Stopped the Turnstile "postMessage origin" errors by (a) debouncing the
  DOM observer so the widget no longer renders during a modal's open animation,
  and (b) requiring the widget to have real width before rendering.
- Note: the /users/request-password/ 404 seen in the console is HivePress core
  REST behaviour (it probes with a trailing slash) and is unrelated to this
  plugin.

### 2.0.4
- Fixed the widget disappearing in 2.0.3: reverted to footer loading of the
  Cloudflare API (the head/async change interacted badly with JS-optimisation
  plugins).
- Made the render script independent of jQuery so the widget still renders even
  when a JS-optimisation plugin (e.g. FlyingPress "Delay JavaScript") delays
  jQuery. The optional token-reset and modal hooks attach once jQuery appears.
- Updated/expanded the delay & defer exclusion filters for FlyingPress,
  Perfmatters and WP Rocket, and added an in-settings note listing the exact
  keywords to exclude if the widget still doesn't show.
- Kept the 2.0.3 submit-gating fix (no submit until a fresh token is present).

### 2.0.3
- Fixed the wrong-password retry failure: the form now blocks submission until a
  fresh Turnstile token is present, so a consumed/empty token is never re-sent.
  This is the same submit-gating approach Simple Cloudflare Turnstile uses for
  the WordPress login form.
- Faster widget appearance: the Cloudflare API now loads async in the document
  head (instead of deferred in the footer) and a preconnect hint is added, so
  the widget renders with much less lag.
- Removed all plugin-side console output. Remaining console messages on the page
  originate from Cloudflare's own api.js and from JS-optimisation preloading;
  they are harmless.

### 2.0.2
- Fixed the "Please verify that you are human." error on valid submissions: the
  Turnstile widget is now reset after every HivePress form submission so a fresh,
  single-use token is issued for the next attempt (tokens are consumed on
  verification, so a stale token was being re-sent on retries).
- Removed turnstile.ready() - it is incompatible with async/defer loading of
  api.js (enforced by JS-optimisation plugins like FlyingPress). The widget now
  polls for the API and calls turnstile.render() directly, which is defer-safe.
- Loads the Cloudflare API under a dedicated handle in explicit mode and excludes
  it (and the render script) from FlyingPress / Perfmatters / WP Rocket JS
  delay/defer, fixing intermittent invisible widgets and preload warnings.
- Added retries when a modal opens so the widget renders reliably during the
  modal's open animation.

### 2.0.0
- Rewritten to use HivePress's native captcha field system.
- Single dynamic **Protected Forms** selector instead of per-form toggles.
- Full modal support (login / register / reset password) via a real form field
  plus explicit visibility-based widget rendering.
- Server-side verification via the form `/errors` filter (no separate REST
  interception layer).
- Form list now auto-discovers any captcha-capable form from any extension.

### 1.x
- Earlier footer-injection + REST-interception approach (superseded).

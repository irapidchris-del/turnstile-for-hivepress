# Turnstile for HivePress

**Version:** 2.0.0
**Author:** Chris B @ HivePress Community
**License:** GPLv2 or later

Protects HivePress forms with Cloudflare Turnstile, using HivePress's own native captcha field system for full modal and AJAX support.

---

## Requirements

| Plugin | Required |
|--------|----------|
| [HivePress](https://wordpress.org/plugins/hivepress/) | yes |
| [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) | yes |
| HivePress Bookings | optional (adds Confirm Booking) |
| HivePress Marketplace | optional (adds Dispute Order) |
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
| Dispute Order | Marketplace |
| Write a Review | Reviews |
| Reply to Review | Reviews |
| Send Message | Messages |

---

## How it works

This plugin mirrors HivePress's own reCAPTCHA implementation
(`includes/components/class-form.php`), but renders a Cloudflare Turnstile
widget instead of Google reCAPTCHA.

For each protected form it uses the same three HivePress hooks core uses:

- `hivepress/v1/forms/{form}/meta` — flips the form's `captcha` meta flag to true.
- `hivepress/v1/forms/{form}` — injects a real HivePress field of type `turnstile`.
- `hivepress/v1/forms/{form}/errors` — verifies the token via `cfturnstile_check()`.

Because the widget is added as a genuine form **field** (rendered inside
`hp-form__fields`), rather than as footer HTML, it appears correctly in every
context HivePress supports — including the login, register and reset-password
**modal popups** — with no per-modal special-casing.

The custom field class is declared in HivePress's own `\HivePress\Fields`
namespace (as `\HivePress\Fields\Turnstile`) so HivePress resolves the field
type `turnstile` to it directly.

### Modal rendering

Cloudflare's auto-render skips elements hidden at page load, which is the case
for modal forms (HivePress prints them hidden in the page footer). To handle
this, the widget is rendered with `data-execution="execute"` to disable
auto-render, and `js/turnstile-render.js` renders each widget explicitly the
moment it becomes visible, using an IntersectionObserver plus a MutationObserver
(and FancyBox's `afterShow` event as a fast path). This also prevents the
`postMessage` origin errors caused by multiple hidden widgets auto-rendering at
once.

All keys, theme, language, size, appearance and server-side verification come
from the Simple Cloudflare Turnstile plugin, so its settings apply automatically.

---

## Changelog

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
  submit handler — :submit is a jQuery-only selector and is invalid in native
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
- Removed turnstile.ready() — it is incompatible with async/defer loading of
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

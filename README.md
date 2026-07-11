# Fullscreen Promo Popup — Popup Maker add-on

Auto-opens a locked promotional popup on chosen pages of a WordPress site and puts the
visitor's browser into fullscreen on their first interaction.

Built for **de-stress4wellness.com** (Astra + Elementor Pro + Popup Maker Pro).

---

## Install

1. WordPress admin → **Plugins → Add New → Upload Plugin**
2. Choose `ds4w-fullscreen-promo.zip` → **Install Now** → **Activate**
3. Go to **Settings → Fullscreen Promo** and list the pages it should run on
   (page ID, slug, or full URL — one per line). It ships pre-set to
   `/vip-access-page-private/`.
4. Refresh the target page. The promo opens by itself.

Requires Popup Maker (free or Pro) to be active. It works with both.

On activation the plugin creates a Popup Maker popup called **"VIP Fullscreen Promo"**.
Edit its content like any other popup under **Popup Maker → All Popups** — change the
headline, text, colours, add images. The one rule: **keep the `ds4w-fs-go` class on the
call-to-action button.** That class is what triggers fullscreen.

---

## The one hard constraint: fullscreen needs a gesture

Fullscreen **cannot** be triggered on page load. Every modern browser gates the
Fullscreen API behind *transient user activation* — `requestFullscreen()` must run
inside the call stack of a real click, tap, or keypress. Called on load, on a timer,
or from an async callback, it is rejected and the console logs:

> Failed to execute 'requestFullscreen' on 'Element': API can only be initiated by a user gesture.

This is a browser security rule (it exists to stop sites hijacking the screen), not a
WordPress or Popup Maker limitation. No plugin or snippet can bypass it.

**How this plugin gets the same result anyway:**

| Step | What happens | Needs a click? |
|---|---|---|
| 1 | Page loads, promo popup opens instantly | No |
| 2 | Page is locked behind the popup — no close button, no ESC, no overlay click, no scroll | — |
| 3 | Visitor clicks the call-to-action | Yes — *this is the gesture* |
| 4 | Fullscreen fires, popup closes | — |

The visitor experiences: page loads → promo blocks the screen → they click → fullscreen.

There is also a **safety net**: the first click/tap/keypress *anywhere* on the page also
triggers fullscreen, so it still works if the visitor interacts somewhere other than the
button.

---

## Browser support

| Browser | Behaviour |
|---|---|
| Chrome / Edge / Firefox / Opera (desktop) | True fullscreen |
| Safari (macOS) | True fullscreen (`webkitRequestFullscreen`) |
| Chrome / Firefox (Android) | True fullscreen |
| **Safari (iPhone)** | **No fullscreen possible** — iOS only allows it for `<video>`. Falls back to a full-viewport lock that looks the same. |
| Safari (iPad) | True fullscreen (iPadOS 13+) |

---

## Settings

**Settings → Fullscreen Promo**

- **Show on these pages** — one per line: `1438`, `vip-access-page-private`, or a full
  URL. The popup appears *only* on these pages. Everything else on the site is untouched.
- **Lock the popup** — blocks the page until the visitor clicks the CTA. Uncheck to make
  the promo dismissible.
- **Send visitor to** — optional URL to send them to after they click.
  ⚠️ Note: navigating to a new page **exits fullscreen** — a fresh document has no user
  activation, so the browser drops out. If you want the visitor to *stay* in fullscreen,
  leave this blank and reveal the offer on the same page.

---

## Files

```
ds4w-fullscreen-promo/
├── ds4w-fullscreen-promo.php        Plugin, settings screen, popup creation
└── assets/
    ├── ds4w-fullscreen-promo.js     Fullscreen + the lock
    └── ds4w-fullscreen-promo.css    Overlay dim, iOS fallback, promo styling
```

Page targeting is enforced in PHP through Popup Maker's `pum_popup_is_loadable` filter,
so the page list lives in one place (the settings screen) and can't be broken by editing
the popup's Conditions tab. **Other popups on the site are not touched.**

---

## Tested

Verified end-to-end against a real WordPress + Popup Maker 1.23 install driven by a
headless Chromium browser — 12/12 checks passing:

- Popup auto-opens on load with no click
- Close (×) button removed; ESC ignored; overlay click ignored; background scroll frozen
- `document.fullscreenElement` confirmed set after the CTA click (real fullscreen, not simulated)
- Popup closes and the lock releases after the CTA
- No popup on non-target pages
- No JavaScript console errors

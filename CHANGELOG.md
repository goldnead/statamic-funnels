# Changelog

## 1.4.0 — 2026-08-26

### Fixed — ein leerer Split-Anteil startete heimlich einen Test

`Split::share()` gab bei einem leeren Anteilsfeld **50** zurück. README und Feldhilfe sagen beide,
ein leeres Feld heiße kein Test.

Wer also eine B-Variante schrieb und den Anteil für später ließ — die naheliegendste Bedienung —
schickte ab dem Speichern **die Hälfte aller Besucher** auf eine Fassung, die er für unveröffentlicht
hielt. Nichts auf dem Bildschirm widersprach, und die Zahlen kamen zurück wie ein gewollter Versuch.

Leer heißt jetzt aus. Die Alternative wäre gewesen, das Feld pflichtig zu machen, sobald eine
Variante Inhalt hat; „aus" ist die weniger überraschende Antwort. Eine halbfertige Konfiguration soll
nichts tun, nicht etwas.

Was sich nicht ändert: ein Anteil, den jemand getippt hat, läuft weiter — festgehalten in einem
eigenen Test, weil ein Fix, der auch konfigurierte Tests abschaltet, wertlos wäre.

## 1.3.1

### Fixed

- **No funnel was walkable. Every step answered 419 Page Expired.** The step renderer used a plain
  Laravel `view()`, which does not run Statamic's cascade — and the cascade is where `csrf_field`
  comes from. So the four `{{ csrf_field }}` in the shipped template rendered to an empty string,
  every form posted without a token, and Laravel rejected it. The addon's core function, walking a
  funnel, did not work at all.

  It survived because the suite posted to the advance route directly with a hand-made session, which
  proves the route and says nothing about the page. `tests/Feature/AFunnelIsWalkableTest.php` now
  reads the token off the rendered page and posts it back, the way a browser does — the two halves
  are finally joined up.

  Verified on a running installation afterwards: entry → capture → offer → decline → next step, all
  302s.

## 1.3.0

### What's fixed

- **The offer page printed the price in English.** `1249.50` on a German site is not a badly styled
  number, it is a different one: in German the dot groups thousands. The step now carries both
  shapes — `amount_local` for the page, `amount` unchanged for anything that parses — and the
  bundled template uses the readable one. Requires `goldnead/statamic-offers` 1.2.

## 1.2.0 — 2026-08-25

### A deadline that is real

- **Countdown on an offer step**, enforced on the server. Past it the step refuses to be accepted,
  whatever a stale tab still shows. `fixed` is one moment for everybody; `rolling` is a window per
  visitor, written to their walk on first sight so a reload does not extend it.
- Declining still works after the deadline. A closed offer is not a closed funnel.
- An unreadable date is treated as **no** deadline rather than one that has passed: a typo in the
  Control Panel should leave an offer buyable, not close it for everybody.
- The shipped page ticks it with `funnels.js` — no build step, no dependency, and loaded only on a
  step that has a clock.

### Testing two versions

- **Split test per step.** A share for B and only the fields B changes. Stable per visitor,
  decided from the walk token and the step key, and **recorded onto the arrival** so the drop-off
  numbers can be read per version.
- The result shows in the editor beside the fields that configure it.
- A share of 0 or 100, or a B with nothing different in it, is not a test and is not recorded as one.

## 1.1.0 — 2026-08-25

### Landing pages come from Statamic, not from here

- **A step can point at an entry.** Then that entry *is* the page: its own template, its own layout,
  the site's own page builder. Delivered through Statamic's `DataResponse`, so password protection,
  private entries and the entry's `redirect` field keep working.
- The funnel's context lives under **one** key, `funnel`. It used to be spread flat, and Statamic
  merges view data *over* an entry's fields — a step handing over `body` blanked the `body` of the
  page it was rendering. Invisible unless the page happens to use that field name, which most page
  blueprints do.
- An unpublished entry falls back to the step's own fields rather than breaking the walk.

### Preview, with a stepper

- **Preview** in the editor walks the funnel step by step. The stepper follows the graph depth-first,
  one branch to its end and then the other; device sizes come from `config/live_preview.php`.
- Shows the **unsaved** graph, works on an unpublished funnel, and **writes nothing**: no visit, no
  step event, and no impression against an offer.
- Backed by a real `Statamic\Facades\Token`, mintable only with the funnels permission, reused per
  session and good for 15 minutes.

### Where people stop

- Every step card carries visitors, continued and the share, counted **per visitor** rather than per
  page load. A step nobody reached shows nothing; a last step shows no rate.

### Selling more on one page

- **Order bumps**: an offer's `bumps` become tick-boxes beside the order button. Only bumps the offer
  lists can be ticked, whatever the form posts.
- **Coupon codes**: a field on the offer page. The code is a string from the browser; what it is
  worth is looked up. Switch it off with `coupons => false`.

### Security

- A step's `template` can no longer name an arbitrary view of the application. Namespaces are
  refused and the location is confinable with `template_prefix`.
- The preview iframe is sandboxed, and the preview route is throttled.

### Requires

- `goldnead/statamic-offers` ^1.1, `goldnead/statamic-payments` ^1.4, `goldnead/statamic-flow-canvas` ^1.1

## 1.0.0

### Found by a reviewer before release, and worth naming

- **The editor could not add a step.** The shared node library emits a *handle*; this page treated it
  as an object, so every added node was typed `undefined`, showed a blank card, fell back to the
  ordinary kind and then failed validation on save. It looked like it worked and stored nothing —
  and the empty cards were sitting in the screenshot taken as proof.
- **An offer drew one way out instead of two.** The shared canvas registers output specs from a
  library payload; it only understood one addon's group names, and expected a grammar this one was
  not speaking. Both fixed, the second in `flow-canvas` 1.0.1 so no host can hit it again.
- **The upsell path advanced before the money arrived.** A recurring charge usually comes back
  `pending`, and moving on there is exactly the mistake this family is written against.
- A second submit started a second payment, and the first one's webhook then found nothing.
- The walk was never marked complete in the ordinary path, so `FunnelCompleted` never fired and
  "carry on where you left off" pointed at the thank-you page for ever.
- `{{ funnels:progress }}` started a walk just by being on a page — a cookie and a database row for
  every visitor and every crawler.


Initial release. Five kinds of step, a front end with a real URL per step, a record of how far each
visitor got, and an editor built on `goldnead/statamic-flow-canvas` — the same canvas the automations
editor runs on, not a copy of it.

Money goes through `statamic-payments` at the price from `statamic-offers`, and the walk advances
only when the webhook says the money arrived.

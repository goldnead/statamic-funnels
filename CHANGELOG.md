# Changelog

## 1.13.0 — 2026-09-07

**The checkout page is now told in which rhythm the buyer pays.** 1.12.0 booked instalment
purchases correctly — but the page above it still knew nothing about them, and a template can
only print what it is told. On adriangoldner.com the hard-wired line **“One-off · no
subscription”** therefore sat above a contract over three instalments.

That is not a label but a mandatory disclosure: § 312j Abs. 2 BGB wants the total price and the
term immediately above the order button. A buyer who reads “520 €, one-off” and owes 1,560 € has
not read what he is buying.

The step now hands the template `offer:plan`, or `null`:

| Key | Meaning |
|---|---|
| `interval` | as the provider writes it, “1 month” |
| `interval_label` | in words, “monthly” — translated, otherwise passed through unchanged |
| `times` | how many payments in total, `null` for a subscription without an end |
| `times_remaining` | how many are still to come after today's |
| `total` / `total_local` | the total price, machine-readable and for humans |
| `trial_days` | trial period, if one is running |

What is asked is the **catalogue**, not the offer: that is where the payment fetches its price,
and a page naming a rhythm other than the one being charged would be exactly the error this
disclosure is meant to prevent. For a subscription without an end no total is given — it is
only settled once it is cancelled.

### The test that skipped itself

`PriceReadsAsGermanTest` bailed out when `ext-intl` was missing. That is precisely the
environment running on adriangoldner.com, and the checkout there read “520.00 €”. The fallback
in `statamic-offers` 1.8.2 now knows the common notations itself, so nothing is skipped here any
more: the test has to bite exactly where it used to stay silent.

## 1.12.0 — 2026-09-07

**An instalment offer in the checkout was charged once and counted as paid.** The failure looked
like a sale, which is the worst form of it: with “3 × 520 €” exactly 520 € moved, access was
granted in full, and the two missing instalments turned up nowhere. No error message, no open
receivable, no log entry.

The cause was not in the catalogue. `statamic-offers` 1.8.0 hands out an offer's plan correctly;
the checkout simply never asked for it. `AdvanceController::offer()` called `Checkout::start()`,
which knows nothing about payment rhythms — and the intent from which the webhook later builds a
subscription agreement cannot be attached by a calling route either
(`PaymentDetails::RESERVED_META`).

If the offer carries an `interval`, the purchase now goes through `Subscriptions::start()` (new
in payments 1.20.0: it takes a cart, not just a handle). The cart travels along; the follow-up
charges debit only the offer's amount, so an order bump next to it is bought once and not again
with every instalment.

Two edges belong to this:

- **The one-click path over the saved card does not apply to instalments.** `FollowUp::accept()`
  charges once and creates no agreement — it would be the same silent failure again, one method
  further up. The buyer enters their details one more time here; that is the price for the
  agreement actually coming into existence.
- **If the installation cannot do agreements** (no subscription-capable provider, or mandates
  switched off), the instalment option is refused rather than charged once. Someone who read
  “3 × 520 €” and paid 520 € once has not bought the same thing.

### The test double could do less than the provider

The same story twice, both fixed here. `Tests\Support\FakeGateway` was only a `FollowUpGateway` —
`Subscriptions::available()` therefore returned false, and the whole instalment branch was
unreachable from any test in this package, while Mollie walks it in production. And `markPaid()`
returned no mandate although Mollie creates one on a first payment; since payments 1.19
`SavedCard` names the card digits only when a mandate is on the row.

### Version constraints raised

`statamic-offers` from `^1.2` to `^1.8`, `statamic-payments` from `^1.17.1` to `^1.20`. The old
constraint was not merely too low, it falsified the tests: `CheckoutOrderTest` ran against a
vendored offers 1.2.0 and claimed that an offer without a withdrawal notice
(Widerrufsbelehrung) of its own shows none. Since offers 1.6.0 the configuration carries a
statutory default notice, and on adriangoldner.com it has been in the checkout for weeks. The
test now measures what actually ships. **Nothing changes about the order above the order
button** — the guard for that stands unchanged.

Along with it, an ignore entry in `phpstan.neon` was removed whose finding has not existed since
“a step's page selection is Statamic's entries fieldtype”.

## 1.11.0 — 2026-09-05

### The funnel index is a Statamic table (F24)

Adrian on 03.09.2026: “this should be a typical Statamic table too, not like this.” The list was
a stacked, hand-built set of cards: no column headers, no sorting, no multiple selection, no row
menu, and seven funnels filled the screen. It now runs on core's `<Listing>` in client-side mode
(`:items`), with the columns Title (handle underneath), Status, Steps and Visits. Sorting works
from every column header and really takes effect, because the raw data is already on the page.

The file's header comment justified the hand-built version by calling a `Listing` “scaffolding
around six rows”. That held for the server-driven mode with paging, saved views and column
preferences — not for `:items`, which brings exactly the table and demands none of it.

### Deleting is an action, not a button of its own

“Edit” and the public link sit in the row's “…” menu. Deleting comes from a real Statamic action
(`statamic_funnels_delete_funnel`) through an `ActionController`. The same code therefore serves
one row and twenty selected ones: the multiple selection is not a checkbox column without effect
but really clears up, and confirmation, refusal and message come from the Control Panel instead
of from a replica inside the addon.

The delete route `DELETE /cp/utilities/funnels/{funnel}` and the addon's own confirmation dialog
are gone; anyone who called them directly now uses `POST /cp/utilities/funnels/actions`.

## 1.10.0 — 2026-09-05

Three findings from Adrian's walkthrough on 03.09.2026.

### Funnels in the sales section of the sidebar (F36)

The screen is registered as a Statamic utility and therefore sat under “Utilities”, between
Cache and PHP Info. It now hangs in the sales section that `statamic-payments` names with
`Cp\SuiteNav::section()`: the same section as Payments, Offers and Products, because Statamic
does not translate section names and two spellings would produce two half-filled sections. Route
and permission stay; the entry under “Utilities” is unhooked, otherwise the screen would appear
twice.

`Cp\SuiteNav` only exists from `goldnead/statamic-payments` 1.18.0 on, while the constraint still
allows `^1.17.1`. The call therefore sits behind `class_exists()`, as in `statamic-booking`: with
an older payments the screen gets a section of its own, “Funnels”, instead of a
`Class not found` while the whole CP navigation is being built. The shared sales section exists
from payments 1.18.0.

### Fixed — exactly one entry step (F27)

A funnel has exactly one entry step. Until now the editor allowed a second one, and which of them
counted was decided silently by row order: `entryStep()` takes the first node of type `entry`.
Three places, because one is not enough:

- The validation in `update()` rejects a second entry step and saves nothing.
- The `kinds` descriptor carries `unique`, which the shared library in `flow-canvas` has long
  evaluated: when appending, unique kinds drop out; when replacing, only they remain. The field
  had simply never been set here.
- The “Entry” tab disappears as soon as one exists, instead of standing there with a 0. When
  replacing, it stays visible.

### Fixed — A/B fields only when the test is switched on (F25)

The three variant fields carry `visible_when` on `split_share`, the test's switch (empty means no
test, see `Support\Split::share()`). Only the display is hidden, never the value.

Internally: `StepStats` reads the aggregate column `visitors` through `getAttribute()` instead of
as a property. No behavioural difference, one PHPStan finding fewer. PHPStan now runs without a
finding: `treatPhpDocTypesAsCertain: false` as in the suite's other addons, plus two
`ignoreErrors` for `QueryBuilder::whereIn()` and `Entry::in()`, which Statamic 6 does not declare
in its contracts although every implementation carries them.
`tests/Fakes/insights-table-metric.php` brought up to insights 1.2.1 (`bucketed()` sorts the
buckets explicitly).

## 1.9.1 — 2026-09-02

### Fixed — the one-click notice was missing for exactly the first-time buyer

After the return from the payment provider the upsell step renders before the webhook has set the
payment to `paid` — in the purchase test on 02.09.2026 less than a second lay between the two. In
that second `FollowUp::eligible()` held the preceding payment to be unpaid, and the page said
nothing about the saved card. Only a reload brought the sentence. In the live version this
affects **every** first-time buyer.

The missing sentence is not the worst of it: by the time of the click the webhook has arrived,
the action takes the one-click path, and something is charged that the page never announced.

`SavedCard` therefore separates the two questions it used to answer as one. The **action** still
asks `FollowUp::eligible()`, `isPaid()` included. The **announcement** hangs on the mandate:
`customer_reference` is written before the jump to the provider, in the same block that sets
`sequenceType: first` — so on the return the column is filled, entirely without a query back to
the provider. If the four digits are still missing in that second, `saved_card_unnamed` takes
over.

The remaining direction of error is the harmless one: announced, still not paid at the moment of
the click, therefore the ordinary checkout instead of one click. An inconvenience, not a charge.
Nothing at all is announced from a failed, expired or cancelled payment.

### Fixed — “from your  •••• 9996”

The card sentence hung on the four digits alone. If the provider names the digits but not the
brand — the normal case with wallet payments — a gap appeared in the middle of the sentence. Now
three cases: brand and digits, digits only (new key `saved_card_digits`), neither of the two. It
is said in all three, only with fewer details.

### Fixed — `payment_items.offer` on the upsell

The one-click path now passes `offer_handles` to `FollowUp::accept()`, the way the checkout path
receives it through the catalogue. Without that the column stayed empty on exactly the upsell
row, and the upsell report in `statamic-insights` attributed the revenue to no offer.

**The dependency therefore rises to `goldnead/statamic-payments: ^1.17.1`.** That is not
convenience but necessity. `PaymentDetails::ALLOWED` only knows `offer_handles` from 1.17.0 on,
and `FollowUp::accept()` calls `PaymentDetails::from()` as its first line — against 1.16 the
one-click purchase therefore ended in an uncaught `InvalidArgumentException` on a public POST
route. Against 1.17.0 the key is accepted but not written; the column would stay silently empty.
Only 1.17.1 does both.

### Fixed — what the visitor types arrived raw in the mail's HTML

Up to 2.2.x `statamic-email-templates` inserted every value unchanged, and this addon handed it
`visitor.name` and `contact.*` straight from the form. A name with markup in it therefore became
markup in a mail. From email-templates 2.3.0 the sibling escapes on its own; without the
adjustment here that would have turned into the opposite error — doubly escaped order lines and
an `&amp;` in the subject line.

Three changes that belong together:

- **The body is escaped, the subject is not.** `FunnelMailRenderer::render()` calls the merge
  with escaping for the HTML body and without it for the subject line. A subject is not HTML;
  there `Müller &amp; Söhne` would be visible damage rather than protection.
- **`order.lines` stays markup, declared rather than tolerated.** The value is built here from
  `e()`-escaped parts with `<br>` between them and passed as raw per call through the new `raw`
  parameter of `MergeVariables::apply()` (`FunnelMailRenderer::RAW_VARIABLES`). The route “no
  markup in the value at all” fails on the substance: `MergeVariables` knows only flat scalars
  and no loop, and in an HTML mail only markup separates lines — a `\n` collapses when rendered.
  The key therefore explicitly does **not** belong in `MergeVariables::RAW_VARIABLES`, where it
  would be raw for every consumer of the sibling.
- **Older siblings stay runnable.** `MailTemplates::merge()` passes the new arguments
  positionally, not by name: against 2.2.x PHP silently ignores additional positional arguments,
  whereas an unknown named argument would be a fatal in the middle of a send.

### Fixed — the test fake stayed green whatever the sibling did

`tests/Fakes/email-templates-facade.php` defines a `MergeVariables` of its own when the real
class is missing — and in this suite it always is. The copy lagged behind, so the suite would
never have seen the error above. The fake is now brought up to the real class's signature and
behaviour (`$escape`, `$raw`, `RAW_VARIABLES`), with a note that it has to be kept in step. Plus
a test that pins exactly the three properties: the name from the form escaped, order lines with
an intact `<br>` and escaped exactly once, the subject raw.

## 1.9.0 — 2026-09-02

### One image per page on the canvas

After saving, every **page** step is photographed, and the card on the canvas shows the image as
a 16:10 tile above the title. The graph becomes a map: you recognise a station by what its page
looks like, not only by its name. Mail nodes get no image, they have no page.

- The photo is taken **not when the editor loads** but in a job (`RenderStepThumbnail`, queued,
  unique per step until it starts; on the `sync` queue after the response) after saving. It loads
  the page through the same preview route as the preview panel, with a `PreviewToken` that is
  deleted again after the photo, and writes nothing: no visit, no event, no impression.
- A step is only photographed again when something about it has changed (a fingerprint of type,
  label, slug and configuration). `php please funnels:thumbnails {funnel?} {--force}` renders
  afterwards, for instance after a change to the entry behind a page.
- Stored on `thumbnails.disk` (default `public`) under `funnels/thumbs/<funnel>/<node_key>-<hmac>.png`
  (16 hex characters of HMAC under `APP_KEY`, so that a draft's images cannot be guessed from the
  editor URLs),
  with the path and `rendered_at` in `funnel_steps.config['thumbnail']`. The key belongs to the
  server: a second save before a reload does not throw the image away. A deleted step takes its
  image with it, a deleted funnel its folder.
- **The renderer sits behind a contract** (`Contracts\ThumbnailRenderer`). Browsershot when
  `spatie/browsershot` is installed **and** a Chromium is found (on `PATH`, in the usual Mac
  paths, or under `thumbnails.chrome_path`). Otherwise a `NullRenderer` that does nothing and
  says so once per process as a `notice`. Without a renderer: **no grey placeholder**, the cards
  look as they did before, and the editor's sidebar quietly says “thumbnails need Chromium (see
  the docs)”.
- **No cookie banner in the image.** The browser sets `thumbnails.cookies` (`name => value`)
  before loading; left empty and with `goldnead/statamic-consent` installed, that addon's consent
  cookie travels along with every service granted, in the format of its script
  (`{ v, granted, ts, how, id }`, URL-encoded). `thumbnails.hide_selectors` hides selectors
  before the photo, for a banner that no cookie quiets. Otherwise every tile shows the same
  banner, and a map on which every station looks alike is not one.
- Config `thumbnails`: `enabled`, `disk`, `width`, `height`, `chrome_path`, `cookies`,
  `hide_selectors`.
- Requires `goldnead/statamic-flow-canvas` ^1.3, which gives the nodes the `thumbnail` field.

## 1.8.0 — 2026-09-02

### Mail nodes on the canvas

A new node `mail` that **hangs on an output and is never entered**. One rule: the mail goes out
when the visit takes the output it hangs on — `default` means continued (page) or submitted
(form), `accepted` means paid (only via webhook), `declined` means declined. The completion has
no output; “the walk is over” is the output that leads there. The walk passes the mail by —
`nextStep()` skips it, the stepper does not count it as a station, and neither does the drop-off
statistic.

The template comes from `statamic-email-templates`, the delay is a queue delay (no second
scheduler, no forced dependency on `statamic-automations`; the reasoning is in the README), and
the recipient is the visit or a fixed address. Once per visit and node, enforced by a unique
index. Every mail leaves a row in `funnel_mail_deliveries` (triggered / delivered / failed with a
reason), and the editor shows the three figures on the node. Preview: the stepper puts the mail
directly behind its step, and the iframe shows the rendered mail with sample data
(`GET /f/{funnel}/_preview-mail/{node}?token=…`).

New event `FunnelOfferDeclined`. The inspector shows “attach mail” per output and, where nothing
continues yet, “attach step” — the canvas's “+” only exists on outputs without an edge. A node
chosen through the “+” of an existing edge is now inserted between the two (before, it stayed
unconnected); a mail there attaches itself as a branch on the same output.

### Purchase and newsletter kept apart

The capture step gains a newsletter checkbox under the email field (`newsletter`: no checkbox /
offer the checkbox, never pre-ticked; `newsletter_label` configurable). The visit carries
`meta['newsletter']` with the timestamp and the wording that was shown; a later step does not
turn a “yes” into a “no”. Towards LeadHub: the address becomes a contact **without** consent,
only the checkbox sets it (through `ContactResolver`, because `ingest()`/`create()`/`update()`
know nothing about consent), and a purchase carries the tag `kunde` — a new listener
`TagBuyerInLeadHub` on `FunnelOfferAccepted`. In passing: `LeadHubBridge::capture()` passed the
name under `name`, which `SourceEvent` does not read at all; now under `contact.full_name`.

### Account step after the purchase

A new page node `account`: shows the visit's email address (not editable), asks for a name and a
password (min. 8, repeated), creates the Statamic user or updates the one with that address —
never a duplicate — logs them in (`login_after`) and moves on. “Later” continues without an
account if `optional` allows it (default: yes). Without an address on the visit there is no
account, and a foreign browser gets a 403 as at every step. No mailing of its own.

Side finding: `FunnelWalk` read the request from the constructor. Laravel holds the controller
instance on the route object; if the same route object serves a second request (test suite,
Octane), the captured request carried the first visitor's cookie into the second visitor's walk.
The current request is now read instead.

### The capture step reads the field library

`billing` has a fourth level, `offer`: the fields follow from `Offer::checkoutFields()` of the
next offer behind the step (forwards through the graph, past pages and mails), with labels,
types, options and required flags from `Offers::fieldLibrary()`. Validation comes dynamically
from the library, storage is 1:1 in `visit.meta['billing']`. If the library or the offer is
missing, the step behaves like `minimal` and writes that to the log. Both methods only behind
`method_exists`; tests run through resolver fakes until the installed offers has them.

### Consent, withdrawal and access window on the payment

`confirmed` was examined and discarded. The time and wording of the consent now go onto the
payment (§ 356 Abs. 5 BGB): as `consent_at`/`consent_text` if the installed payments knows those
keys, otherwise under `meta['consent']`. The wording comes from `Offer::withdrawalTerms()` with
the version in square brackets (fallback: the language file), and with a VAT ID in the billing
details the B2B text. The checkout page shows the short withdrawal notice above the button,
sends the wording it showed back as `consent_text`, and the server rejects a differing version
(“please reload the page”). The terms are frozen under `meta['withdrawal']`, the access window
(`Offer::accessWindow()`) under `meta['access']`. Both purchase paths, checkout and saved card.
Older neighbours without these methods: nothing thrown, left out.

## 1.7.0 — 2026-09-01

Added after the fact: version 1.7.0 shipped without an entry. It brought the **billing details**
to the capture step (`billing` minimal / name / full, the address lands on the payment as
`meta.address` so that an invoice is created above 250 €) and the **order summary** to the
thank-you page (`order` with line items, total, reference).

## 1.6.1 — 2026-09-01

Wording of the card notice. “from your Mastercard ending 9996” read on the page like an amount of
money; it now says “from your Mastercard •••• 9996”. Text only, no behaviour.

## 1.6.0 — 2026-09-01

### The second person at the same computer paid on the first one's card

A funnel visit hangs on a cookie with a thirty-day lifetime. Anyone who walked the same funnel a
second time — same machine, different person, different address — got no card form any more:
`AdvanceController` found the first walk's payment on the visit, took that for “the same buyer is
taking something else” and let the stored mandate be charged. In the process `FollowUp`
overwrote the freshly entered address with the old one. Access, invoice and confirmation mail ran
to the first buyer; the second person had paid and received nothing. Reproduced on a staging
installation on 31.08.2026, with a real payment.

Two places, the same wrong assumption that a device is a person:

- **The saved card.** New in `Support\SavedCard`, and in *one* place because two need it: the
  page that has to say so beforehand, and the action that does it afterwards. It asks
  `FollowUp::eligible($payment, $visit->email)` — that needs `goldnead/statamic-payments ^1.16`,
  hence the raised requirement.
- **The memory of the purchase.** If the capture step enters a different address than a moment
  ago, the walk starts over: `payment_id` and `meta['payments']` are dropped. Without that the
  error would merely have moved — instead of charging a stranger's card, `pendingPaymentFor()`
  would have taken the first buyer's long-paid purchase for this one and waved the second person
  through **without any payment at all**.

Nothing changes for the genuine repeat buyer: same address, same one-click upsell.

### The page now says what it charges

The offer step gains `funnel:saved_card` with `last4` and `label`, filled from
`payments.card_last4` / `card_label` (new in payments 1.16). The shipped view writes the sentence
immediately above the order button — § 312j Abs. 3 BGB wants the essential details exactly there,
and the payment method is one of them. New language keys `saved_card_named` and
`saved_card_unnamed`; without card details (bank transfer, older records) the sentence stands
without the four digits rather than being missing. Anyone writing their own view has to output
`saved_card` themselves.

### Tested

`tests/Feature/SavedCardTest.php`, and the test double `FakeGateway` can now follow up — until
this point the whole branch was unreachable from this package's tests, which is the reason the
error got as far as a real payment.

## 1.5.1 — 2026-08-30

### Fixed — the preview in the Control Panel always ran into a CSRF error

`PreviewPanel` took the CSRF token from `<meta name="csrf-token">`. **Statamic 6's Control Panel
does not render that tag** — the token sits in the JS configuration the layout writes out
(`Statamic.$config`, `StatamicConfig.csrfToken`). So an empty string was read, and every preview
call came back as *CSRF token mismatch*. The preview was therefore never usable on any
installation.

It went unnoticed because the preview's feature tests run in Laravel's test environment, where
the CSRF check is switched off: the endpoint was fine the whole time, only the call from the
browser never arrived. `statamic-marketing` has always read the same token through
`Statamic.$config`; that is now the first route here as well, with the meta tag as the last
fallback.

## 1.5.0 — 2026-08-29

### Added: this addon's figures appear in Insights

From 1.1.0 `statamic-insights` is no longer a revenue report but the family's reporting layer: an
addon registers what it can count and gets the period, the comparison against the period before,
the chart, the breakdowns and two finished screens in return.

The coupling is optional in **both** directions. Without Insights nothing here is missing;
without this addon only its own group is missing over there. `suggest`, never `require`.

Every figure follows the contract's house rules: **null is not zero** (a rate with no denominator
has no answer and does not print 0 %), `available()` decides existence and never the data, gaps
in a series are filled by Insights rather than by the metric, and a filter a metric does not
understand is ignored rather than fatal.

Four figures: entries, completions, completion rate, and time to completion.

None of the five tables carries a brand column — checked against the migrations rather than
assumed — so there is nothing to narrow here.

## 1.4.0 — 2026-08-26

### Fixed — an empty split share secretly started a test

`Split::share()` returned **50** for an empty share field. The README and the field help both say
that an empty field means no test.

So anyone who wrote a B variant and left the share for later — the most obvious way to use it —
sent **half of all visitors** onto a version they believed to be unpublished, from the moment
they saved. Nothing on the screen contradicted them, and the numbers came back looking like an
intended experiment.

Empty now means off. The alternative would have been to make the field required as soon as a
variant has content; “off” is the less surprising answer. A half-finished configuration should do
nothing, not something.

What does not change: a share someone typed keeps running — pinned in a test of its own, because
a fix that switched off configured tests as well would be worthless.

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

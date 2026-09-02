<!-- statamic:hide -->
# Statamic Funnels
> A path a visitor walks: pages, forms, offers and payments in one flow, drawn on a canvas.
<!-- /statamic:hide -->

## Requirements

Statamic 6 · PHP 8.2+ · a database ·
[`goldnead/statamic-payments`](https://github.com/goldnead/statamic-payments) ·
[`goldnead/statamic-offers`](https://github.com/goldnead/statamic-offers)

## What a funnel is, and why it is not an automation

An **automation** is *event → action*: something happened, do something.

A **funnel** is a path somebody is actually standing on. That difference is small in words and large
in what it needs: a page, and a memory of how far each person got. Neither belongs in an automation,
which is why this is its own addon rather than four new node types in that one.

The editor, though, is literally the same code —
[`goldnead/statamic-flow-canvas`](https://github.com/goldnead/statamic-flow-canvas), the canvas the
automations editor runs on. One editor, consumed twice, so the two cannot drift apart.

## Installation

```bash
composer require goldnead/statamic-funnels
php artisan migrate
php artisan vendor:publish --tag=statamic-funnels
```

Funnels live under **Utilities → Funnels**.

## Usage

### The five kinds of step

| Step | What it is | Ways out |
|---|---|---|
| **Entry** | The first page. Lives under the funnel's own URL. | one |
| **Form** | A page with a **native Statamic form** on it. | one |
| **Page** | A plain page: a thank-you, an explanation, a delivery notice. | one |
| **Offer** | Where money can change hands. | **accepted** and **declined** |
| **Finish** | The end. Marks the visit complete. | none |

One entry per funnel, because a path has one beginning.

**Declining is an answer, not a failure.** Most visitors decline; a funnel that treats that as an
error has nowhere to send them, so the canvas draws both branches and the editor nags about neither.

A sixth step, **Account**, is a page after the purchase: see [The account step](#the-account-step).
And there is one kind of node that is **not** a step: a **Mail** hangs off a step and is never
entered. See [Mail nodes](#mail-nodes).

### The account step

Until now the user came into being silently — built from the address, and nobody told the buyer
it existed. The account step is Kajabi's order: checkout → upsell → name and password (skippable)
→ library. It shows the visit's email read-only, asks for a name and a password (min. 8, repeated),
creates the Statamic user — no role, no group, never super — logs them in (`login_after`, on a
fresh session) and carries on. **If an account with that address already exists, nothing is
changed and nobody is logged in:** the page says so and points to the password reset. The
address came from a form anybody can fill in; setting a password on an existing account from
here would be an account takeover with the administrator's address. *Later* carries on without an account when `optional` allows it,
which it does by default — a mandatory account is an abandoned checkout after the checkout.

The address is the visit's, never the form's: to get an account for an address you have to have
walked the path that gave it, and that path hangs on a cookie only your browser has. A browser
that never reached the step gets a 403 like on every other step. No mail is sent from here; the
access mail is the site's.

### Mail nodes

A mail is drawn on the same canvas as the steps, as a branch beside the way on — and that is the
whole design decision. A mail that a visitor walked *through* would be a station without a page:
the URL flickers, the back button breaks, the drop-off report counts a step nobody stood on, and a
delayed mail cannot exist at all. So the node hangs off a step's **output** and the walk goes past
it. **One rule: the mail goes out when the visitor takes the output it hangs off.** It is the
same rule the canvas draws — an edge on the handle labelled "submitted" fires on submit.

| Edge from the parent step | The mail goes out when… |
|---|---|
| `default` | the visitor **carries on**: *continue* on an entry or page, *submitted* on a form |
| `accepted` | the offer is **paid** — when the webhook says so, never on the click |
| `declined` | the offer is **declined** |

The Finish step has no output, so nothing hangs off it: "the walk is complete" is the output
that *leads to* the finish, and that is where a completion mail goes. A welcome mail belongs on
the form's *submitted* output or later — before that the walk has no address yet, and the row
says so.

Each mail names a template from `goldnead/statamic-email-templates` (published entries of
`et_templates`), an optional delay (minutes, hours, days), a recipient (the visitor, or a fixed
address for an internal notification) and an optional subject. Placeholders come from the walk:
`{{ visitor.name }}`, `{{ visitor.email }}`, `{{ contact.salutation }}`, `{{ funnel.title }}`,
`{{ funnel.continue_url }}`, `{{ step.label }}` and, after a paid purchase, `{{ order.total }}`,
`{{ order.reference }}`, `{{ order.lines }}`.

**Once per visit and node.** A provider that redelivers a webhook three times triggers the purchase
mail once; the uniqueness is a database index, not a check that a second process could overtake.

**Every mail leaves a row.** `funnel_mail_deliveries` records triggered, delivered and failed with a
reason, and the editor shows the three numbers on the node. A mail before the form step has no
recipient yet and says so; a node without a template says so; a site without the templates addon
says so. Nothing here fails silently — that is the failure this table exists to end.

**Why the queue and not the automations engine.** The first plan handed the send to
`statamic-automations` and its scheduled-jobs table. That would have forced every funnel with a
mail on it to install the automations addon, and built a second place where a mail is "delayed".
Laravel already has one: the delay is `dispatch()->delay()` on the site's own queue. One queue in
the house, no second scheduler, and a funnel works without automations. **That needs a queue
that runs:** with `QUEUE_CONNECTION=sync` the delay is ignored and the mail goes out inside the
visitor's request — fine for trying it out, not for "three days later". A multi-step sequence over
days remains an automation; a mail node is one mail at one moment.

**Attaching one.** Pick *Mail* from the library on an output's "+", or from the "+" on an existing
edge (a mail added there hangs off the same output; the edge stays), or use *Attach mail* next to
an output in the step's inspector. The preview's stepper lists a mail right behind the step it
hangs off and shows the rendered mail with sample data.

### Newsletter and purchase, kept apart

A form step shows a newsletter checkbox **under the email field**, never pre-ticked: the step's
`newsletter` setting is *no checkbox* or *offer it, unticked* — there is no pre-ticked option,
because a pre-ticked consent is not one in the EU (Planet49). The sentence beside the box is
configurable (`newsletter_label`) and is recorded with the tick, so the visit carries
`meta['newsletter'] = {opted_in, at, text}`.

Towards LeadHub the two facts stay two facts. The address becomes a contact **without** consent;
only a ticked box hands over consent — through LeadHub's `ContactResolver`, the one path that
knows the flag (`LeadHub::ingest()` and `create()` do not carry consent). A purchase tags the
contact `kunde` and grants nothing else: having bought is not having agreed to mail.

### Fields from the offer

The form step's `billing` setting has a fourth mode, **fields from the offer**. The step then
searches the graph forward for the next offer step (past pages, past mails), asks that offer which
checkout fields it wants (`Offer::checkoutFields()` in `statamic-offers`) and takes labels, types,
options and `required` from the site-wide library (`Offers::fieldLibrary()`). Validation is built
from the library; the values land in `visit.meta['billing']` under their own keys, one to one, so
the checkout and the invoice read the same definition the form did. Without the library, or
without an offer behind the step, the step asks for the email only and the log says why.

### Consent at the order button

German law (§ 356 Abs. 5 BGB) lets the right of withdrawal lapse for digital content only after an
explicit agreement, and an agreement without a timestamp and the wording that was agreed to is
worthless the moment the wording changes. The order button therefore records both.

The wording comes from the offer's withdrawal terms (`Offer::withdrawalTerms()` in
`statamic-offers`, when the installed version has them) with its version appended in brackets,
or from this addon's language file otherwise. A buyer with a VAT id in their billing details gets
the B2B wording. The page sends the wording it showed back as a hidden `consent_text`; the server
compares it with the wording in force and refuses a stale or altered one with "please reload".
A custom template that does not send the field can still order; the server then records the
wording in force.

What goes **into** the payment, on both the checkout and the saved-card path: `consent_at` and
`consent_text` as columns where the installed `statamic-payments` accepts them, otherwise under
`meta['consent']`; the terms frozen as they were at purchase under `meta['withdrawal']`; and the
offer's access window (`Offer::accessWindow()`) under `meta['access']` for whatever grants access
later. Nothing is thrown at an older sibling — keys it does not know are left out.

### Landing pages: point a step at an entry

A step can name a **Statamic entry**, and then that entry *is* the page: its own template, its own
layout, its own page builder, whatever sets and fields the site has defined. The funnel does not
render it, it delivers it, through Statamic's own response — so password protection, `private`
entries and the entry's `redirect` field all keep working.

There is no landing page builder in this addon and there should not be one. Statamic has Bard,
Replicator and the blueprint the site already uses; a second, worse builder inside an addon is the
wrong thing to maintain.

The funnel adds its context under a single key, `funnel`:

```antlers
{{# In any template a step points at. #}}
{{ if funnel:action }}
    <form method="POST" action="{{ funnel:action }}">
        {{ csrf_field }}
        <button type="submit">Weiter</button>
    </form>
{{ /if }}
```

| Available | What it is |
|---|---|
| `funnel:handle`, `funnel:title` | The funnel |
| `funnel:step:key`, `:type`, `:label`, `:slug` | The step being shown |
| `funnel:action` | Where the form posts to move on |
| `funnel:offer` | The offer on an offer step, price included |
| `funnel:visit:name`, `:email` | What the visitor has told you so far |
| `funnel:preview` | `true` when the Control Panel is looking |

**One key, not a dozen loose ones**, and that is load-bearing rather than tidy: Statamic merges view
data *over* an entry's own fields, so a funnel that handed over a flat `body` would blank the `body`
of the very page it was rendering. Namespacing makes the collision impossible.

An entry that is unpublished falls back to the step's own fields rather than breaking the walk. One
pulled into draft should not take a running funnel down with it.

### Looking at it before it is live

The editor has a **Preview**. It walks the funnel step by step: the stepper follows the graph, one
branch to its end and then the other, with the device sizes from `config/live_preview.php`.

What it shows is the graph **on screen**, not the one in the table, so an unsaved headline is
visible immediately. It works on an unpublished funnel, which is the point. And it writes nothing:
no visit, no step event, and no impression against an offer — an editor clicking through their own
funnel twenty times must not move the acceptance rate the offers screen is judged by.

Behind it is a real `Statamic\Facades\Token`, minted only by somebody with the funnels permission,
reused across a session rather than reissued per keystroke, and good for fifteen minutes.

### A picture of every page

After a save, every **page** step is photographed and its card on the canvas shows the picture as a
16:10 tile above the title. A graph becomes a map: you recognise a station by what its page looks
like, not only by its name. Mail nodes get no picture; a mail has no page.

It needs a browser, and the addon does not bring one. Two things have to be true:

1. `spatie/browsershot` is installed (`composer require spatie/browsershot`, plus the `puppeteer`
   npm package it drives, as its own README describes).
2. A Chromium can be found: on `PATH` as `google-chrome`, `google-chrome-stable`, `chromium`,
   `chromium-browser` or `chrome`, in the usual place on a Mac, or named in
   `statamic-funnels.thumbnails.chrome_path` (`FUNNELS_CHROME_PATH` in `.env`).

Without both, nothing is rendered, the cards look exactly as they always did — **no grey
placeholder**, a card without a picture is a card, a grey box is a fault — and the editor's side
panel says quietly that thumbnails need Chromium. The log carries one `notice` per process.

The picture is not taken when the editor loads. It is taken by a queued job (`RenderStepThumbnail`,
unique per step) after the save, through the same preview route the preview panel uses, with a
`PreviewToken` that is deleted once the picture exists. Like the preview it writes nothing: no
visit, no step event, no impression. A step is photographed again only when something about it
changed — type, label, slug, configuration. For the change that cannot be seen from the step, an
edited entry or template behind it, there is a command:

```bash
php please funnels:thumbnails                  # every funnel, only what is out of date
php please funnels:thumbnails fruehlingskurs   # one funnel, by handle or id
php please funnels:thumbnails --force          # everything, again
```

Pictures live on `thumbnails.disk` (default `public`, so run `php artisan storage:link`) under
`funnels/thumbs/<funnel-id>/<node_key>-<hmac>.png`, 640 × 400 by default — the sixteen hex
characters are an HMAC under `APP_KEY`, so a draft's pictures are not guessable from the editor's
URLs. Path and `rendered_at` are kept
in the step's `config['thumbnail']`; that key belongs to the server, so a second save before the
page was reloaded does not throw the picture away. A deleted step takes its picture with it, a
deleted funnel its folder.

Two things to know about the host. The browser loads the page through `APP_URL`, so that URL has to
resolve from where the queue worker runs. And on `QUEUE_CONNECTION=sync` the pictures are taken
after the save's response has gone out, in the same PHP process, a second or two per page — the
editor has its "saved" back first, but the process stays busy; a real queue is the better place.

**The cookie banner.** A fresh browser has consented to nothing, so without help every picture is a
picture of the banner and the map shows the same card six times. Two knobs:

- `thumbnails.cookies` (`name => value`) are set for the page's host before it loads. Left empty,
  and with `goldnead/statamic-consent` installed, its own cookie is sent with every registered
  service granted, in the exact shape its script reads (`{ v, granted, ts, how, id }`, URL-encoded),
  so the banner stays down and the gated embeds render. Another consent tool wants its own cookie
  here.
- `thumbnails.hide_selectors` is a list of CSS selectors hidden before the shot (`display: none
  !important`), for a banner no cookie silences. Empty by default.

### Where people stop

Every step card in the editor carries three figures: how many visitors reached it, how many carried
on from it, and the share. Counted **per visitor**, not per page load, so a reload does not flatter
a step. A step nobody has reached shows nothing at all, and the last step of a walk shows no rate —
a thank-you page is not converting at 0 %, it is the end.

### Order bumps and coupon codes

An offer can carry other offers as tick-boxes beside the order button (`bumps` on the offer), and
the page can take a coupon code. Both are settled on the server:

- Only bumps the **offer** lists can be ticked. The form says which boxes were checked; the offer
  says which boxes exist, and only the intersection is bought.
- A coupon code is a *string* from the browser; what it is worth is looked up. The rule that no
  amount ever comes from a request is intact.
- A code's last use is claimed with a conditional update, so two people typing it at the same
  moment cannot both have it. If the loser was mid-purchase, they pay full price rather than
  failing — a sale lost to a race is worse than a discount missed.

Set `coupons` to `false` to leave the field off the page entirely.

### A deadline that holds

An offer step can carry one, and it is **enforced on the server**: past it the step refuses to be
accepted, whatever a stale tab still shows. A countdown that only counts is a lie told in
Javascript, and a visitor who reloads past one learns that every deadline on the site is decoration.

| Kind | What it means |
|---|---|
| `fixed` | One moment for everybody. A launch that closes on Friday. |
| `rolling` | A window per visitor, from the first time **they** see the step. |

The rolling one is what people mean by "evergreen", and it needs saying out loud: the deadline is
per visitor, written to their walk on first sight, and somebody who clears cookies gets a new one.
That is a property of the mechanism. Enforcing it otherwise would mean identifying people.

The shipped page draws it and ticks it (`funnels.js`, no build step, no dependency). A site with its
own front end reads `funnel:countdown` and does its own:

```antlers
{{ if funnel:countdown }}
    <p data-funnel-countdown="{{ funnel:countdown:ends_at }}">
        {{ if funnel:countdown:expired }}Vorbei.{{ else }}<time data-funnel-clock>{{ funnel:countdown:seconds }}</time>{{ /if }}
    </p>
{{ /if }}
```

**Declining still works after the deadline.** A closed offer is not a closed funnel, and somebody
standing on an expired page must be able to move on rather than being stuck.

### Testing two versions of a page

Any step can run one. Set a share for B and fill in only the fields B changes:

| Field | |
|---|---|
| `split_share` | A whole percentage for B. Empty or 0 means no test. |
| `variant_entry` | A different page |
| `variant_headline`, `variant_body` | Different words |

Three properties it has, and each is the reason such a thing is usually worthless without it:

- **Stable.** A visitor sees the same version every time, decided once from their walk token and the
  step key. Splitting per render would show somebody A, then B, then A, and the numbers underneath
  would be about nothing.
- **Per step, not per funnel.** Two tests can run at once without one deciding the other.
- **Recorded.** The version is written onto the arrival, so the drop-off numbers can be split by it.
  A test that cannot be counted is a coin toss with extra steps.

The result shows in the editor, on the step where the test is set up — a split report on another
screen is a report nobody opens. Only fields B actually sets are swapped: a test that changes one
headline must not silently blank the body.

### URLs

```
/f/{funnel}              the entry step
/f/{funnel}/{slug}       every other step
```

Real URLs, one per step, so a bookmark works, the back button works, and an email can link into the
middle of a flow — which is how the second half of most funnels actually starts.

A step's slug is derived from its label **once** and then left alone. A slug that followed the
label would break every link already sent out.

### Money

An offer step sells an offer from `statamic-offers`, at the price that lives there. The price never
comes from the page.

**Accepted means paid.** The walk moves on when the payment addon's webhook says the money arrived,
and exactly once however often the provider redelivers. A buyer who closes the tab has still paid; a
buyer who reaches the return page has not necessarily.

The provider sends them back **into the funnel**, not to the site's general thank-you page. Landing
outside the flow is being dropped halfway through a purchase, and everything that was meant to
follow the sale never happens.

If the same walk has already paid once and the site collects mandates, the second offer is charged
without asking for card details again. The consent is not skipped — see
[`statamic-payments/docs/follow-up-offers.md`](https://github.com/goldnead/statamic-payments/blob/main/docs/follow-up-offers.md),
because in Germany a follow-up order still needs its own unambiguously labelled button.

**The page says so before the button does it.** The shipped template prints, directly above the
order button, that this will be charged to the card already on file — with the brand and last four
digits where the provider named them, without the digits where it did not, and never with a card the
provider did not document for that payment. Announcement and action come from the same place
(`SavedCard`), so they cannot drift apart.

That place also asks the provider once when the buyer comes back from the checkout before the
webhook has landed — otherwise the very first buyer sees the offer without the one-click sentence and
is then charged with one click anyway. A provider that will not answer changes nothing: the page
falls back to an ordinary checkout, and the webhook settles it a moment later.

### Templates

Each step may name its own Antlers template. Without one, a shipped template renders it, styled by a
small stylesheet whose every value is a custom property. That fallback matters more than it looks: a
funnel that renders nothing until somebody has written four templates never gets tried out.

```antlers
{{ funnels:link handle="fruehlingskurs" }}

{{ funnels:progress handle="fruehlingskurs" }}
    {{ if no_results }}{{ else }}
        <a href="{{ url }}">Weiter bei „{{ step }}"</a>
    {{ /if }}
{{ /funnels:progress }}
```

`progress` is the "you were partway through this" link, which is worth more than most of what a
funnel does at the front.

### Who is walking

A random token in the visitor's own cookie identifies a **walk**, not a person. A funnel has to work
before anybody has said who they are, and most visitors never do. Nothing here needs a login.

The cookie is deliberately not encrypted: it holds nothing worth hiding, and Laravel silently
discards a cookie it cannot decrypt — a failure that looks exactly like a visitor who never came
back.

### What it plugs into

Every one of these is optional, checked with `class_exists`, and **off by default**. Installing a
funnel addon must not start writing into somebody's CRM.

| Sibling | What it does |
|---|---|
| `statamic-payments` | takes the money, decides what "paid" means |
| `statamic-offers` | says what a thing costs *here* |
| `statamic-leadhub` | receives a captured address as a contact |
| `statamic-email-templates` | renders the template a mail node names; without it a mail node saves but every send is recorded as failed |
| `statamic-automations` | four trigger nodes: step entered, form submitted, offer accepted, funnel completed |
| Statamic forms | are the form; this addon never grew its own |

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `route_prefix` | `f` | Every funnel URL changes with it, including ones already printed on a flyer. |
| `styles` | `true` | Off for a site with its own design. The markup keeps its class names either way. |
| `integrations.leadhub` | `false` | On, captured addresses go to LeadHub as contacts. |
| `integrations.entitlements` | `false` | Off because the payment addon offers the same bridge, and two addons granting the same thing is worse than neither. |
| `coupons` | `true` | Off leaves the code field off every offer page. |
| `template_prefix` | `''` | A folder name confines what a step may name as its template. A namespaced name is refused either way. |
| `thumbnails.enabled` | `true` | Off, no page is photographed and the editor says nothing about it. |
| `thumbnails.disk` | `public` | Has to be a disk with public URLs; the Control Panel loads the pictures by URL. |
| `thumbnails.width` / `height` | `640` / `400` | The stored size. The card draws 16:10; another ratio is cropped. |
| `thumbnails.chrome_path` | `null` | Names the browser when it is not on `PATH`. Named but not executable means no renderer, not a fallback. |
| `thumbnails.cookies` | `[]` | Cookies the browser carries into the page. Empty means the consent addon's cookie with everything granted, when that addon is installed. |
| `thumbnails.hide_selectors` | `[]` | CSS selectors hidden before the shot. For a banner no cookie can silence. |

## Multi-site

Funnels are not site-scoped. A funnel is a campaign, not content.

## Support

Only the latest version. <https://github.com/goldnead/statamic-funnels/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)

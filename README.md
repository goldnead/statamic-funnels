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
| `statamic-automations` | hears `FunnelStepEntered`, `FunnelOfferAccepted`, `FunnelCompleted` |
| Statamic forms | are the form; this addon never grew its own |

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `route_prefix` | `f` | Every funnel URL changes with it, including ones already printed on a flyer. |
| `styles` | `true` | Off for a site with its own design. The markup keeps its class names either way. |
| `integrations.leadhub` | `false` | On, captured addresses go to LeadHub as contacts. |
| `integrations.entitlements` | `false` | Off because the payment addon offers the same bridge, and two addons granting the same thing is worse than neither. |

## Multi-site

Funnels are not site-scoped. A funnel is a campaign, not content.

## Support

Only the latest version. <https://github.com/goldnead/statamic-funnels/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)

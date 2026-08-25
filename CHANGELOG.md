# Changelog

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

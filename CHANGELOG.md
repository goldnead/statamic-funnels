# Changelog

## 1.9.1 — 2026-09-02

### Fixed — der Ein-Klick-Hinweis fehlte genau beim ersten Käufer

Nach dem Rücksprung vom Bezahldienst rendert der Upsell-Schritt, bevor der Webhook die Zahlung auf
`paid` gesetzt hat — beim Kauftest am 02.09.2026 lagen zwischen beidem weniger als eine Sekunde. In
dieser Sekunde hielt `FollowUp::eligible()` die Vorgängerzahlung für unbezahlt, und die Seite sagte
nichts über die gespeicherte Karte. Erst ein Neuladen brachte den Satz. In der scharfen Fassung
betrifft das **jeden** ersten Käufer.

Der fehlende Satz ist dabei nicht das Schlimmste: bis zum Klick ist der Webhook da, die Aktion nimmt
den Ein-Klick-Weg, und abgebucht wird etwas, das die Seite nie angekündigt hat. `SavedCard` fragt
deshalb den Anbieter selbst, wenn eine Zahlung `open` bei ihm liegt (`Fulfilment::handle()`, derselbe
Weg wie der Webhook, gegen Doppelausführung gesichert). Antwortet er nicht oder wirft ein Listener,
bleibt es beim bekannten Stand und die Seite fällt auf die normale Kasse zurück — eine öffentliche
Seite kippt daran nicht.

### Fixed — „von deiner  •••• 9996"

Der genannte Kartensatz hing allein an den vier Ziffern. Nennt der Anbieter die Ziffern, aber nicht
die Marke — bei Wallet-Zahlungen der Normalfall —, entstand eine Lücke mitten im Satz. Jetzt drei
Fälle: Marke und Ziffern, nur Ziffern (neuer Schlüssel `saved_card_digits`), keins von beidem. Gesagt
wird es in allen dreien, nur mit weniger Details.

### Fixed — `payment_items.offer` beim Upsell

Der Ein-Klick-Weg übergibt jetzt `offer_handles` an `FollowUp::accept()`, so wie der Kassenweg es
über den Katalog bekommt. Ohne das blieb die Spalte genau bei der Upsell-Zeile leer, und der
Upsell-Bericht in `statamic-insights` ordnete den Umsatz keinem Angebot zu. Braucht payments 1.17.1;
gegen ältere payments-Fassungen wird die Angabe ignoriert, nicht bestraft.

### Fixed — was der Besucher eintippt, kam roh im HTML der Mail an

`statamic-email-templates` setzte bis 2.2.x jeden Wert unverändert ein, und dieses Addon gab ihm
`visitor.name` und `contact.*` direkt aus dem Formular. Ein Name mit Markup darin wurde damit zu
Markup in einer Mail. Ab email-templates 2.3.0 escaped die Schwester selbst; ohne die Anpassung hier
wäre daraus der umgekehrte Fehler geworden — doppelt escapte Bestellzeilen und ein `&amp;` in der
Betreffzeile.

Drei Änderungen, die zusammengehören:

- **Der Körper wird escaped, der Betreff nicht.** `FunnelMailRenderer::render()` ruft die
  Zusammenführung für den HTML-Körper mit Escaping und für die Betreffzeile ohne auf. Ein Betreff ist
  kein HTML; dort wäre `Müller &amp; Söhne` sichtbarer Schaden statt Schutz.
- **`order.lines` bleibt Markup, angemeldet statt geduldet.** Der Wert wird hier aus `e()`-escapten
  Teilen mit `<br>` dazwischen gebaut und über den neuen `raw`-Parameter von
  `MergeVariables::apply()` pro Aufruf als roh übergeben (`FunnelMailRenderer::RAW_VARIABLES`).
  Der Weg „gar kein Markup mehr im Wert" scheitert an der Sache: `MergeVariables` kennt nur flache
  Skalare und keine Schleife, und in einer HTML-Mail trennt Zeilen nur Markup — ein `\n` fällt beim
  Rendern zusammen. Der Schlüssel gehört deshalb ausdrücklich **nicht** in
  `MergeVariables::RAW_VARIABLES`, wo er für jeden Konsumenten der Schwester roh wäre.
- **Ältere Schwestern bleiben lauffähig.** `MailTemplates::merge()` reicht die neuen Argumente
  positional durch, nicht benannt: gegen 2.2.x ignoriert PHP zusätzliche positionale Argumente
  stillschweigend, ein unbekanntes benanntes Argument wäre ein Fatal mitten im Versand.

### Fixed — der Test-Fake blieb grün, egal was die Schwester tat

`tests/Fakes/email-templates-facade.php` definiert ein eigenes `MergeVariables`, wenn die echte
Klasse fehlt — und genau das ist sie in dieser Suite immer. Die Kopie hinkte hinterher, also hätte
die Suite den Fehler oben nie gesehen. Der Fake ist jetzt auf Signatur und Verhalten der echten
Klasse nachgezogen (`$escape`, `$raw`, `RAW_VARIABLES`), mit einem Vermerk darüber, dass er
mitgeführt werden muss. Dazu ein Test, der genau die drei Eigenschaften festnagelt: Name aus dem
Formular escaped, Bestellzeilen mit intaktem `<br>` und genau einmal escaped, Betreff roh.

## 1.9.0 — 2026-09-02

### Ein Bild je Seite auf der Leinwand

Nach dem Speichern wird jeder **Seiten**-Schritt fotografiert, und die Karte auf der Leinwand zeigt
das Bild als 16:10-Kachel über dem Titel. Aus dem Graphen wird eine Landkarte: man erkennt eine
Station am Aussehen ihrer Seite, nicht nur am Namen. Mail-Knoten bekommen kein Bild, sie haben keine
Seite.

- Das Foto entsteht **nicht beim Laden des Editors**, sondern in einem Job (`RenderStepThumbnail`,
  queued, eindeutig je Schritt bis zum Start; auf der `sync`-Queue nach der Antwort) nach dem
  Speichern. Er lädt die Seite über dieselbe Vorschau-Route
  wie das Vorschau-Panel, mit einem `PreviewToken`, das nach dem Foto wieder gelöscht wird, und
  schreibt nichts: kein Besuch, kein Ereignis, keine Impression.
- Ein Schritt wird nur neu fotografiert, wenn sich an ihm etwas geändert hat (Fingerabdruck aus
  Typ, Label, Slug, Konfiguration). `php please funnels:thumbnails {funnel?} {--force}` rendert
  nach, etwa nach einer Änderung am Eintrag hinter einer Seite.
- Ablage auf `thumbnails.disk` (Standard `public`) unter `funnels/thumbs/<funnel>/<node_key>-<hmac>.png`
  (16 Hex-Zeichen HMAC unter `APP_KEY`, damit die Bilder eines Entwurfs nicht aus den Editor-URLs
  erratbar sind),
  Pfad und `rendered_at` in `funnel_steps.config['thumbnail']`. Der Schlüssel gehört dem Server:
  ein zweites Speichern vor dem Neuladen wirft das Bild nicht weg. Ein gelöschter Schritt nimmt
  sein Bild mit, ein gelöschter Funnel seinen Ordner.
- **Renderer hinter einem Contract** (`Contracts\ThumbnailRenderer`). Browsershot, wenn
  `spatie/browsershot` installiert ist **und** ein Chromium gefunden wird (auf `PATH`, in den
  üblichen Mac-Pfaden oder unter `thumbnails.chrome_path`). Sonst ein `NullRenderer`, der nichts
  tut und das einmal je Prozess als `notice` sagt. Ohne Renderer: **kein grauer Platzhalter**, die
  Karten sehen aus wie bisher, und die Seitenleiste des Editors sagt still „Vorschaubilder brauchen
  Chromium (siehe Doku)".
- **Ohne Cookie-Banner im Bild.** `thumbnails.cookies` (`name => value`) setzt der Browser vor dem
  Laden; leer und mit `goldnead/statamic-consent` installiert geht dessen Consent-Cookie mit allen
  Diensten als erteilt mit, im Format seines Skripts (`{ v, granted, ts, how, id }`, URL-kodiert).
  `thumbnails.hide_selectors` blendet Selektoren vor dem Foto aus, für ein Banner, das kein Cookie
  beruhigt. Sonst zeigt jede Kachel dasselbe Banner, und eine Landkarte, auf der jede Station gleich
  aussieht, ist keine.
- Config `thumbnails`: `enabled`, `disk`, `width`, `height`, `chrome_path`, `cookies`,
  `hide_selectors`.
- Braucht `goldnead/statamic-flow-canvas` ^1.3, das den Knoten das Feld `thumbnail` gibt.

## 1.8.0 — 2026-09-02

### Mail-Knoten auf der Leinwand

Ein neuer Knoten `mail`, der **an einem Ausgang hängt und nie betreten wird**. Eine Regel: die
Mail geht raus, wenn der Besuch den Ausgang nimmt, an dem sie hängt — `default` heißt weiter
(Seite) oder abgeschickt (Formular), `accepted` heißt bezahlt (erst per Webhook), `declined`
heißt abgelehnt. Der Abschluss hat keinen Ausgang; „der Weg ist zu Ende" ist der Ausgang, der
dorthin führt. Der Weg führt an der Mail vorbei — `nextStep()` überspringt sie, der Stepper
zählt sie nicht als Station, die Abbruchstatistik auch nicht.

Vorlage aus `statamic-email-templates`, Verzögerung als Queue-Delay (kein zweiter Scheduler,
kein Zwang zu `statamic-automations`; die Begründung steht in der README), Empfänger der Besuch
oder eine feste Adresse. Einmal je Besuch und Knoten, durch einen Unique-Index. Jede Mail
hinterlässt eine Zeile in `funnel_mail_deliveries` (ausgelöst / zugestellt / fehlgeschlagen mit
Grund), der Editor zeigt die drei Zahlen am Knoten. Vorschau: der Stepper führt die Mail direkt
hinter ihrem Schritt, das Iframe zeigt die gerenderte Mail mit Beispieldaten
(`GET /f/{funnel}/_preview-mail/{node}?token=…`).

Neues Ereignis `FunnelOfferDeclined`. Der Inspector zeigt je Ausgang „Mail anhängen" und, wo
noch nichts weitergeht, „Schritt anhängen" — das „+" der Leinwand gibt es nur an Ausgängen ohne
Kante. Ein Knoten, der über das „+" einer bestehenden Kante gewählt wird, wird jetzt dazwischen
eingefügt (vorher blieb er unverbunden); eine Mail dort hängt sich als Abzweig an denselben Ausgang.

### Kauf und Newsletter getrennt

Der Capture-Schritt bekommt einen Newsletter-Haken unter dem E-Mail-Feld (`newsletter`: kein
Haken / Haken anbieten, nie vorangekreuzt; `newsletter_label` konfigurierbar). Der Besuch traegt
`meta['newsletter']` mit Zeitpunkt und dem gezeigten Wortlaut; ein spaeterer Schritt macht aus
einem „ja" kein „nein". Richtung LeadHub: die Adresse wird ein Kontakt **ohne** Einwilligung, nur
der Haken setzt sie (ueber `ContactResolver`, weil `ingest()`/`create()`/`update()` keine
Einwilligung kennen), ein Kauf traegt das Tag `kunde` — neues Listener `TagBuyerInLeadHub` auf
`FunnelOfferAccepted`. Nebenbei: `LeadHubBridge::capture()` uebergab den Namen unter `name`, den
`SourceEvent` gar nicht liest; jetzt unter `contact.full_name`.

### Konto-Schritt nach dem Kauf

Neuer Seiten-Knoten `account`: zeigt die E-Mail des Besuchs (nicht aenderbar), fragt Name und
Passwort (min. 8, wiederholt), legt den Statamic-User an oder aktualisiert den mit dieser
Adresse — nie ein Duplikat —, meldet ihn an (`login_after`) und geht weiter. „Spaeter" geht ohne
Konto weiter, wenn `optional` es zulaesst (Vorgabe: ja). Ohne Adresse am Besuch gibt es kein
Konto, ein fremder Browser bekommt 403 wie an jedem Schritt. Kein eigenes Mailing.

Nebenbefund: `FunnelWalk` las den Request aus dem Konstruktor. Laravel haelt die
Controller-Instanz am Route-Objekt fest; bedient dasselbe Route-Objekt einen zweiten Request
(Testsuite, Octane), trug der gefangene Request den Cookie des ersten Besuchers in den Weg des
zweiten. Jetzt wird der aktuelle Request gelesen.

### Der Capture-Schritt liest die Feld-Bibliothek

`billing` hat eine vierte Stufe `offer`: die Felder ergeben sich aus `Offer::checkoutFields()`
des naechsten Angebots hinter dem Schritt (Graph vorwaerts, an Seiten und Mails vorbei), Labels,
Typen, Optionen und Pflicht aus `Offers::fieldLibrary()`. Validierung dynamisch aus der
Bibliothek, Ablage 1:1 in `visit.meta['billing']`. Fehlt die Bibliothek oder das Angebot,
verhaelt sich der Schritt wie `minimal` und schreibt es ins Log. Beide Methoden nur hinter
`method_exists`; Tests laufen ueber Resolver-Fakes, bis das installierte offers sie hat.

### Einwilligung, Widerruf und Zugangsfenster an der Zahlung

`confirmed` wurde geprüft und verworfen. Jetzt gehen Zeitpunkt und Wortlaut der Einwilligung in
die Zahlung (§ 356 Abs. 5 BGB): als `consent_at`/`consent_text`, wenn das installierte payments
die Schlüssel kennt, sonst unter `meta['consent']`. Der Wortlaut kommt aus
`Offer::withdrawalTerms()` mit Fassung in eckigen Klammern (Fallback: die Sprachdatei), bei einer
USt-IdNr in den Rechnungsangaben der B2B-Text. Die Kassenseite zeigt die Kurzbelehrung über dem
Knopf, schickt den gezeigten Wortlaut als `consent_text` zurück, und der Server lehnt eine
abweichende Fassung ab („Bitte Seite neu laden"). Die Konditionen werden unter `meta['withdrawal']`
eingefroren, das Zugangsfenster (`Offer::accessWindow()`) unter `meta['access']`. Beide Kaufwege,
Kasse und gespeicherte Karte. Ältere Nachbarn ohne diese Methoden: nichts geworfen, weggelassen.

## 1.7.0 — 2026-09-01

Nachgetragen: die Fassung 1.7.0 ging ohne Eintrag raus. Sie brachte am Capture-Schritt die
**Rechnungsangaben** (`billing` minimal / name / full, Anschrift landet als `meta.address` an der
Zahlung, damit über 250 € eine Rechnung entsteht) und auf der Danke-Seite die
**Bestellzusammenfassung** (`order` mit Positionen, Summe, Referenz).

## 1.6.1 — 2026-09-01

Formulierung des Kartenhinweises. „von deiner Mastercard auf 9996" las sich auf der Seite wie eine
Betragsangabe; jetzt steht dort „von deiner Mastercard •••• 9996". Nur Text, kein Verhalten.

## 1.6.0 — 2026-09-01

### Der zweite Mensch am selben Rechner zahlte auf die Karte des ersten

Ein Funnel-Besuch hängt an einem Cookie mit dreißig Tagen Laufzeit. Wer ein zweites Mal durch
denselben Funnel ging — dieselbe Maschine, andere Person, andere Adresse —, bekam kein
Kartenformular mehr: `AdvanceController` fand die Zahlung des ersten Laufs am Besuch, hielt das für
„derselbe Käufer nimmt noch etwas" und ließ per gespeichertem Mandat abbuchen. Die frisch
eingegebene Adresse überschrieb `FollowUp` dabei mit der alten. Zugang, Rechnung und
Bestätigungsmail liefen auf den ersten Käufer, die zweite Person hatte gezahlt und bekam nichts.
Reproduziert auf einer Staging-Installation am 31.08.2026, mit echter Zahlung.

Zwei Stellen, dieselbe falsche Annahme, dass ein Gerät ein Mensch ist:

- **Die gespeicherte Karte.** Neu in `Support\SavedCard`, und zwar an *einer* Stelle, weil zwei sie
  brauchen: die Seite, die es vorher sagen muss, und die Aktion, die es danach tut. Sie fragt
  `FollowUp::eligible($payment, $visit->email)` — das braucht `goldnead/statamic-payments ^1.16`,
  daher die angehobene Anforderung.
- **Die Erinnerung an den Kauf.** Trägt der Capture-Schritt eine andere Adresse ein als eben, fängt
  der Lauf neu an: `payment_id` und `meta['payments']` fallen weg. Ohne das wäre der Fehler nur
  gewandert — statt auf fremde Karte zu buchen, hätte `pendingPaymentFor()` den längst bezahlten
  Kauf des Ersten für diesen gehalten und die zweite Person **ohne jede Zahlung** durchgewinkt.

Für den echten Wiederkäufer ändert sich nichts: gleiche Adresse, gleicher Ein-Klick-Upsell.

### Die Seite sagt jetzt, womit sie abbucht

Der Offer-Schritt bekommt `funnel:saved_card` mit `last4` und `label`, gefüllt aus
`payments.card_last4` / `card_label` (neu in payments 1.16). Die mitgelieferte Ansicht schreibt den
Satz unmittelbar über den Bestellknopf — § 312j Abs. 3 BGB will die wesentlichen Angaben genau
dort, und die Zahlungsart gehört dazu. Neue Sprachschlüssel `saved_card_named` und
`saved_card_unnamed`; ohne Kartenangaben (Überweisung, Altbestand) steht der Satz ohne die vier
Ziffern da, statt zu fehlen. Wer eine eigene Ansicht schreibt, muss `saved_card` selbst ausgeben.

### Getestet

`tests/Feature/SavedCardTest.php`, und das Test-Double `FakeGateway` kann jetzt nachfassen — bis
hierhin war der ganze Zweig aus den Tests dieses Pakets heraus unerreichbar, was der Grund war,
dass der Fehler bis in eine echte Zahlung durchkam.

## 1.5.1 — 2026-08-30

### Fixed — die Vorschau im Control Panel lief immer in einen CSRF-Fehler

`PreviewPanel` holte den CSRF-Token aus `<meta name="csrf-token">`. **Das Control Panel von
Statamic 6 rendert dieses Tag nicht** — der Token steht in der JS-Konfiguration, die das Layout
ausschreibt (`Statamic.$config`, `StatamicConfig.csrfToken`). Gelesen wurde also ein leerer String,
und jeder Vorschau-Aufruf kam als *CSRF token mismatch* zurück. Die Vorschau war damit auf keiner
Installation je benutzbar.

Nicht aufgefallen, weil die Feature-Tests der Vorschau in Laravels Testumgebung laufen, wo die
CSRF-Prüfung ausgeschaltet ist: der Endpunkt war die ganze Zeit in Ordnung, nur der Aufruf aus dem
Browser kam nie an. `statamic-marketing` liest denselben Token seit jeher über `Statamic.$config`;
das ist jetzt auch hier der erste Weg, mit dem Meta-Tag als letztem Rückfall.

## 1.5.0 — 2026-08-29

### Neu: die Zahlen dieses Addons erscheinen in Insights

`statamic-insights` ist ab 1.1.0 keine Umsatzauswertung mehr, sondern die Auswertungs-Schicht der
Familie: jedes Addon meldet an, was es zählen kann, und bekommt dafür Zeitraum, Vergleich mit dem
Vorzeitraum, Diagramm, Aufteilungen und zwei fertige Schirme.

Die Kopplung ist in **beide** Richtungen freiwillig. Ohne Insights fehlt hier nichts; ohne dieses
Addon fehlt dort nur seine Gruppe. `suggest`, nie `require`.

Jede Zahl hält sich an die Hausregeln des Vertrags: **null ist nicht null** (eine Quote ohne Nenner
hat keine Antwort und zeigt keine 0 %), `available()` entscheidet über die Existenz und nie über die
Daten, Lücken im Verlauf füllt Insights und nicht die Kennzahl, und ein Filter, den eine Zahl nicht
versteht, wird ignoriert statt zum Fehler.

Vier Zahlen: Eintritte, Abschlüsse, Abschlussquote, Dauer bis zum Abschluss.

Keine der fünf Tabellen trägt eine Markenspalte — gegen die Migrationen geprüft, nicht angenommen —,
hier ist also nichts zu verengen.

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

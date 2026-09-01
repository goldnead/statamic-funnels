<?php

namespace Goldnead\StatamicFunnels\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Die fertige Mail eines Mail-Knotens.
 *
 * Betreff und HTML sind schon gerendert, wenn sie hier ankommen — die Vorlage
 * hat `statamic-email-templates` gebaut, die Platzhalter der Funnel gefuellt.
 * Eine eigene Mailable statt `Mail::html()`, damit ein Test sie sehen kann und
 * damit ein Host sie ueber `Mail::alwaysFrom()` oder einen eigenen Mailer
 * lenkt wie jede andere Mail seiner Site.
 */
class FunnelMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
    ) {}

    public function build(): static
    {
        return $this->subject($this->subjectLine)->html($this->htmlBody);
    }
}

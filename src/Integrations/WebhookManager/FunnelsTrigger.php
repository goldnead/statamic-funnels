<?php

namespace Goldnead\StatamicFunnels\Integrations\WebhookManager;

use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

/**
 * One funnel event as a webhook-manager trigger.
 *
 * Implements the webhook manager's interface, so it must never be loaded on a
 * site without that addon: {@see WebhookManagerBridge} creates it only after
 * checking the interface by name.
 */
class FunnelsTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $labelKey,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    /** Translated when asked, so the CP shows it in the reader's language. */
    public function label(): string
    {
        return (string) __($this->labelKey);
    }

    public function sourceType(): string
    {
        return 'funnels';
    }

    /**
     * @param  mixed  $source  The funnel event, or an already built payload (replays, the CP simulator).
     */
    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $payload = is_array($source) ? $source : WebhookPayload::for($this->handle, $source);

        return new TriggerEvent(
            triggerHandle: $this->handle,
            sourceType: $this->sourceType(),
            sourceReference: self::reference($payload),
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: new \DateTimeImmutable,
        );
    }

    /**
     * The funnel's id, the same object the payload names as its subject.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function reference(array $payload): ?string
    {
        $funnel = $payload['subject_id'] ?? null;

        return is_int($funnel) || (is_string($funnel) && $funnel !== '') ? (string) $funnel : null;
    }
}

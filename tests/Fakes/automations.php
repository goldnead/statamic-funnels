<?php

/*
 * Stands in for the one event of `goldnead/statamic-automations` the funnels
 * addon listens to, under the sibling's real name. It mirrors the two methods
 * `Integrations\ExternalSteps` calls: `handle()` and `email()`.
 */

namespace Goldnead\StatamicAutomations\Events {
    if (! class_exists(AutomationRunFinished::class)) {
        class AutomationRunFinished
        {
            public function __construct(public readonly string $automation, public readonly ?string $address) {}

            public function handle(): ?string
            {
                return $this->automation;
            }

            public function email(): ?string
            {
                return $this->address;
            }
        }
    }
}

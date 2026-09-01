<?php

/*
 * Stands in for `goldnead/statamic-leadhub`, which this addon does not require
 * and the suite therefore does not install.
 *
 * Three classes under the sibling's real names, because that is what the
 * bridge probes with `class_exists` and resolves from the container. Each one
 * only records what it was handed; a test reads the record.
 */

namespace Goldnead\LeadHub\Facades {
    if (! class_exists(LeadHub::class)) {
        class LeadHub
        {
            /** @var list<array<string, mixed>> */
            public static array $ingested = [];

            public static function getFacadeRoot(): object
            {
                return new class
                {
                    public function ingest(array $event): ?object
                    {
                        LeadHub::$ingested[] = $event;

                        return null;
                    }
                };
            }

            public static function ingest(array $event): ?object
            {
                return self::getFacadeRoot()->ingest($event);
            }
        }
    }
}

namespace Goldnead\Leadhub\Support {
    if (! class_exists(ContactDto::class)) {
        class ContactDto
        {
            public function __construct(
                public ?string $email = null,
                public ?string $firstName = null,
                public ?string $lastName = null,
                public ?string $fullName = null,
                public ?string $phone = null,
                public ?string $company = null,
                public ?string $message = null,
                public bool $consent = false,
                public array $tags = [],
                public ?string $source = null,
            ) {}
        }
    }
}

namespace Goldnead\Leadhub\Services {
    use Goldnead\Leadhub\Support\ContactDto;

    if (! class_exists(ContactResolver::class)) {
        class ContactResolver
        {
            /** @var list<ContactDto> */
            public static array $resolved = [];

            public function resolveOrCreate(ContactDto $dto): array
            {
                self::$resolved[] = $dto;

                return [(object) ['email' => $dto->email, 'consent' => $dto->consent], true];
            }
        }
    }
}

<?php

/*
 * Stands in for `goldnead/statamic-email-templates`, which this addon does not
 * require and the suite therefore does not install.
 *
 * Two classes, both under the sibling's real names, because that is what the
 * bridge probes with `class_exists`. `resolve()` answers from a static map a
 * test fills; `apply()` is the sibling's own dotted-key substitution, copied
 * so the placeholders a test asserts on are resolved the way the real thing
 * resolves them.
 */

namespace Goldnead\EmailTemplates\Facades {
    if (! class_exists(EmailTemplates::class)) {
        class EmailTemplates
        {
            /** @var array<string, array{subject: string, body: string}> */
            public static array $templates = [];

            public static function resolve(string $slug, ?callable $fallback = null): ?object
            {
                if (! isset(self::$templates[$slug])) {
                    return null;
                }

                return (object) self::$templates[$slug];
            }
        }
    }
}

namespace Goldnead\EmailTemplates\Support {
    if (! class_exists(MergeVariables::class)) {
        class MergeVariables
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public static function apply(string $text, array $data): string
            {
                if ($text === '') {
                    return '';
                }

                $flat = self::flatten($data);

                return (string) preg_replace_callback(
                    '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                    fn (array $m) => array_key_exists($m[1], $flat) ? (string) $flat[$m[1]] : $m[0],
                    $text,
                );
            }

            /**
             * @param  array<string, mixed>  $data
             * @return array<string, mixed>
             */
            protected static function flatten(array $data, string $prefix = ''): array
            {
                $result = [];

                foreach ($data as $key => $value) {
                    $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                    if (is_array($value)) {
                        $result += self::flatten($value, $full);
                    } else {
                        $result[$full] = $value;
                    }
                }

                return $result;
            }
        }
    }
}

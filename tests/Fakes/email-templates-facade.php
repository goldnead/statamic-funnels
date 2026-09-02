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
 *
 * ⚠ **Ein Fake, der hinterherhinkt, ist schlimmer als kein Fake.** Am
 * 02.09.2026 fing `MergeVariables::apply()` drueben an zu escapen; diese Kopie
 * tat es nicht, die Suite blieb gruen, und der Fehler waere erst beim Versand
 * in einer echten Installation sichtbar geworden. Wer drueben an `apply()`
 * etwas aendert, zieht es hier nach — Signatur UND Verhalten:
 *
 *   apply(string $text, array $data, bool $escape = true, array $raw = [])
 *   const RAW_VARIABLES = ['unsubscribe_url']
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
            /** @var list<string> */
            public const RAW_VARIABLES = ['unsubscribe_url'];

            /**
             * @param  array<string, mixed>  $data
             * @param  list<string>  $raw
             */
            public static function apply(string $text, array $data, bool $escape = true, array $raw = []): string
            {
                if ($text === '') {
                    return '';
                }

                $flat = self::flatten($data);
                $rawKeys = $raw === [] ? self::RAW_VARIABLES : array_merge(self::RAW_VARIABLES, $raw);

                return (string) preg_replace_callback(
                    '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                    function (array $m) use ($flat, $escape, $rawKeys) {
                        if (! array_key_exists($m[1], $flat)) {
                            return $m[0];
                        }

                        $value = (string) $flat[$m[1]];

                        return $escape && ! in_array($m[1], $rawKeys, true) ? e($value) : $value;
                    },
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

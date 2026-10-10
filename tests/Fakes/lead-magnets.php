<?php

/*
 * Stands in for `goldnead/statamic-lead-magnets`, which this addon does not
 * require and the suite therefore does not install.
 *
 * Under the sibling's real names, because the bridge probes with
 * `class_exists` and calls the facade. It is a stand-in and says so: it mirrors
 * the four things the bridge depends on and nothing more.
 *
 *  1. `LeadMagnets::request($resource, $email, $meta)` returns a grant that is
 *     pending (resource wants a confirmation) or active (it does not).
 *  2. `return_url` in the meta is kept only when it is a URL this application
 *     signed, on the current host; anything else is dropped. That is the
 *     sibling's `ReturnUrl::accept()`, copied in spirit, and
 *     `LeadMagnetsRealPackageTest` holds this copy against the real package
 *     wherever the real package can be found.
 *  3. Confirming marks the grant active and hands back the return URL to
 *     redirect to; the test does the redirect, as the browser would.
 *  4. `Resource` is queried through Eloquent like the real model (`published`,
 *     `handle`, `title`, `requires_confirmation`).
 *
 * The tables are created by `tests/Support/LeadMagnetsSchema.php`.
 */

namespace Goldnead\LeadMagnets\Models {
    use Illuminate\Database\Eloquent\Model;

    if (! class_exists(Resource::class)) {
        class Resource extends Model
        {
            protected $table = 'fake_lm_resources';

            public $timestamps = false;

            protected $guarded = [];

            protected function casts(): array
            {
                return ['published' => 'boolean', 'requires_confirmation' => 'boolean'];
            }
        }
    }

    if (! class_exists(Grant::class)) {
        class Grant extends Model
        {
            protected $table = 'fake_lm_grants';

            protected $guarded = [];

            protected function casts(): array
            {
                return ['meta' => 'array'];
            }

            public function resource()
            {
                return $this->belongsTo(Resource::class, 'resource_id');
            }

            public function isRedeemable(): bool
            {
                return $this->status === 'active';
            }

            public function isPending(): bool
            {
                return $this->status === 'pending';
            }
        }
    }
}

namespace Goldnead\LeadMagnets\Support {
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\URL;

    if (! class_exists(ReturnUrl::class)) {
        final class ReturnUrl
        {
            public static function accept(mixed $url): ?string
            {
                if (! is_string($url) || $url === '' || strlen($url) > 2048) {
                    return null;
                }

                $parts = parse_url($url);

                if ($parts === false
                    || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                    || ! isset($parts['host'])
                    || isset($parts['user'])
                    || isset($parts['pass'])
                    || strcasecmp($parts['host'], request()->getHost()) !== 0) {
                    return null;
                }

                return URL::hasValidSignature(Request::create($url)) ? $url : null;
            }
        }
    }
}

namespace Goldnead\LeadMagnets\Facades {
    use Goldnead\LeadMagnets\Models\Grant;
    use Goldnead\LeadMagnets\Models\Resource;
    use Goldnead\LeadMagnets\Support\ReturnUrl;

    if (! class_exists(LeadMagnets::class)) {
        class LeadMagnets
        {
            /** @var list<array{resource: string, email: string, meta: array<string, mixed>}> */
            public static array $requests = [];

            /** When set, the next request throws, as a broken mailer would. */
            public static ?\Throwable $failWith = null;

            public static function reset(): void
            {
                self::$requests = [];
                self::$failWith = null;
            }

            public static function request(Resource $resource, string $email, array $meta = []): Grant
            {
                if (self::$failWith !== null) {
                    throw self::$failWith;
                }

                self::$requests[] = ['resource' => $resource->handle, 'email' => $email, 'meta' => $meta];

                $email = mb_strtolower(trim($email));
                $returnUrl = ReturnUrl::accept($meta['return_url'] ?? null);
                $meta = array_diff_key($meta, ['return_url' => true]);

                if ($returnUrl !== null) {
                    $meta['return_url'] = $returnUrl;
                }

                $grant = Grant::query()->firstOrNew(['resource_id' => $resource->id, 'email' => $email]);
                $grant->meta = $meta;

                if (! $grant->exists) {
                    $grant->status = $resource->requires_confirmation ? 'pending' : 'active';
                }

                $grant->save();
                $grant->setRelation('resource', $resource);

                return $grant;
            }

            /**
             * What the click on the confirmation link does: activate, and
             * answer with where the browser goes next (or null).
             */
            public static function confirm(Grant $grant): ?string
            {
                $grant->forceFill(['status' => 'active'])->save();

                return ReturnUrl::accept($grant->meta['return_url'] ?? null);
            }
        }
    }
}

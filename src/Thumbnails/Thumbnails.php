<?php

namespace Goldnead\StatamicFunnels\Thumbnails;

use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\GraphWriter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * What is known about step thumbnails: whether they are on, where they go, and
 * which renderer is behind them.
 *
 * The picture itself is written by {@see StepRenderer}. This class holds the
 * facts every caller needs — the job, the listener, the command, the editor —
 * so that "where does a thumbnail live" is answered in one place. The key in
 * `funnel_steps.config` is **owned by the server**: the editor sends the config
 * back on every save, and {@see GraphWriter}
 * keeps the stored value rather than the client's copy, or a save made between
 * a render and a reload would quietly throw the picture away.
 */
class Thumbnails
{
    /** The key under a step's `config`: `{ path, rendered_at, hash }`. */
    public const KEY = 'thumbnail';

    public static function enabled(): bool
    {
        return (bool) config('statamic-funnels.thumbnails.enabled', true);
    }

    /** Whether the bound renderer can produce a picture at all. */
    public static function available(): bool
    {
        return ! app(ThumbnailRenderer::class) instanceof NullRenderer;
    }

    /**
     * The renderer for this host, decided once per process.
     *
     * Browsershot behind a `class_exists` guard: the package is a `suggest`,
     * and a site without it must never load the class. A Chromium is looked
     * for only when the package is there — the search costs a few stats and
     * there is no point paying them for a browser nothing could drive.
     */
    public static function detectRenderer(): ThumbnailRenderer
    {
        if (BrowsershotRenderer::available()) {
            return new BrowsershotRenderer((string) Chromium::find());
        }

        return new NullRenderer;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('statamic-funnels.thumbnails.disk', 'public'));
    }

    public static function width(): int
    {
        return max(1, (int) config('statamic-funnels.thumbnails.width', 640));
    }

    public static function height(): int
    {
        return max(1, (int) config('statamic-funnels.thumbnails.height', 400));
    }

    /**
     * Cookies the browser carries into the page, `name => value`.
     *
     * Configured ones win. With nothing configured and `statamic-consent`
     * installed, its own cookie with every service granted — otherwise every
     * thumbnail is a picture of the cookie banner, and a map on which every
     * station looks the same is no map.
     *
     * @return array<string, string>
     */
    public static function cookies(): array
    {
        $configured = config('statamic-funnels.thumbnails.cookies', []);

        if (is_array($configured) && $configured !== []) {
            return array_map('strval', array_filter($configured, 'is_scalar'));
        }

        return ConsentCookie::default() ?? [];
    }

    /**
     * CSS selectors hidden before the picture is taken. For the banner no
     * cookie can silence.
     *
     * @return list<string>
     */
    public static function hideSelectors(): array
    {
        $configured = config('statamic-funnels.thumbnails.hide_selectors', []);

        return is_array($configured)
            ? array_values(array_filter(array_map('trim', array_filter($configured, 'is_string'))))
            : [];
    }

    /** `funnels/thumbs/{funnel}/` — one folder per funnel, so deleting one is deleting a folder. */
    public static function directory(Funnel|int $funnel): string
    {
        $id = $funnel instanceof Funnel ? $funnel->getKey() : $funnel;

        return 'funnels/thumbs/'.$id;
    }

    public static function path(FunnelStep $step): string
    {
        return self::directory((int) $step->funnel_id).'/'.$step->node_key.'.png';
    }

    /**
     * Whether this step is a place a visitor stands on, and so has a page to
     * photograph. A mail hangs off a step and has no URL; it gets no picture.
     */
    public static function isPage(FunnelStep $step): bool
    {
        $class = app(StepRegistry::class)->find($step->type);

        return $class !== null && $class::isPage();
    }

    /**
     * What the picture depends on, boiled down to one string.
     *
     * Everything that decides what the page looks like, except the thumbnail
     * itself. Stored beside the picture, so a save that changed nothing about
     * a step does not photograph it again — and `--force` exists for the
     * change this cannot see, an edited entry or template behind the step.
     */
    public static function fingerprint(FunnelStep $step): string
    {
        $config = $step->config ?? [];
        unset($config[self::KEY]);
        ksort($config);

        return sha1(json_encode([
            'type' => $step->type,
            'label' => $step->label,
            'slug' => $step->slug,
            'config' => $config,
            'disabled' => (bool) $step->disabled,
        ]) ?: '');
    }

    /**
     * Whether the stored picture still matches the step and is actually there.
     */
    public static function isCurrent(FunnelStep $step): bool
    {
        $stored = $step->config(self::KEY);

        if (! is_array($stored) || empty($stored['path'])) {
            return false;
        }

        return ($stored['hash'] ?? null) === self::fingerprint($step)
            && self::disk()->exists($stored['path']);
    }

    /** The public URL of a step's picture, or null when it has none. */
    public static function url(FunnelStep $step): ?string
    {
        $stored = $step->config(self::KEY);

        if (! is_array($stored) || empty($stored['path'])) {
            return null;
        }

        return self::disk()->url($stored['path']);
    }

    /**
     * A step's config as the editor receives it: the stored thumbnail with its
     * URL folded in, so the browser never has to know which disk it is on.
     *
     * @return array<string, mixed>
     */
    public static function configForEditor(FunnelStep $step): array
    {
        $config = $step->config ?? [];

        if (is_array($config[self::KEY] ?? null) && ($url = self::url($step)) !== null) {
            $config[self::KEY]['url'] = $url;
        } else {
            unset($config[self::KEY]);
        }

        return $config;
    }

    /**
     * The stored config, with the client's copy of the thumbnail replaced by
     * the server's. The key belongs to the server; see the class comment.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function keepStored(array $incoming, ?FunnelStep $existing): array
    {
        unset($incoming[self::KEY]);

        $stored = $existing?->config(self::KEY);

        if (is_array($stored) && ! empty($stored['path'])) {
            $incoming[self::KEY] = $stored;
        }

        return $incoming;
    }

    /** Delete a step's picture, if it has one. The config is not touched. */
    public static function forget(FunnelStep $step): void
    {
        $stored = $step->config(self::KEY);

        if (is_array($stored) && ! empty($stored['path'])) {
            self::disk()->delete($stored['path']);
        }
    }

    /** Delete every picture of a funnel. For when the funnel itself goes. */
    public static function forgetFunnel(Funnel|int $funnel): void
    {
        self::disk()->deleteDirectory(self::directory($funnel));
    }

    /**
     * The saved graph in the shape the preview token carries.
     *
     * The preview route renders whatever graph its token holds; for a
     * thumbnail that is the table, verbatim.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function graph(Funnel $funnel): array
    {
        return [
            'nodes' => $funnel->steps->map(fn (FunnelStep $step) => [
                'node_key' => $step->node_key,
                'type' => $step->type,
                'label' => $step->label,
                'slug' => $step->slug,
                'config' => $step->config ?? [],
                'disabled' => (bool) $step->disabled,
            ])->values()->all(),
            'edges' => $funnel->edges->map(fn ($edge) => [
                'from_node_key' => $edge->from_node_key,
                'to_node_key' => $edge->to_node_key,
                'from_output' => $edge->from_output,
            ])->values()->all(),
        ];
    }
}

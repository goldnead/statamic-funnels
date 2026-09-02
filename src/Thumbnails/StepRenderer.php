<?php

namespace Goldnead\StatamicFunnels\Thumbnails;

use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Tokens\TokenRepository;
use Throwable;

/**
 * One step, one picture.
 *
 * Loads the step's own page through the same preview route the editor uses,
 * with the same kind of pass: minted for this render, good for one funnel, and
 * deleted the moment the picture is taken — a token is a file holding the whole
 * graph, and a queue that left one behind per render would fill a disk with
 * working URLs into unpublished funnels. Like the preview, it writes nothing:
 * no visit, no step event, no impression on an offer.
 *
 * The job and the command both come through here, so "what does rendering a
 * step mean" is answered once and the command can count what happened.
 */
class StepRenderer
{
    /** The picture was taken and stored. */
    public const RENDERED = 'rendered';

    /** The stored picture still matches the step; nothing was done. */
    public const CURRENT = 'current';

    /** Not a page, so nothing to photograph. Any stale picture was removed. */
    public const NOT_A_PAGE = 'not-a-page';

    /** No renderer on this host. The step is left exactly as it was. */
    public const NO_RENDERER = 'no-renderer';

    /** The renderer tried and failed. Logged; the step is left as it was. */
    public const FAILED = 'failed';

    /** Thumbnails are switched off in config. */
    public const DISABLED = 'disabled';

    /** How long the pass a render uses stays good, in case the delete never runs. */
    public const TOKEN_MINUTES = 2;

    public function __construct(protected ThumbnailRenderer $renderer) {}

    /**
     * @return self::RENDERED|self::CURRENT|self::NOT_A_PAGE|self::NO_RENDERER|self::FAILED|self::DISABLED
     */
    public function render(FunnelStep $step, bool $force = false): string
    {
        if (! Thumbnails::enabled()) {
            return self::DISABLED;
        }

        if (! Thumbnails::isPage($step)) {
            $this->strip($step);

            return self::NOT_A_PAGE;
        }

        if (! $force && Thumbnails::isCurrent($step)) {
            return self::CURRENT;
        }

        $funnel = $step->funnel()->with(['steps', 'edges'])->first();

        if (! $funnel) {
            return self::FAILED;
        }

        // Two minutes, not the preview's fifteen: this pass is used once, now,
        // and deleted below. The short life is for the case where that delete
        // never runs because the worker died mid-render.
        $token = PreviewToken::mint($funnel, Thumbnails::graph($funnel), minutes: self::TOKEN_MINUTES);

        $url = route('statamic-funnels.preview', [
            'funnel' => $funnel->handle,
            'nodeKey' => $step->node_key,
        ]).'?token='.$token;

        try {
            $png = $this->renderer->render(
                $url,
                Thumbnails::width(),
                Thumbnails::height(),
                Thumbnails::cookies(),
                Thumbnails::hideSelectors(),
            );
        } catch (Throwable $e) {
            // Browsershot quotes its whole command line in the message, pass
            // included. The pass is short-lived and deleted below, but a log
            // is copied into tickets and read months later.
            Log::warning('statamic-funnels: a step thumbnail could not be rendered.', [
                'funnel' => $funnel->handle,
                'node' => $step->node_key,
                'exception' => preg_replace('/token=[^&\s\'"]+/', 'token=***', $e->getMessage()),
            ]);

            return self::FAILED;
        } finally {
            // Through the repository, not the facade: the facade's docblock
            // promises a token, the repository admits it may be gone.
            app(TokenRepository::class)->find($token)?->delete();
        }

        if ($png === null) {
            return self::NO_RENDERER;
        }

        $path = Thumbnails::path($step);

        if (! Thumbnails::disk()->put($path, $png)) {
            Log::warning('statamic-funnels: a step thumbnail could not be stored.', [
                'funnel' => $funnel->handle,
                'node' => $step->node_key,
                'path' => $path,
            ]);

            return self::FAILED;
        }

        $config = $step->config ?? [];
        $config[Thumbnails::KEY] = [
            'path' => $path,
            'rendered_at' => now()->toIso8601String(),
            'hash' => Thumbnails::fingerprint($step),
        ];

        $step->config = $config;
        $step->save();

        return self::RENDERED;
    }

    /** A step that stopped being a page keeps no picture of the page it was. */
    protected function strip(FunnelStep $step): void
    {
        if (! is_array($step->config(Thumbnails::KEY))) {
            return;
        }

        Thumbnails::forget($step);

        $config = $step->config ?? [];
        unset($config[Thumbnails::KEY]);

        $step->config = $config;
        $step->save();
    }
}

<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Tokens\PreviewHandler;
use Statamic\Contracts\Tokens\Token as TokenContract;
use Statamic\Facades\Token;

/**
 * A short-lived pass that lets the Control Panel look at a step.
 *
 * The same idea as Statamic's own Live Preview token, and deliberately the same
 * machinery (`Statamic\Facades\Token`, so expiry and garbage collection are the
 * platform's problem, not this addon's). What it carries is different: not one
 * entry's unsaved values but the **whole unsaved graph**, because in a funnel
 * the thing being previewed is a path and a step only makes sense inside one.
 *
 * Three properties it has to have, and they are why this is a token rather than
 * a query parameter:
 *
 * 1. **Only the Control Panel can mint one.** The route that creates it sits
 *    behind the utility's permission. Without that, anybody could render an
 *    unpublished funnel by guessing a URL.
 * 2. **It writes nothing.** No visit, no step event, no impression on an offer.
 *    A preview that counted itself would quietly poison the very numbers the
 *    funnel is judged by.
 * 3. **It expires.** A link pasted into a chat two weeks later shows nothing.
 */
class PreviewToken
{
    public const HANDLER = PreviewHandler::class;

    /**
     * How long a pass is good for.
     *
     * Short, because it travels as a query parameter: it lands in every access
     * log the preview passes through and in the `Referer` of every external
     * asset the previewed page loads. Every refresh extends it, so a pass only
     * runs out when somebody has stopped looking.
     */
    public const MINUTES = 15;

    /**
     * A pass for this editing session, reused.
     *
     * Reused rather than reissued, and that matters more than it sounds: the
     * editor asks for a refresh on every keystroke, and a token is a file on
     * disk holding a full copy of the graph. Minting one each time left dozens
     * of them lying about after half an hour of typing, each a working URL into
     * an unpublished funnel — and nothing collects them, because this route is
     * not in the group where Statamic's token garbage collection runs.
     *
     * Passing the previous token back in overwrites it in place and pushes the
     * expiry out.
     *
     * @param  array<string, mixed>  $graph
     */
    public static function mint(Funnel $funnel, array $graph, ?string $reuse = null): string
    {
        $existing = self::valid($reuse, $funnel);

        $token = Token::make($existing?->token(), self::HANDLER, [
            'funnel_id' => $funnel->id,
            'graph' => $graph,
        ]);

        $token->expireAt(now()->addMinutes(self::MINUTES));
        $token->save();

        return $token->token();
    }

    /** The token behind a string, if it is one of ours and still good for this funnel. */
    protected static function valid(?string $token, Funnel $funnel): ?TokenContract
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $found = Token::find($token);

        if (! $found || $found->handler() !== self::HANDLER || $found->hasExpired()) {
            return null;
        }

        return (int) $found->get('funnel_id') === (int) $funnel->id ? $found : null;
    }

    /**
     * The graph a token stands for, or null if the token is no good for this funnel.
     *
     * @return array<string, mixed>|null
     */
    public static function graph(?string $token, Funnel $funnel): ?array
    {
        // Bound to one funnel. A pass for funnel A must not open funnel B, even
        // though both are drafts belonging to the same site.
        $found = self::valid($token, $funnel);

        if (! $found) {
            return null;
        }

        $graph = $found->get('graph');

        return is_array($graph) ? $graph : null;
    }

    /**
     * One step of a previewed graph, as an unsaved model.
     *
     * Unsaved on purpose: everything downstream reads a `FunnelStep`, and giving
     * it a real one keeps the preview path and the live path the same code. It
     * is never saved, so nothing about looking at a funnel changes it.
     *
     * @param  array<string, mixed>  $graph
     */
    public static function step(array $graph, string $nodeKey, Funnel $funnel): ?FunnelStep
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['node_key'] ?? null) !== $nodeKey) {
                continue;
            }

            $step = new FunnelStep([
                'funnel_id' => $funnel->id,
                'node_key' => $node['node_key'],
                'type' => $node['type'] ?? 'page',
                'label' => $node['label'] ?? null,
                'slug' => $node['slug'] ?? null,
                'config' => $node['config'] ?? [],
                'disabled' => (bool) ($node['disabled'] ?? false),
            ]);

            $step->setRelation('funnel', $funnel);

            return $step;
        }

        return null;
    }
}

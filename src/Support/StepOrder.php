<?php

namespace Goldnead\StatamicFunnels\Support;

/**
 * The steps of a funnel in the order somebody walks them.
 *
 * Not the order they were saved in, and not the order they were drawn in. A
 * stepper that runs down the table's primary key shows the entry in the middle
 * and the thank-you page first, which teaches the wrong thing about the funnel
 * on the very screen meant to explain it.
 *
 * Depth-first from the entry, following each output to the end before taking
 * the next one. Breadth-first was the first attempt and reads wrong to a person:
 * with `Offer → (accepted: Upsell → Thanks) / (declined: Sorry)` it produces
 * Offer, Upsell, Sorry, Thanks — the two paths interleaved. Somebody stepping
 * through a funnel walks one way to its end, then comes back for the other.
 *
 * Anything unreachable is appended at the end rather than dropped: an orphaned
 * step is exactly what somebody opens the preview to notice.
 */
class StepOrder
{
    /**
     * @param  list<array{node_key: string, type: string, ...}>  $nodes
     * @param  list<array{from_node_key: string, to_node_key: string, from_output?: string|null}>  $edges
     * @return list<string> Node keys, in walking order.
     */
    public static function keys(array $nodes, array $edges): array
    {
        $present = [];

        foreach ($nodes as $node) {
            $present[$node['node_key']] = $node;
        }

        $out = [];

        foreach ($edges as $edge) {
            $out[$edge['from_node_key']][] = $edge;
        }

        $start = null;

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'entry') {
                $start = $node['node_key'];
                break;
            }
        }

        $ordered = [];
        $seen = [];

        if ($start !== null) {
            // Iterative rather than recursive: a graph is drawn by a person and
            // a person can draw a hundred steps in a line, which is a stack
            // depth no one should have to think about.
            $stack = [$start];

            while ($stack !== []) {
                $key = array_pop($stack);

                // A cycle is legal to draw and would otherwise be walked for
                // ever. Seen once is enough for an ordering.
                if (isset($seen[$key]) || ! isset($present[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $ordered[] = $key;

                // Mails zuerst, direkt hinter ihrem Schritt. Sie sind keine
                // Station, sondern haengen an einer — im Stepper gehoeren sie
                // deshalb neben den Schritt, der sie ausloest, nicht ans Ende
                // eines Zweigs, den sie nie betreten.
                foreach ($out[$key] ?? [] as $edge) {
                    $to = $edge['to_node_key'];

                    if (isset($seen[$to]) || (($present[$to]['type'] ?? null) !== 'mail')) {
                        continue;
                    }

                    $seen[$to] = true;
                    $ordered[] = $to;
                }

                // Pushed in reverse so the first declared output is popped
                // first: the `accepted` branch of an offer is the one somebody
                // means to look at first, and it is declared first.
                foreach (array_reverse($out[$key] ?? []) as $edge) {
                    if (! isset($seen[$edge['to_node_key']])) {
                        $stack[] = $edge['to_node_key'];
                    }
                }
            }
        }

        foreach ($nodes as $node) {
            if (! isset($seen[$node['node_key']])) {
                $ordered[] = $node['node_key'];
            }
        }

        return $ordered;
    }
}

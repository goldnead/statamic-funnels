<?php

namespace Goldnead\StatamicFunnels\Registries;

use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicFunnels\Nodes\EntryStep;
use Goldnead\StatamicFunnels\Nodes\FinishStep;
use Goldnead\StatamicFunnels\Nodes\OfferStep;
use Goldnead\StatamicFunnels\Nodes\PageStep;
use Goldnead\StatamicFunnels\Nodes\StepType;
use InvalidArgumentException;

/**
 * What may be dropped on the canvas.
 *
 * The registry is what the browser is handed: the shared editor knows how to
 * draw a graph and nothing about funnels, so every label, icon, output and
 * field it shows comes from here.
 */
class StepRegistry
{
    /** @var array<string, class-string<StepType>> */
    protected array $types = [];

    public function __construct()
    {
        foreach ([EntryStep::class, CaptureStep::class, PageStep::class, OfferStep::class, FinishStep::class] as $class) {
            $this->register($class);
        }
    }

    /**
     * @param  class-string<StepType>  $class
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, StepType::class)) {
            throw new InvalidArgumentException($class.' is not a step type.');
        }

        $this->types[$class::handle()] = $class;
    }

    /** @return class-string<StepType>|null */
    public function find(string $handle): ?string
    {
        return $this->types[$handle] ?? null;
    }

    public function has(string $handle): bool
    {
        return isset($this->types[$handle]);
    }

    /** @return array<string, class-string<StepType>> */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The node library, grouped the way the editor's kinds are.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function library(): array
    {
        $library = [];

        foreach ($this->types as $handle => $class) {
            $library[$class::kind().'s'][] = [
                'handle' => $handle,
                'label' => $class::label(),
                'description' => $class::description(),
                'icon' => $class::icon(),
                'kind' => $class::kind(),
                'schema' => $class::schema(),
                // The grammar the shared canvas evaluates, not a shape of our
                // own: `clauses`, each with the outputs that apply. Anything
                // else resolves to a single `default`, and an offer would draw
                // one handle where it declared two — with the branches already
                // wired underneath, going nowhere.
                'outputs' => [
                    'version' => 1,
                    'clauses' => [
                        ['outputs' => $class::outputs()],
                    ],
                ],
            ];
        }

        return $library;
    }
}

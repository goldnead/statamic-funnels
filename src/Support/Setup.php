<?php

namespace Goldnead\StatamicFunnels\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * The funnels utility registers itself the moment the addon is installed, so
 * the nav entry exists before anybody has run `php artisan migrate`. Clicking
 * it then ran `Funnel::query()->withCount(...)` against tables that were never
 * created, and the reader got HTTP 500 for what is really an unfinished setup.
 * Unfinished setup owes them a sentence, not a stack trace.
 *
 * The reason must not vanish with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an empty
 * state and says nothing anywhere would be worse than the crash it replaced —
 * the site would look installed and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-funnels: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('statamic-funnels::SetupRequired', [
            'title' => $title,
            'heading' => __('statamic-funnels::messages.setup_required_heading'),
            'description' => __('statamic-funnels::messages.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}

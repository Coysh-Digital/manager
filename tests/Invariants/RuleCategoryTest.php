<?php

declare(strict_types=1);

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use Illuminate\Support\Facades\File;

/*
 * Every rule belongs to exactly one screen.
 *
 * Findings and Security are one table split by category, and the split is done in the query:
 * Security asks for `RuleCategory::keysFor(SECURITY)`, Findings asks for everything else. A rule
 * whose category is not one of the known three would therefore be listed by neither - it would open,
 * notify, count towards nothing, and appear on no screen, with every other test in the suite green.
 *
 * That is not hypothetical for long. Adding a rule is a routine change; remembering that two
 * unrelated controllers filter on a method you have just implemented is not. This is the tripwire,
 * and it is written to fail rather than warn.
 */

it('gives every rule a known category', function (): void {
    $rules = app(FindingsEvaluator::class)->rules();

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect(RuleCategory::isKnown($rule->category()))
            ->toBeTrue($rule->key().' declares the unknown category "'.$rule->category().'"');
    }
});

it('puts every rule in exactly one category', function (): void {
    $all = array_map(static fn (Rule $rule): string => $rule->key(), app(FindingsEvaluator::class)->rules());

    $categorised = [];

    foreach (RuleCategory::ordered() as $category) {
        $categorised = array_merge($categorised, RuleCategory::keysFor($category));
    }

    // Set equality in both directions, and no duplicates. A rule in two categories is counted by
    // both sidebar badges, so the two stop summing to the truth.
    sort($all);
    sort($categorised);

    expect($categorised)->toBe($all)
        ->and(array_unique($categorised))->toHaveCount(count($all))
        ->and(RuleCategory::allKeys())->toHaveCount(count($all));
});

it('has a rule in every category it offers', function (): void {
    // Not pedantry: a category with no rules is a screen or a filter that can never show anything,
    // and the cheapest moment to notice is the one where somebody adds the category.
    foreach (RuleCategory::ordered() as $category) {
        expect(RuleCategory::keysFor($category))
            ->not->toBeEmpty('no rule declares the category "'.$category.'"');
    }
});

it('declares a category on every rule class on disk', function (): void {
    /*
     | The evaluator's list is hand-maintained, so a rule can exist as a class and never be
     | registered. That is a different fault from an uncategorised one and this suite is the only
     | place it would show: the file is written, the tests for it pass, and it never runs.
     */
    $classes = collect(File::files(app_path('Domain/Findings/Rules')))
        ->map(fn ($file): string => 'App\\Domain\\Findings\\Rules\\'.$file->getFilenameWithoutExtension())
        ->values()
        ->all();

    $registered = array_map(
        static fn (Rule $rule): string => $rule::class,
        app(FindingsEvaluator::class)->rules(),
    );

    sort($classes);
    sort($registered);

    expect($registered)->toBe($classes);
});

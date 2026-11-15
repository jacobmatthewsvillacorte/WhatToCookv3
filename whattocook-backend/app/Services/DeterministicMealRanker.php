<?php

namespace App\Services;

use App\Models\PantryItem;
use App\Models\Recipe;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** A stable, inspectable ranking policy. Safety exclusions always happen before scoring. */
class DeterministicMealRanker
{
    public function __construct(
        private readonly FoodSafetyTaxonomy $foodSafety,
        private readonly ChildMealPlanner $childPlanner,
    ) {}

    public function rank(Collection $recipes, Collection $profiles, Collection $pantry, Collection $history, array $options, array $alreadyChosen = [], ?Carbon $mealDate = null): Collection
    {
        $likes = $profiles->flatMap(fn ($p) => $p->likes ?? [])->map(fn ($v) => strtolower($v));
        $dislikes = $profiles->flatMap(fn ($p) => $p->dislikes ?? [])->map(fn ($v) => strtolower($v));
        // A child can cross an age-band boundary between planning and cooking.
        // Always calculate eligibility for the scheduled meal date, never the
        // date the API happened to load the household profile.
        $mealDate ??= now();
        $isYoungChild = $profiles->contains(fn ($p) => in_array(
            $this->childPlanner->ageBand($p->birth_date, $mealDate),
            ['0-5_months', '6-11_months', '12-23_months', '2-5_years'],
            true,
        ));
        $hasLowSugarDiner = $profiles->contains(function ($profile) {
            $condition = strtolower((string) ($profile->health_condition ?? 'none'));
            $legacyConditions = collect($profile->health_conditions ?? [])->map(fn ($value) => strtolower((string) $value));

            return in_array($condition, ['diabetic', 'low_sugar'], true)
                || $legacyConditions->contains(fn ($value) => str_contains($value, 'diabet') || str_contains($value, 'low sugar'));
        });
        $strict = ($options['time_preference'] ?? 'flexible') === 'strict';
        $budget = $this->budget($options['cooking_time_budget'] ?? '60');

        return $recipes->filter(function (Recipe $recipe) use ($profiles, $isYoungChild, $hasLowSugarDiner) {
            $terms = $this->terms($recipe);
            if (! $this->foodSafety->recipeIsSafe($recipe, $profiles)) return false;
            if ($hasLowSugarDiner && $terms->contains(fn ($text) => preg_match('/(?<!free )\b(sugar|honey|syrup|sweetened|condensed milk|soft drink|soda|dessert|cake|candy|sweet and sour|sweet chili|teriyaki|ketchup|barbecue sauce|bbq sauce)\b/', $text))) return false;
            // Conservative child rule: recipes with alcohol or explicitly very spicy ingredients are not auto-selected.
            return ! $isYoungChild || ! $terms->contains(fn ($text) => str_contains($text, 'alcohol') || str_contains($text, 'siling labuyo'));
        })->filter(fn (Recipe $recipe) => ! $strict || $this->minutes($recipe) <= $budget)
            ->map(function (Recipe $recipe) use ($pantry, $history, $alreadyChosen, $likes, $dislikes, $budget, $strict, $hasLowSugarDiner) {
                $terms = $this->terms($recipe);
                $ingredientNames = $recipe->ingredients->pluck('name')->map(fn ($v) => strtolower($v));
                $nutrition = min(30, (($recipe->protein ?? 0) / 2) + (($recipe->calories ?? 0) >= 180 && ($recipe->calories ?? 0) <= 650 ? 8 : 0));
                $lowSugarPriority = $hasLowSugarDiner
                    && (int) ($recipe->protein ?? 0) >= 15
                    && $terms->contains(fn ($text) => preg_match('/kangkong|spinach|cabbage|cauliflower|eggplant|string beans|ampalaya|pechay|malunggay|broccoli/', $text))
                    ? 6 : 0;
                $expiryHits = $pantry->filter(fn (PantryItem $item) => $item->expiry_date && Carbon::parse($item->expiry_date)->betweenIncluded(now(), now()->copy()->addDays(3)))
                    ->filter(fn ($item) => $ingredientNames->contains(fn ($name) => str_contains(strtolower($item->name), $name) || str_contains($name, strtolower($item->name))))->count();
                $pantryHits = $pantry->filter(fn ($item) => $ingredientNames->contains(fn ($name) => str_contains(strtolower($item->name), $name) || str_contains($name, strtolower($item->name))))->count();
                $minutes = $this->minutes($recipe);
                $likeScore = $likes->filter(fn ($term) => $terms->contains(fn ($text) => str_contains($text, $term)))->count() * 6;
                $dislikePenalty = $dislikes->filter(fn ($term) => $terms->contains(fn ($text) => str_contains($text, $term)))->count() * 12;
                $repeatPenalty = (in_array($recipe->id, $alreadyChosen, true) ? 45 : 0) + ($history->contains('recipe_id', $recipe->id) ? 15 : 0);
                $timeScore = $strict ? 0 : max(0, 12 - abs($budget - $minutes) / 5);
                $recipe->ranking = ['nutrition' => round($nutrition, 1), 'low_sugar_priority' => $lowSugarPriority, 'pantry' => $pantryHits * 5, 'expiry' => $expiryHits * 12, 'time' => round($timeScore, 1), 'preference' => $likeScore - $dislikePenalty, 'variety_penalty' => $repeatPenalty, 'score' => round($nutrition + $lowSugarPriority + $pantryHits * 5 + $expiryHits * 12 + $timeScore + $likeScore - $dislikePenalty - $repeatPenalty, 1)];
                $recipe->why_chosen = $this->why($recipe, $expiryHits, $pantryHits, $minutes, $budget, $alreadyChosen);
                return $recipe;
            })->sort(function (Recipe $a, Recipe $b) {
                $score = $b->ranking['score'] <=> $a->ranking['score'];
                return $score ?: ($a->id <=> $b->id);
            })->values();
    }

    private function terms(Recipe $recipe): Collection { return $recipe->ingredients->pluck('name')->push($recipe->name)->map(fn ($v) => strtolower((string) $v)); }
    private function minutes(Recipe $recipe): int { return (int) ($recipe->prep_time ?? 0) + (int) ($recipe->cook_time ?? 0); }
    private function budget(string|int $value): int { return $value === '90+' ? 999 : (int) $value; }
    private function why(Recipe $r, int $expiry, int $pantry, int $minutes, int $budget, array $chosen): array {
        $why = ['Passed allergy, dietary, and age safety checks.', 'Nutrition score: '.$r->ranking['nutrition'].'.'];
        if ($expiry) $why[] = "Uses {$expiry} pantry ingredient(s) nearing expiry."; elseif ($pantry) $why[] = "Uses {$pantry} ingredient(s) already in the pantry.";
        if ($minutes <= $budget) $why[] = "Estimated {$minutes} minutes fits the selected time preference.";
        if (!in_array($r->id, $chosen, true)) $why[] = 'Avoids repeating an earlier meal in this draft.';
        return $why;
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use App\Models\HouseholdProfile;
use App\Models\MealPlan;
use App\Models\PantryItem;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Helpers shared by every controller that plans meals for a personal or
 * family scope (recipes, meal plans, meal-plan batches).
 * See docs/13-meal-planning-lifecycle.md.
 */
trait PlansForHousehold
{
    /** De-duplicates diner ids and rejects any that are not selectable in the family. */
    protected function validatedDiners(?int $familyId, array $dinerIds): array
    {
        $dinerIds = array_values(array_unique(array_map('intval', $dinerIds)));
        if (! $dinerIds) {
            return [];
        }
        if (! $familyId || HouseholdProfile::selectableForFamily($familyId)->whereIn('id', $dinerIds)->count() !== count($dinerIds)) {
            throw ValidationException::withMessages(['diner_profile_ids' => ['Each diner must belong to the selected household.']]);
        }

        return $dinerIds;
    }

    /** Servings adjusted for children's portions; falls back to plain servings. */
    protected function servingEquivalent(MealPlan $plan): float
    {
        return (float) ($plan->serving_equivalents ?? $plan->servings);
    }

    /** Conservative rule: alcohol and siling labuyo are never auto-picked when a young child eats. */
    protected function hasYoungChildConflict(Recipe $recipe): bool
    {
        return $recipe->ingredients->pluck('name')->push($recipe->name)
            ->contains(fn ($term) => str_contains(strtolower((string) $term), 'alcohol') || str_contains(strtolower((string) $term), 'siling labuyo'));
    }

    /** Usable (fresh or review) pantry items for the active scope: the family's, or the user's personal ones. */
    protected function pantryFor(Request $request, ?int $familyId)
    {
        return PantryItem::whereIn('freshness_status', ['fresh', 'review'])
            ->where(fn ($query) => $familyId === null
                ? $query->where('user_id', $request->user()->id)->whereNull('family_id')
                : $query->where('family_id', $familyId))
            ->get();
    }
}

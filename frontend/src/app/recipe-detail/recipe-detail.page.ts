import { Component } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService, RecipeDetail, RecipeNutrition, RecipeReview } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';

@Component({ selector: 'app-recipe-detail', templateUrl: './recipe-detail.page.html', styleUrls: ['./recipe-detail.page.scss'], standalone: false })
export class RecipeDetailPage {
  recipe?: RecipeDetail; nutrition?: RecipeNutrition; reviews: RecipeReview[] = []; servings = 2; rating = 5; reviewText = ''; favorite = false; loading = false; startingCooking = false; message = '';
  private requestedFamilyId?: number;
  constructor(private api: ApiService, private route: ActivatedRoute, private router: Router, private householdContext: HouseholdContextService, private auth: AuthService) {}
  ionViewWillEnter(): void { const id = Number(this.route.snapshot.paramMap.get('id')); const familyId = Number(this.route.snapshot.queryParamMap.get('family_id')); this.requestedFamilyId = Number.isInteger(familyId) && familyId > 0 ? familyId : undefined; if (!Number.isInteger(id) || id < 1) { this.router.navigateByUrl('/tabs/recipes', { replaceUrl: true }); return; } this.load(id); }
  load(id: number): void { this.loading = true; this.message = ''; this.api.recipe(id).subscribe({ next: recipe => { this.recipe = recipe; this.servings = recipe.servings || 2; this.loading = false; this.loadReviews(id); this.loadFavorite(id); this.api.recipeNutrition(id).subscribe({ next: nutrition => this.nutrition = nutrition, error: () => this.nutrition = undefined }); }, error: () => { this.loading = false; this.message = 'Could not load this recipe.'; } }); }
  ingredientQuantity(quantity?: string): string { const value = Number(quantity); if (!quantity || Number.isNaN(value)) return quantity || ''; return String(Math.round(value * this.servings / (this.recipe?.servings || 2) * 100) / 100); }
  nutritionForSelectedServings(nutrient: string): number {
    if (!this.nutrition) return 0;
    return Math.round((this.nutrition.per_serving[nutrient] || 0) * this.servings * 100) / 100;
  }
  get cookingSteps(): string[] { return (this.recipe?.instructions || '').split(/\r?\n/).map(step => step.trim()).filter(Boolean); }
  get totalTime(): number { return (this.recipe?.prep_time || 0) + (this.recipe?.cook_time || 0); }
  unknownNutrientNames(): string[] { return Object.keys(this.nutrition?.unknown_nutrients || {}); }
  unavailableReason(reason: string): string { return reason === 'nutrition_food_not_linked' ? 'No food record linked' : 'Quantity cannot be converted to grams'; }
  recipeImage(): string { return this.recipe?.image_url || 'assets/shapes.svg'; }
  useFallbackImage(event: Event): void { (event.target as HTMLImageElement).src = 'assets/shapes.svg'; }
  toggleFavorite(): void { if (!this.recipe) return; (this.favorite ? this.api.unfavoriteRecipe(this.recipe.id) : this.api.favoriteRecipe(this.recipe.id)).subscribe({ next: () => this.favorite = !this.favorite, error: () => this.message = 'Could not update favorites.' }); }
  cookNow(): void {
    if (!this.recipe || this.startingCooking) return;
    const userId = this.auth.user?.id;
    if (!userId) { this.message = 'Sign in before starting a cooking session.'; return; }
    this.startingCooking = true;
    this.householdContext.refresh(userId).subscribe({
      next: context => this.api.createMealPlan({
        recipe_id: this.recipe!.id,
        family_id: this.requestedFamilyId || context.activeFamily?.id,
        planned_date: new Date().toISOString().slice(0, 10),
        meal_type: this.recipe!.meal_type || 'dinner',
        servings: Math.max(1, Number(this.servings) || this.recipe!.servings || 1),
      }).subscribe({
        next: plan => this.router.navigate(['/cooking', plan.id]),
        error: error => { this.startingCooking = false; this.message = error?.error?.message || 'Could not start cooking. Please try again.'; },
      }),
      error: () => { this.startingCooking = false; this.message = 'Could not determine which pantry to cook from.'; },
    });
  }
  submitReview(): void { if (!this.recipe) return; this.api.reviewRecipe(this.recipe.id, this.rating, this.reviewText).subscribe({ next: () => { this.reviewText = ''; this.loadReviews(this.recipe!.id); }, error: () => this.message = 'Could not save your rating.' }); }
  private loadReviews(id: number): void { this.api.recipeReviews(id).subscribe({ next: response => this.reviews = Array.isArray(response) ? response : response.reviews, error: () => this.reviews = [] }); }
  private loadFavorite(id: number): void { this.api.favorites().subscribe({ next: recipes => this.favorite = recipes.some(recipe => recipe.id === id), error: () => this.favorite = false }); }
}

import { Component } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService, MealPlanPreflight, RecipeIngredient } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { CookingProgressService } from '../services/cooking-progress.service';

@Component({
  selector: 'app-cooking',
  templateUrl: './cooking.page.html',
  styleUrls: ['./cooking.page.scss'],
  standalone: false,
})
export class CookingPage {
  preflight?: MealPlanPreflight;
  steps: string[] = [];
  ingredientChecks: boolean[] = [];
  stepIndex = 0;
  loading = false;
  finishing = false;
  confirmingCook = false;
  confirmingWithoutDeduction = false;
  message = '';
  mealPlanId?: number;
  completionNotes = '';
  timerMinutes = 5;
  timerSeconds = 0;
  timerRunning = false;
  private timerHandle?: ReturnType<typeof setInterval>;

  readonly cookAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Cook & deduct pantry', role: 'confirm', handler: () => this.finishWithPantryDeduction() },
  ];
  readonly withoutDeductionAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Mark cooked', role: 'confirm', handler: () => this.finishWithoutPantryDeduction() },
  ];

  constructor(
    private api: ApiService,
    private auth: AuthService,
    private progress: CookingProgressService,
    private route: ActivatedRoute,
    private router: Router,
  ) {}

  ionViewWillEnter(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!Number.isInteger(id) || id < 1 || !this.auth.user?.id) {
      this.router.navigateByUrl('/tabs/meal-plan', { replaceUrl: true });
      return;
    }
    this.mealPlanId = id;
    this.load();
  }

  get currentStep(): string | undefined { return this.steps[this.stepIndex]; }
  get isLastStep(): boolean { return this.stepIndex === this.steps.length - 1; }
  get progressValue(): number { return this.steps.length ? (this.stepIndex + 1) / this.steps.length : 0; }
  get progressLabel(): string { return this.steps.length ? `Step ${this.stepIndex + 1} of ${this.steps.length}` : 'No steps available'; }
  get totalTime(): number { return (this.preflight?.recipe.prep_time || 0) + (this.preflight?.recipe.cook_time || 0); }
  recipeImage(): string { return this.preflight?.recipe.image_url || 'assets/shapes.svg'; }
  useFallbackImage(event: Event): void { (event.target as HTMLImageElement).src = 'assets/shapes.svg'; }
  get checkedIngredientCount(): number { return this.ingredientChecks.filter(Boolean).length; }
  get notReadyIngredients() { return this.preflight?.ingredients_by_status.low_stock.concat(this.preflight.ingredients_by_status.missing, this.preflight.ingredients_by_status.needs_review) || []; }
  get pantryReadinessMessage(): string {
    const items = this.notReadyIngredients;
    if (!items.length) return 'All required ingredients are ready in the pantry.';
    return `Pantry check: ${items.map(item => item.status === 'needs_review' ? `${item.name} needs review` : `${item.name} ${item.status === 'low_stock' ? `needs ${item.missing_quantity ?? 'more'}` : 'is missing'}`).join('; ')}.`;
  }

  ingredientLabel(ingredient: RecipeIngredient): string {
    return [ingredient.quantity, ingredient.unit, ingredient.name].filter(Boolean).join(' ');
  }

  previous(): void { this.setStep(this.stepIndex - 1); }
  next(): void { this.setStep(this.stepIndex + 1); }
  toggleIngredient(index: number, checked: boolean): void { this.ingredientChecks[index] = checked; }
  startTimer(): void {
    if (this.timerRunning) return;
    this.timerMinutes = Math.max(1, Math.min(180, Number(this.timerMinutes) || 1));
    this.timerRunning = true;
    this.timerHandle = setInterval(() => {
      this.timerSeconds++;
      if (this.timerSeconds >= this.timerMinutes * 60) this.pauseTimer();
    }, 1000);
  }
  pauseTimer(): void { this.timerRunning = false; if (this.timerHandle) clearInterval(this.timerHandle); this.timerHandle = undefined; }
  resetTimer(): void { this.pauseTimer(); this.timerSeconds = 0; }
  get timerLabel(): string { const minutes = Math.floor(this.timerSeconds / 60); return `${minutes}:${String(this.timerSeconds % 60).padStart(2, '0')}`; }
  requestFinishWithPantryDeduction(): void {
    if (this.finishing) return;
    if (!this.preflight?.can_cook_from_pantry) {
      this.message = `${this.pantryReadinessMessage} Add or update the ingredients before deducting pantry stock.`;
      return;
    }
    this.confirmingCook = true;
  }
  requestFinishWithoutPantryDeduction(): void { if (!this.finishing) this.confirmingWithoutDeduction = true; }

  finishWithPantryDeduction(): void {
    if (!this.mealPlanId || this.finishing) return;
    this.confirmingCook = false;
    this.finish(this.api.cookMealPlan(this.mealPlanId, this.completionNotes), 'Meal cooked and pantry stock updated.');
  }

  finishWithoutPantryDeduction(): void {
    if (!this.mealPlanId || this.finishing) return;
    this.confirmingWithoutDeduction = false;
    this.finish(this.api.completeMealPlanWithoutDeduction(this.mealPlanId, this.completionNotes), 'Meal marked as cooked; pantry stock was not changed.');
  }

  private load(): void {
    if (!this.mealPlanId) return;
    this.loading = true;
    this.message = '';
    this.api.mealPlanPreflight(this.mealPlanId).subscribe({
      next: preflight => {
        if (preflight.meal_plan.completed_at) {
          this.router.navigate(['/meal-details', this.mealPlanId], { replaceUrl: true });
          return;
        }
        this.preflight = preflight;
        this.steps = this.parseSteps(preflight.recipe.instructions);
        this.ingredientChecks = preflight.recipe.ingredients.map(() => false);
        this.stepIndex = this.progress.load(this.auth.user!.id, this.mealPlanId!, this.steps.length).stepIndex;
        this.loading = false;
      },
      error: error => { this.loading = false; this.message = error?.error?.message || 'Could not load this cooking session.'; },
    });
  }

  private setStep(index: number): void {
    if (index < 0 || index >= this.steps.length || !this.mealPlanId || !this.auth.user) return;
    this.stepIndex = index;
    this.progress.save(this.auth.user.id, this.mealPlanId, index);
  }

  private finish(request: ReturnType<ApiService['cookMealPlan']>, fallback: string): void {
    if (!this.mealPlanId || !this.auth.user) return;
    this.finishing = true;
    this.message = '';
    request.subscribe({
      next: result => {
        this.progress.clear(this.auth.user!.id, this.mealPlanId!);
        this.router.navigate(['/tabs/dashboard'], { state: { message: result.message || fallback }, replaceUrl: true });
      },
      error: error => { this.finishing = false; this.message = error?.error?.message || 'Could not finish this meal. Please try again.'; },
    });
  }

  private parseSteps(instructions?: string): string[] {
    return (instructions || '').split(/\r?\n/).map(step => step.trim()).filter(Boolean);
  }

  ionViewWillLeave(): void { this.pauseTimer(); }
}

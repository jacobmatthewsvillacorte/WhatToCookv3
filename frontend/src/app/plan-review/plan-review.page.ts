import { Component } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {
  ApiService,
  HouseholdProfile,
  MealPlan,
  MealPlanBatchResponse,
  MealPlanIngredientStatus,
  PurchasedPlanIngredient,
  Recommendation,
} from '../services/api.service';
import { PantryChangeService } from '../services/pantry-change.service';

type MealType = 'breakfast' | 'lunch' | 'dinner';
type StorageType = 'room_temperature' | 'refrigerated' | 'frozen' | 'other' | 'unknown';

interface MealEditor {
  id: number;
  recipeId: number;
  plannedDate: string;
  mealType: MealType;
  servings: number;
  dinerIds: number[];
}

interface PurchasedItemDraft {
  selected: boolean;
  name: string;
  quantity: number | null;
  unit: string;
  storageType: StorageType;
}

@Component({
  selector: 'app-plan-review',
  templateUrl: './plan-review.page.html',
  styleUrls: ['./plan-review.page.scss'],
  standalone: false,
})
export class PlanReviewPage {
  readonly storageTypes: Array<{ value: StorageType; label: string }> = [
    { value: 'unknown', label: 'Not sure yet' },
    { value: 'room_temperature', label: 'Room temperature' },
    { value: 'refrigerated', label: 'Refrigerated' },
    { value: 'frozen', label: 'Frozen' },
    { value: 'other', label: 'Other' },
  ];

  batchId?: number;
  response?: MealPlanBatchResponse;
  diners: HouseholdProfile[] = [];
  recommendations: Recommendation[] = [];
  editor?: MealEditor;
  purchaseDrafts?: PurchasedItemDraft[];
  loading = false;
  saving = false;
  addingShortages = false;
  addingPurchasedItems = false;
  replaceConflicts = false;
  message = '';
  editorMessage = '';
  purchaseMessage = '';
  shortagesAdded = false;
  private modalReturnFocus?: HTMLElement;

  constructor(
    private api: ApiService,
    private route: ActivatedRoute,
    private router: Router,
    private pantryChanges: PantryChangeService,
  ) {}

  ionViewWillEnter(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!Number.isInteger(id) || id < 1) {
      this.router.navigateByUrl('/plan-setup', { replaceUrl: true });
      return;
    }
    this.batchId = id;
    this.load();
  }

  get meals(): MealPlan[] {
    return this.response?.meal_plans || [];
  }

  get ingredients(): MealPlanIngredientStatus[] {
    return this.response?.summary.ingredients || [];
  }

  get shortages(): MealPlanIngredientStatus[] {
    return this.response?.summary.shortages || [];
  }

  get isDraft(): boolean {
    return this.response?.batch.status === 'draft';
  }

  get isFamilyPlan(): boolean {
    return !!this.response?.batch.family_id;
  }

  get hasConflicts(): boolean {
    return !!this.response?.conflicts.length;
  }

  get personalConflicts() {
    return this.response?.personal_conflicts || [];
  }

  get hasPersonalConflicts(): boolean {
    return this.personalConflicts.length > 0;
  }

  personalConflictLabel(conflict: { diner_name: string; planned_date: string; meal_type: string }): string {
    return `${conflict.diner_name} already has a personal ${this.mealTypeLabel(conflict.meal_type)} plan on ${this.dateLabel(conflict.planned_date)}.`;
  }

  load(): void {
    if (!this.batchId) return;
    this.loading = true;
    this.message = '';
    this.api.mealPlanBatch(this.batchId).subscribe({
      next: response => {
        this.applyResponse(response);
        this.loading = false;
        this.loadSupportingOptions(response);
      },
      error: error => {
        this.loading = false;
        this.message = this.errorMessage(error, 'Could not load this meal-plan draft.');
      },
    });
  }

  openMealEditor(meal: MealPlan): void {
    if (!this.isDraft) return;
    this.rememberModalReturnFocus();
    this.editorMessage = '';
    this.editor = {
      id: meal.id,
      recipeId: meal.recipe_id,
      plannedDate: meal.planned_date.slice(0, 10),
      mealType: meal.meal_type as MealType,
      servings: meal.servings || 1,
      dinerIds: [...(meal.diner_profile_ids || [])],
    };
  }

  closeMealEditor(): void {
    this.editor = undefined;
    this.editorMessage = '';
  }
  onMealEditorDidDismiss(): void { this.closeMealEditor(); this.restoreModalFocus(); }

  isEditorDinerSelected(dinerId: number): boolean {
    return !!this.editor?.dinerIds.includes(dinerId);
  }

  setEditorDiner(dinerId: number, selected: boolean): void {
    if (!this.editor) return;
    this.editor.dinerIds = selected
      ? [...new Set([...this.editor.dinerIds, dinerId])]
      : this.editor.dinerIds.filter(id => id !== dinerId);
    if (selected) this.editor.servings = Math.max(this.editor.servings, this.editor.dinerIds.length);
  }

  saveMealChange(): void {
    if (!this.editor || !this.batchId) return;
    if (!this.editor.recipeId) {
      this.editorMessage = 'Choose a recipe.';
      return;
    }
    if (Number(this.editor.servings) < 1) {
      this.editorMessage = 'Servings must be at least 1.';
      return;
    }
    if (this.isFamilyPlan && !this.editor.dinerIds.length) {
      this.editorMessage = 'Choose at least one diner for this meal.';
      return;
    }

    this.saving = true;
    this.editorMessage = '';
    this.api.updateMealPlanBatchMeal(this.batchId, this.editor.id, {
      recipe_id: this.editor.recipeId,
      planned_date: this.editor.plannedDate,
      meal_type: this.editor.mealType,
      servings: Number(this.editor.servings),
      ...(this.isFamilyPlan ? { diner_profile_ids: this.editor.dinerIds } : {}),
    }).subscribe({
      next: response => {
        this.applyResponse(response);
        this.saving = false;
        this.closeMealEditor();
      },
      error: error => {
        this.saving = false;
        this.editorMessage = this.errorMessage(error, 'Could not update this meal.');
      },
    });
  }

  addShortagesToList(): void {
    if (!this.batchId || !this.shortages.length || this.addingShortages || this.shortagesAdded) return;
    this.addingShortages = true;
    this.message = '';
    this.api.addMealPlanBatchShortagesToShoppingList(this.batchId).subscribe({
      next: result => {
        this.addingShortages = false;
        this.shortagesAdded = true;
        this.message = result.message || 'Plan shortages were added to the shopping list.';
      },
      error: error => {
        this.addingShortages = false;
        this.message = this.errorMessage(error, 'Could not add shortages to the shopping list.');
      },
    });
  }

  openPurchasedItems(): void {
    if (!this.shortages.length) {
      this.message = 'There are no shortage items to add to the pantry.';
      return;
    }
    this.rememberModalReturnFocus();
    this.purchaseMessage = '';
    this.purchaseDrafts = this.shortages.map(item => ({
      selected: true,
      name: item.name,
      quantity: item.missing_quantity ?? null,
      unit: item.unit || '',
      storageType: 'unknown',
    }));
  }

  closePurchasedItems(): void {
    this.purchaseDrafts = undefined;
    this.purchaseMessage = '';
  }
  onPurchasedItemsDidDismiss(): void { this.closePurchasedItems(); this.restoreModalFocus(); }

  savePurchasedItems(): void {
    if (!this.batchId || !this.purchaseDrafts || this.addingPurchasedItems) return;
    const selected = this.purchaseDrafts.filter(item => item.selected);
    if (!selected.length) {
      this.purchaseMessage = 'Select at least one item to add to the pantry.';
      return;
    }
    if (selected.some(item => !Number(item.quantity) || Number(item.quantity) <= 0 || !item.unit.trim())) {
      this.purchaseMessage = 'Enter a positive quantity and a unit for every selected item.';
      return;
    }

    const items: PurchasedPlanIngredient[] = selected.map(item => ({
      name: item.name,
      quantity: Number(item.quantity),
      unit: item.unit.trim(),
      storage_type: item.storageType,
    }));
    this.addingPurchasedItems = true;
    this.purchaseMessage = '';
    this.api.addMealPlanBatchPurchasedItems(this.batchId, items).subscribe({
      next: result => {
        this.addingPurchasedItems = false;
        this.applyResponse(result.preview);
        this.pantryChanges.publishAddedItems(result.items, result.preview.batch.family_id);
        const count = result.items.length;
        this.closePurchasedItems();
        this.message = `Added ${count} purchased item${count === 1 ? '' : 's'} to the pantry. Ingredient availability has been checked again.`;
      },
      error: error => {
        this.addingPurchasedItems = false;
        this.purchaseMessage = this.errorMessage(error, 'Could not add the purchased items to the pantry.');
      },
    });
  }

  savePlan(): void {
    if (!this.batchId || !this.isDraft) return;
    this.saving = true;
    this.message = '';
    this.api.saveMealPlanBatch(this.batchId, {
      conflict_action: this.replaceConflicts ? 'replace_conflicting' : 'keep_existing',
    }).subscribe({
      next: result => {
        this.saving = false;
        this.applyResponse(result);
        const startDate = result.batch.start_date?.slice(0, 10) || new Date().toISOString().slice(0, 10);
        const navigation = this.router.navigate(['/tabs/meal-plan'], {
          replaceUrl: true,
          queryParams: { refresh: Date.now(), start_date: startDate, generated_range: `${result.batch.start_date} to ${result.batch.end_date}` },
        });
        void Promise.resolve(navigation).then(navigated => {
          if (navigated === false) this.router.navigateByUrl('/tabs/meal-plan', { replaceUrl: true });
        }).catch(() => this.router.navigateByUrl('/tabs/meal-plan', { replaceUrl: true }));
      },
      error: error => {
        this.saving = false;
        this.message = this.errorMessage(error, 'Could not save this meal plan.');
      },
    });
  }

  discardPlan(): void {
    if (!this.batchId || !this.isDraft) return;
    if (!window.confirm('Discard this draft? Its generated meals will not be saved.')) return;
    this.saving = true;
    this.api.discardMealPlanBatch(this.batchId).subscribe({
      next: () => {
        this.saving = false;
        this.router.navigateByUrl('/plan-setup', { replaceUrl: true });
      },
      error: error => {
        this.saving = false;
        this.message = this.errorMessage(error, 'Could not discard this draft.');
      },
    });
  }

  recipeName(meal: MealPlan): string {
    return meal.recipe?.name
      || this.recommendations.find(item => item.recipe.id === meal.recipe_id)?.recipe.name
      || 'Selected recipe';
  }

  dinerNames(meal: MealPlan): string {
    if (!this.isFamilyPlan) return 'Personal profile';
    const names = this.diners
      .filter(diner => (meal.diner_profile_ids || []).includes(diner.id))
      .map(diner => diner.name);
    return names.length ? names.join(', ') : 'No diners selected';
  }

  mealTypeLabel(type: string): string {
    return type ? `${type.charAt(0).toUpperCase()}${type.slice(1)}` : 'Meal';
  }

  dateLabel(date: string): string {
    const parsed = new Date(`${date.slice(0, 10)}T12:00:00`);
    return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
  }

  statusLabel(status: MealPlanIngredientStatus['status']): string {
    switch (status) {
      case 'ready': return 'In pantry';
      case 'low_stock': return 'Need more';
      case 'missing': return 'Missing';
      default: return 'Review quantity';
    }
  }

  statusColor(status: MealPlanIngredientStatus['status']): string {
    switch (status) {
      case 'ready': return 'success';
      case 'low_stock': return 'warning';
      case 'missing': return 'danger';
      default: return 'medium';
    }
  }

  ingredientDetail(item: MealPlanIngredientStatus): string {
    if (item.status === 'needs_review') return 'The recipe quantity needs a quick check before stock can be compared.';
    const unit = item.unit ? ` ${item.unit}` : '';
    const needed = item.required_quantity ?? 0;
    const inPantry = item.pantry_quantity ?? 0;
    const missing = item.missing_quantity ?? 0;
    if (item.status === 'ready') return `Need ${needed}${unit}; ${inPantry}${unit} is in the pantry.`;
    return `Need ${needed}${unit}; ${inPantry}${unit} is in the pantry; ${missing}${unit} still needed.`;
  }

  isRecommended(recipeId: number): boolean {
    return this.recommendations.some(item => item.recipe.id === recipeId);
  }

  editorRecipeName(): string {
    const meal = this.meals.find(item => item.id === this.editor?.id);
    return meal ? this.recipeName(meal) : 'Selected recipe';
  }

  private applyResponse(response: MealPlanBatchResponse): void {
    this.response = { ...response, conflicts: response.conflicts || [] };
  }

  private loadSupportingOptions(response: MealPlanBatchResponse): void {
    const familyId = response.batch.family_id || undefined;
    this.recommendations = [];
    this.diners = [];
    this.api.recommendations(familyId).subscribe({ next: result => this.recommendations = result.recommendations });
    if (familyId) {
      this.api.householdProfiles(familyId).subscribe({ next: result => this.diners = result.household_profiles });
    }
  }

  private rememberModalReturnFocus(): void { this.modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : undefined; }
  private restoreModalFocus(): void { const target = this.modalReturnFocus; this.modalReturnFocus = undefined; if (target?.isConnected) setTimeout(() => target.focus(), 0); }

  private errorMessage(error: { error?: { message?: string; errors?: Record<string, string[]> } }, fallback: string): string {
    const validationErrors = error?.error?.errors;
    const validationMessage = validationErrors
      ? Object.keys(validationErrors).map(key => validationErrors[key][0]).find(message => !!message)
      : undefined;
    return validationMessage || error?.error?.message || fallback;
  }
}

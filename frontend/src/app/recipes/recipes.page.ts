import { Component, OnDestroy } from '@angular/core';
import { Router } from '@angular/router';
import { ApiService, Family, PackageItem, Recommendation, RecipeIngredient } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';
import { AlertController } from '@ionic/angular';

@Component({ selector: 'app-recipes', templateUrl: 'recipes.page.html', styleUrls: ['recipes.page.scss'], standalone: false })
export class RecipesPage implements OnDestroy {
  recipes: Recommendation[] = [];
  loading = false;
  loadingMore = false;
  searchError = '';
  message = '';
  favorites = new Set<number>();
  householdName = '';
  families: Family[] = [];
  cookingScope: 'personal' | number = 'personal';
  searchTerm = '';
  mealType = '';
  difficulty = '';
  maxTime?: number;
  currentPage = 1;
  lastPage = 1;
  confirmingIngredient?: RecipeIngredient;
  confirmingPackage?: PackageItem;
  packageAmount?: number;
  packageUnit = 'g';
  savingConversion = false;
  startingRecipeId?: number;
  private activeFamilyId?: number;
  private searchTimer?: ReturnType<typeof setTimeout>;

  constructor(private api: ApiService, private householdContext: HouseholdContextService, private auth: AuthService, private router: Router, private alerts: AlertController) {}
  ionViewWillEnter(): void { this.loadRecipeDiscovery(); }
  ngOnDestroy(): void { if (this.searchTimer) clearTimeout(this.searchTimer); }

  get cookingScopeDescription(): string {
    return this.activeFamilyId ? `${this.householdName}'s shared pantry and household dietary safety rules are used.` : 'Only your personal pantry and your own dietary preferences are used.';
  }
  get hasMore(): boolean { return this.currentPage < this.lastPage; }

  onSearchChanged(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.loadRecipes(), 300);
  }
  applyFilters(): void { this.loadRecipes(); }

  loadRecipeDiscovery(): void {
    const userId = this.auth.user?.id;
    if (!this.api.hasToken || !userId) { this.message = 'Connect your account on Home to browse recipes.'; return; }
    this.loading = true;
    this.searchError = '';
    this.householdContext.refresh(userId).subscribe({
      next: context => {
        this.families = context.families;
        const selected = this.cookingScope === 'personal' ? undefined : context.families.find(family => family.id === Number(this.cookingScope));
        this.activeFamilyId = selected?.id;
        this.householdName = selected?.name || '';
        this.loadFavorites();
        this.loadRecipes();
      },
      error: () => { this.searchError = 'Could not load your household context. Check your connection and try again.'; this.loading = false; },
    });
  }

  changeCookingScope(): void {
    const family = this.cookingScope === 'personal' ? undefined : this.families.find(item => item.id === Number(this.cookingScope));
    this.activeFamilyId = family?.id;
    this.householdName = family?.name || '';
    this.loadRecipes();
  }

  loadMore(): void { if (this.hasMore && !this.loadingMore) this.loadRecipes(this.currentPage + 1, true); }
  showRecipeDetails(recipe: Recommendation): void { this.router.navigate(['/recipes', recipe.recipe.id], { queryParams: this.activeFamilyId ? { family_id: this.activeFamilyId } : {} }); }
  addMissingIngredientsToShoppingList(recipe: Recommendation): void { this.api.addMissingToList(recipe.recipe.id, this.activeFamilyId).subscribe({ next: () => this.message = `${recipe.recipe.name}: missing ingredients added to your shopping list.`, error: () => this.message = 'Could not update the shopping list.' }); }
  async cookNow(recipe: Recommendation): Promise<void> {
    if (this.startingRecipeId) return;
    const servings = await this.chooseServings(recipe);
    if (!servings) return;
    this.startingRecipeId = recipe.recipe.id;
    this.api.createMealPlan({ recipe_id: recipe.recipe.id, family_id: this.activeFamilyId, planned_date: new Date().toISOString().slice(0, 10), meal_type: recipe.recipe.meal_type || 'dinner', servings }).subscribe({
      next: plan => this.router.navigate(['/cooking', plan.id]),
      error: error => { this.startingRecipeId = undefined; this.message = error?.error?.message || 'Could not start cooking. Please try again.'; },
    });
  }
  private async chooseServings(recipe: Recommendation): Promise<number | undefined> {
    let servings: number | undefined;
    const alert = await this.alerts.create({ header: `Cook ${recipe.recipe.name}?`, message: 'One serving is roughly one person.', inputs: [{ name: 'servings', type: 'number', value: String(recipe.recipe.servings || 1), min: 1, max: 100, label: 'People to cook for' }], buttons: [{ text: 'Cancel', role: 'cancel' }, { text: 'Start cooking', role: 'confirm', handler: values => { servings = Math.max(1, Math.min(100, Number(values.servings) || 1)); } }] });
    await alert.present(); await alert.onDidDismiss(); return servings;
  }
  toggleFavorite(recipe: Recommendation): void {
    const id = recipe.recipe.id;
    const isFavorite = this.favorites.has(id);
    (isFavorite ? this.api.unfavoriteRecipe(id) : this.api.favoriteRecipe(id)).subscribe({
      next: () => isFavorite ? this.favorites.delete(id) : this.favorites.add(id),
      error: () => this.message = 'Could not update favorites.',
    });
  }
  recipeImage(recipe: Recommendation): string { return recipe.recipe.image_url || recipe.recipe.image || 'assets/shapes.svg'; }
  useFallbackImage(event: Event): void { (event.target as HTMLImageElement).src = 'assets/shapes.svg'; }
  openPackageConfirmation(ingredient: RecipeIngredient): void { const item = ingredient.package_items?.[0]; if (!item) return; this.confirmingIngredient = ingredient; this.confirmingPackage = item; this.packageAmount = undefined; this.packageUnit = ingredient.unit || 'g'; }
  closePackageConfirmation(): void { this.confirmingIngredient = undefined; this.confirmingPackage = undefined; }
  savePackageConversion(): void {
    if (!this.confirmingPackage || !this.packageAmount || this.packageAmount <= 0 || this.savingConversion) return;
    this.savingConversion = true;
    this.api.confirmPackageConversion(this.confirmingPackage.id, this.packageAmount, this.packageUnit).subscribe({
      next: result => { this.savingConversion = false; this.message = result.message; this.closePackageConfirmation(); this.loadRecipes(); },
      error: () => { this.savingConversion = false; this.message = 'Could not save that package conversion.'; },
    });
  }

  private loadRecipes(page = 1, append = false): void {
    if (!append) { this.loading = true; this.searchError = ''; }
    else this.loadingMore = true;
    this.api.searchRecipes(this.searchTerm.trim(), this.activeFamilyId, { mealType: this.mealType, difficulty: this.difficulty, maxTime: this.maxTime, page }).subscribe({
      next: response => {
        this.recipes = append ? [...this.recipes, ...response.data] : response.data;
        this.currentPage = response.current_page;
        this.lastPage = response.last_page;
        this.loading = false;
        this.loadingMore = false;
      },
      error: () => { this.searchError = 'Could not load recipes right now. Check your connection and try again.'; this.loading = false; this.loadingMore = false; },
    });
  }
  private loadFavorites(): void { this.api.favorites().subscribe({ next: recipes => this.favorites = new Set(recipes.map(recipe => recipe.id)), error: () => this.favorites = new Set() }); }
}

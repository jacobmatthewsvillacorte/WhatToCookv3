import { Component } from '@angular/core';
import { AlertController } from '@ionic/angular';
import { ApiService, Family, PantryItem, Recommendation } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';
import { Router } from '@angular/router';
import { forkJoin, of } from 'rxjs';
import { catchError, map, switchMap } from 'rxjs/operators';

@Component({ selector: 'app-dashboard', templateUrl: 'dashboard.page.html', styleUrls: ['dashboard.page.scss'], standalone: false })
export class DashboardPage {
  personalItems: PantryItem[] = [];
  householdItems: PantryItem[] = [];
  families: Family[] = [];
  activeFamily?: Family;
  recommendations: Recommendation[] = [];
  householdScope: 'personal' | number = 'personal';
  loading = false;
  error = '';
  notice = '';

  constructor(private api: ApiService, private householdContext: HouseholdContextService, private router: Router, private alerts: AlertController, public auth: AuthService) {}

  ionViewWillEnter(): void { this.loadDashboardData(); }

  get greeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
  }

  get selectedPantryLabel(): string {
    return this.activeFamily ? `${this.activeFamily.name} shared pantry` : 'My personal pantry';
  }

  sharedItems(familyId: number): PantryItem[] { return this.householdItems.filter(item => item.family_id === familyId); }
  overviewItems(): PantryItem[] { return [...this.personalItems, ...this.householdItems]; }
  pantryOwner(item: PantryItem): string { return item.family_id ? this.families.find(family => family.id === item.family_id)?.name || 'Shared pantry' : 'Personal'; }

  loadDashboardData(): void {
    const userId = this.auth.user?.id;
    if (!this.api.hasToken || !userId) return;
    this.loading = true;
    this.error = '';
    this.notice = history.state?.message || '';
    this.householdContext.refresh(userId).pipe(
      switchMap(context => {
        this.families = context.families;
        this.activeFamily = context.activeFamily || undefined;
        this.householdScope = this.activeFamily?.id || 'personal';
        return forkJoin({
          // The endpoint is authorization-aware and keeps personal and shared
          // The dashboard deliberately receives all pantries the user can see.
          // A selected household changes recipe matching only, never visibility.
          inventory: this.api.pantry().pipe(
            map(items => ({ items, failed: false })),
            catchError(() => of({ items: [] as PantryItem[], failed: true })),
          ),
          recommendations: this.api.recommendations(this.activeFamily?.id).pipe(
            map(response => ({ response, failed: false })),
            catchError(() => of({ response: { recommendations: [] as Recommendation[] }, failed: true })),
          ),
        });
      }),
    ).subscribe({
      next: ({ inventory, recommendations }) => {
        this.personalItems = inventory.items.filter(item => !item.family_id);
        this.householdItems = inventory.items.filter(item => !!item.family_id);
        this.recommendations = recommendations.response.recommendations.slice(0, 3);
        if (inventory.failed || recommendations.failed) this.notice = 'Some kitchen details could not be refreshed. Pull down or tap refresh to try again.';
        this.loading = false;
      },
      error: () => {
        this.personalItems = [];
        this.householdItems = [];
        this.recommendations = [];
        this.error = 'Unable to load your household. Please refresh and try again.';
        this.loading = false;
      },
    });
  }

  changeHousehold(): void {
    const family = this.householdScope === 'personal'
      ? null
      : this.families.find(item => item.id === Number(this.householdScope)) || null;
    this.householdContext.select(this.auth.user?.id, family);
    this.activeFamily = family || undefined;
    this.recommendations = [];
    this.loadDashboardData();
  }

  attention(items: PantryItem[]): PantryItem[] {
    const limit = Date.now() + 3 * 24 * 60 * 60 * 1000;
    return items.filter(item => item.freshness_status === 'review' || (item.expiry_date && new Date(item.expiry_date).getTime() <= limit)).slice(0, 4);
  }

  attentionLabel(item: PantryItem): string {
    if (item.freshness_status === 'review' && !item.expiry_date) return 'Check freshness';
    if (!item.expiry_date) return 'Check freshness';
    const days = Math.ceil((new Date(item.expiry_date).getTime() - Date.now()) / (24 * 60 * 60 * 1000));
    if (days < 0) return 'Past due';
    if (days === 0) return 'Use today';
    if (days === 1) return 'Use tomorrow';
    return `Use in ${days} days`;
  }

  recipeImage(item: Recommendation): string { return item.recipe.image_url || item.recipe.image || 'assets/shapes.svg'; }
  useFallbackImage(event: Event): void { (event.target as HTMLImageElement).src = 'assets/shapes.svg'; }
  async cookRecommendation(event: Event, item: Recommendation): Promise<void> {
    event.preventDefault();
    event.stopPropagation();
    if (!item.recipe.id) return;
    const servings = await this.chooseServings(item);
    if (!servings) return;
    this.api.createMealPlan({
      recipe_id: item.recipe.id,
      family_id: this.activeFamily?.id,
      planned_date: new Date().toISOString().slice(0, 10),
      meal_type: item.recipe.meal_type || 'dinner',
      servings,
    }).subscribe({ next: plan => this.router.navigate(['/cooking', plan.id]), error: error => this.notice = error?.error?.message || 'Could not start cooking. Please try again.' });
  }

  private async chooseServings(item: Recommendation): Promise<number | undefined> {
    let servings: number | undefined;
    const alert = await this.alerts.create({
      header: `Cook ${item.recipe.name}?`, message: 'One serving is roughly one person.',
      inputs: [{ name: 'servings', type: 'number', value: String(item.recipe.servings || 1), min: 1, max: 100, label: 'People to cook for' }],
      buttons: [{ text: 'Cancel', role: 'cancel' }, { text: 'Start cooking', role: 'confirm', handler: values => { servings = Math.max(1, Math.min(100, Number(values.servings) || 1)); } }],
    });
    await alert.present(); await alert.onDidDismiss(); return servings;
  }
}

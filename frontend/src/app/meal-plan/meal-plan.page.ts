import { Component, OnDestroy } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ToastController } from '@ionic/angular';
import { forkJoin, of, Subscription } from 'rxjs';
import { catchError, finalize, switchMap } from 'rxjs/operators';
import { ApiService, CarbChoice, CARB_CHOICES, Family, HouseholdProfile, MealPlan, Recommendation, SecondaryDish } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';

type MealType = 'Breakfast' | 'Lunch' | 'Dinner';
interface PlannerDraft { date: string; mealType: MealType; recipeId: number | null; servings: number; dinerIds: number[]; planId?: number; carbChoice: CarbChoice; includeCarb: boolean; secondaryDish?: SecondaryDish | null; }

@Component({ selector: 'app-meal-plan', templateUrl: './meal-plan.page.html', styleUrls: ['./meal-plan.page.scss'], standalone: false })
export class MealPlanPage implements OnDestroy {
  readonly mealTypes: MealType[] = ['Breakfast', 'Lunch', 'Dinner'];
  readonly carbChoices = CARB_CHOICES;
  days: Date[] = [];
  selectedDay?: Date;
  showAllPlannedDays = false;
  household?: Family;
  families: Family[] = [];
  contextId: number | 'personal' = 'personal';
  diners: HouseholdProfile[] = [];
  recipes: Recommendation[] = [];
  plans: MealPlan[] = [];
  readonly readinessByPlan = new Map<number, 'ready' | 'attention' | 'unavailable'>();
  draft?: PlannerDraft;
  loading = false;
  saving = false;
  deleteCandidate?: MealPlan;
  private deleteClosesEditor = false;
  readonly deleteAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Delete meal', role: 'destructive', handler: () => this.confirmRemove() },
  ];
  message = '';
  loadError = '';
  private periodStart = this.startOfWeek(new Date());
  private preferredDate?: string;
  private generatedConfirmation?: string;
  private modalReturnFocus?: HTMLElement;
  private loadSubscription?: Subscription;
  private readinessSubscription?: Subscription;
  private activeLoadKey?: string;
  private loadAttempt = 0;
  private readinessAttempt = 0;
  private viewActive = false;
  private routeSubscription?: Subscription;

  constructor(private api: ApiService, private householdContext: HouseholdContextService, private auth: AuthService, private router: Router, private route: ActivatedRoute, private toastController: ToastController) {
    // A saved generated draft returns here with this value. Query parameters
    // update even when Ionic keeps the tab page alive in its navigation stack.
    this.routeSubscription = this.route.queryParamMap.subscribe(params => {
      const startDate = params.get('start_date');
      if (startDate) {
        const parsed = this.parseDate(startDate);
        if (parsed) this.periodStart = this.startOfWeek(parsed);
        this.preferredDate = startDate;
      }
      const generatedRange = params.get('generated_range');
      if (generatedRange) this.generatedConfirmation = `Plan ready for ${this.formatGeneratedRange(generatedRange)}. Your first planned day is open below.`;
      if (this.viewActive && (params.has('refresh') || startDate)) this.load();
    });
  }
  ionViewWillEnter(): void { this.viewActive = true; this.load(); }
  ionViewWillLeave(): void { this.viewActive = false; this.cancelRequests(); }
  ngOnDestroy(): void { this.routeSubscription?.unsubscribe(); this.cancelRequests(); }

  load(force = false): void {
    const userId = this.auth.user?.id;
    if (!userId || !this.api.hasToken) return;
    const startDate = this.dateOnly(this.periodStart);
    const endDate = this.dateOnly(this.addDays(this.periodStart, 6));
    const loadKey = `${userId}:${startDate}:${endDate}`;

    // Query params and Ionic lifecycle hooks can arrive back-to-back. They
    // describe the same visible week, so keep the request already in flight
    // instead of starting another complete set of planner requests.
    if (!force && this.activeLoadKey === loadKey && this.loadSubscription && !this.loadSubscription.closed) return;

    this.cancelRequests();
    const attempt = ++this.loadAttempt;
    this.activeLoadKey = loadKey;
    this.loading = true;
    this.message = '';
    this.loadError = '';
    this.loadSubscription = this.householdContext.refresh(userId).pipe(
      switchMap(context => {
        this.household = context.activeFamily || undefined;
        this.families = context.families;
        this.contextId = this.household?.id || 'personal';
        return this.household
          ? forkJoin({ profiles: this.api.householdProfiles(this.household.id), recommendations: this.api.recommendations(this.household.id), plans: this.api.mealPlans(this.household.id, startDate, endDate) })
          : forkJoin({ profiles: of({ household_profiles: [] as HouseholdProfile[] }), recommendations: this.api.recommendations(), plans: this.api.mealPlans(undefined, startDate, endDate) });
      }),
      catchError(() => { this.loadError = 'Could not load the meal planner. Check your connection and try again.'; return of({ profiles: { household_profiles: [] as HouseholdProfile[] }, recommendations: { recommendations: [] as Recommendation[] }, plans: [] as MealPlan[] }); }),
      finalize(() => {
        if (this.loadAttempt !== attempt) return;
        this.loading = false;
        this.activeLoadKey = undefined;
      }),
    ).subscribe(result => {
      if (this.loadAttempt !== attempt) return;
      this.diners = result.profiles.household_profiles;
      this.recipes = result.recommendations.recommendations;
      this.plans = this.household ? result.plans.filter(plan => plan.family_id === this.household?.id) : result.plans.filter(plan => !plan.family_id);
      this.setDays();
      this.selectUsefulDay();
      if (this.generatedConfirmation) {
        this.showGeneratedPlanToast(this.generatedConfirmation);
        this.generatedConfirmation = undefined;
      }
      this.loadReadiness();
    });
  }

  previousPeriod(): void { this.periodStart = this.addDays(this.periodStart, -7); this.load(); }
  nextPeriod(): void { this.periodStart = this.addDays(this.periodStart, 7); this.load(); }
  currentPeriod(): void { this.periodStart = this.startOfWeek(new Date()); this.load(); }
  selectDay(day: Date): void { this.selectedDay = day; this.showAllPlannedDays = false; this.loadReadiness(); }
  planFor(day: Date, mealType: MealType): MealPlan | undefined { return this.plans.find(plan => this.dateOnly(plan.planned_date) === this.dateOnly(day) && plan.meal_type.toLowerCase() === mealType.toLowerCase()); }
  plansForDay(day: Date): MealPlan[] { return this.mealTypes.map(type => this.planFor(day, type)).filter((plan): plan is MealPlan => !!plan); }
  plannedCount(day: Date): number { return this.plansForDay(day).length; }
  get selectedDayPlans(): MealPlan[] { return this.selectedDay ? this.plansForDay(this.selectedDay) : []; }
  get allPlannedDays(): Date[] { return this.days.filter(day => this.plannedCount(day)); }
  get plannedMealCount(): number { return this.days.reduce((count, day) => count + this.plannedCount(day), 0); }
  openSlot(day: Date, mealType: MealType, plan?: MealPlan): void {
    if (plan && this.isCooked(plan)) {
      this.openMealDetails(plan);
      return;
    }
    this.rememberModalReturnFocus();
    this.draft = { date: this.dateOnly(day), mealType, planId: plan?.id, recipeId: plan?.recipe_id || null, servings: plan?.servings || plan?.recipe?.servings || Math.max(this.diners.length, 1), dinerIds: plan?.diner_profile_ids || this.diners.map(diner => diner.id), carbChoice: plan?.carb_choice || 'white_rice', includeCarb: plan?.include_carb ?? true, secondaryDish: plan?.secondary_dish };
  }
  closeEditor(): void { this.draft = undefined; }
  onEditorDidDismiss(): void { this.closeEditor(); this.restoreModalFocus(); }
  toggleDiner(id: number): void { if (!this.draft) return; this.draft.dinerIds = this.draft.dinerIds.includes(id) ? this.draft.dinerIds.filter(dinerId => dinerId !== id) : [...this.draft.dinerIds, id]; if (this.draft.dinerIds.length) this.draft.servings = Math.max(this.draft.servings, this.draft.dinerIds.length); }
  recipeName(plan: MealPlan): string { return plan.recipe?.name || this.recipes.find(item => item.recipe.id === plan.recipe_id)?.recipe.name || 'Selected recipe'; }
  carbLabel(choice?: CarbChoice): string { return this.carbChoices.find(option => option.value === choice)?.label || 'White rice'; }
  servingsFor(plan: MealPlan): number { return plan.servings || plan.recipe?.servings || 1; }
  dinersFor(plan: MealPlan): string { const ids = plan.diner_profile_ids || []; const names = this.diners.filter(diner => ids.includes(diner.id)).map(diner => diner.name); return names.length ? names.join(', ') : this.household ? 'No diners selected' : 'Personal profile'; }
  get plannerName(): string { return this.household?.name || 'your personal profile'; }
  changeContext(): void {
    const userId = this.auth.user?.id;
    if (!userId) return;
    this.householdContext.select(userId, this.contextId === 'personal' ? null : this.families.find(family => family.id === this.contextId) || null);
    this.load(true);
  }
  openGeneration(): void { this.router.navigate(['/plan-setup']); }
  openMealDetails(plan: MealPlan): void { this.router.navigate(['/meal-details', plan.id]); }
  openShoppingList(): void { this.router.navigate(['/shopping-list']); }
  readiness(plan: MealPlan): 'ready' | 'attention' | 'unavailable' | 'cooked' { return this.isCooked(plan) ? 'cooked' : this.readinessByPlan.get(plan.id) || 'unavailable'; }
  readinessLabel(plan: MealPlan): string { return ({ ready: 'Pantry ready', attention: 'Needs groceries', cooked: 'Cooked', unavailable: 'Check pantry' })[this.readiness(plan)]; }

  save(): void {
    if (!this.draft?.recipeId) { this.message = 'Choose a recipe before saving this meal.'; return; }
    if (this.household && !this.draft.dinerIds.length) { this.message = 'Select at least one diner.'; return; }
    this.saving = true;
    const payload = { recipe_id: this.draft.recipeId, ...(this.household ? { family_id: this.household.id, diner_profile_ids: this.draft.dinerIds } : {}), planned_date: this.draft.date, meal_type: this.draft.mealType.toLowerCase(), servings: this.draft.servings, carb_choice: this.draft.carbChoice, include_carb: this.draft.includeCarb };
    const request = this.draft.planId ? this.api.updateMealPlan(this.draft.planId, payload) : this.api.createMealPlan(payload);
    request.subscribe({ next: () => { this.message = 'Meal scheduled.'; this.draft = undefined; this.saving = false; this.load(true); }, error: error => { this.message = error?.error?.message || 'Could not save this meal.'; this.saving = false; } });
  }
  requestRemove(plan: MealPlan): void { if (!this.isCooked(plan)) { this.deleteClosesEditor = false; this.deleteCandidate = plan; } }
  remove(): void { if (!this.draft?.planId) return; this.deleteClosesEditor = true; this.deleteCandidate = this.plans.find(plan => plan.id === this.draft?.planId); }
  onDeleteAlertWillPresent(): void { this.rememberModalReturnFocus(); }
  onDeleteAlertDidDismiss(): void { this.deleteCandidate = undefined; this.restoreModalFocus(); }
  confirmRemove(): void { if (this.deleteCandidate) this.deletePlan(this.deleteCandidate.id, this.deleteClosesEditor); }
  private deletePlan(planId: number, closeEditor: boolean): void { this.deleteCandidate = undefined; this.api.deleteMealPlan(planId).subscribe({ next: () => { if (closeEditor) this.draft = undefined; this.message = 'Meal removed.'; this.load(true); }, error: error => this.message = error?.error?.message || 'Could not remove this meal.' }); }
  private rememberModalReturnFocus(): void { this.modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : undefined; }
  private restoreModalFocus(): void { const target = this.modalReturnFocus; this.modalReturnFocus = undefined; if (target?.isConnected) setTimeout(() => target.focus(), 0); }
  isCooked(plan: MealPlan): boolean { return !!plan.completed_at; }
  dateLabel(day: Date): string { return day.toLocaleDateString(undefined, { weekday: 'short' }); }
  dayNumber(day: Date): string { return day.toLocaleDateString(undefined, { day: 'numeric' }); }
  selectedDayLabel(day: Date): string { return day.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }); }
  periodLabel(): string { return `${this.days[0]?.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) || ''} – ${this.days[this.days.length - 1]?.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) || ''}`; }
  private startOfWeek(date: Date): Date { const result = new Date(date); const offset = (result.getDay() + 6) % 7; result.setDate(result.getDate() - offset); result.setHours(0, 0, 0, 0); return result; }
  private setDays(): void { this.days = Array.from({ length: 7 }, (_, index) => this.addDays(this.periodStart, index)); }
  private selectUsefulDay(): void {
    const today = this.dateOnly(new Date());
    const preferred = this.preferredDate && this.days.find(day => this.dateOnly(day) === this.preferredDate && this.plannedCount(day));
    const todayWithMeal = this.days.find(day => this.dateOnly(day) === today && this.plannedCount(day));
    const upcoming = this.days.find(day => this.dateOnly(day) >= today && this.plannedCount(day));
    this.selectedDay = preferred || todayWithMeal || upcoming || this.days[0];
    this.preferredDate = undefined;
  }
  private loadReadiness(): void {
    this.readinessSubscription?.unsubscribe();
    const attempt = ++this.readinessAttempt;
    this.readinessByPlan.clear();
    // The weekly agenda exposes at most breakfast, lunch, and dinner for the
    // selected day. Checking only those three avoids a request for every
    // historical meal plan while still keeping the visible statuses current.
    const activePlans = this.selectedDayPlans.filter(plan => !this.isCooked(plan)).slice(0, this.mealTypes.length);
    if (!activePlans.length) return;
    this.readinessSubscription = forkJoin(activePlans.map(plan => this.api.mealPlanPreflight(plan.id).pipe(catchError(() => of(undefined))))).subscribe(checks => {
      if (this.readinessAttempt !== attempt) return;
      checks.forEach((check, index) => this.readinessByPlan.set(activePlans[index].id, check ? (check.can_cook_from_pantry ? 'ready' : 'attention') : 'unavailable'));
    });
  }
  private cancelRequests(): void {
    this.loadAttempt++;
    this.readinessAttempt++;
    this.loadSubscription?.unsubscribe();
    this.readinessSubscription?.unsubscribe();
    this.loadSubscription = undefined;
    this.readinessSubscription = undefined;
    this.activeLoadKey = undefined;
    this.loading = false;
  }
  private addDays(date: Date, days: number): Date { const result = new Date(date); result.setDate(result.getDate() + days); return result; }
  private async showGeneratedPlanToast(message: string): Promise<void> {
    const toast = await this.toastController.create({
      message,
      duration: 3200,
      position: 'top',
      color: 'success',
      icon: 'checkmark-circle-outline',
    });
    await toast.present();
  }
  private parseDate(value: string): Date | undefined { const parsed = new Date(`${value.slice(0, 10)}T12:00:00`); return Number.isNaN(parsed.getTime()) ? undefined : parsed; }
  private formatGeneratedRange(value: string): string {
    const [startValue, endValue] = value.split(' to ');
    const start = startValue && this.parseDate(startValue);
    const end = endValue && this.parseDate(endValue);
    if (!start || !end) return value;
    const startLabel = start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    const endLabel = end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    return `${startLabel}–${endLabel}`;
  }
  dateOnly(value: Date | string): string { return typeof value === 'string' ? value.slice(0, 10) : `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`; }
}

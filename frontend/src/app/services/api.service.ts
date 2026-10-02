import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Capacitor } from '@capacitor/core';
import { environment } from '../../environments/environment';

export interface PantryItem { id: number; name: string; quantity?: string; quantity_value?: number | string; unit?: string; purchase_date?: string; expiry_date?: string; purchase_source?: string; storage_type?: string; freshness_condition?: string; freshness_status?: 'fresh' | 'review' | 'spoiled' | 'used' | 'discarded'; freshness_review_date?: string; freshness_confidence?: 'high' | 'medium' | 'low'; is_expiry_estimated?: boolean; family_id?: number | null; last_used_quantity?: number | string | null; last_usage_reason?: string | null; }
export interface PackageItem { id: number; name: string; quantity?: number | string; unit?: string; }
export interface Family { id: number; name: string; owner_id: number; join_code?: string; owner?: { id: number; name: string }; members?: Array<{ id: number; user_id: number; role: string; user?: { id: number; name: string; email: string } }>; }
export interface RecipeIngredient { name: string; quantity?: string; unit?: string; substitutes: string[]; needs_review?: boolean; pantry_units?: string[]; package_items?: PackageItem[]; }
export interface RecipeSummary { id: number; name: string; description?: string; servings?: number; prep_time?: number; cook_time?: number; meal_type?: string; difficulty?: string; region?: string; image?: string | null; image_url?: string | null; }
export interface Recommendation { recipe: RecipeSummary; match_percentage: number; available_ingredients: RecipeIngredient[]; needs_review_ingredients?: RecipeIngredient[]; missing_ingredients: RecipeIngredient[]; }
export interface RecipeSearchResponse { data: Recommendation[]; current_page: number; last_page: number; total: number; }
export interface RecipeDetail extends RecipeSummary { instructions?: string; cooking_tips?: string; ingredients: RecipeIngredient[]; }
export interface RecipeNutrition { servings: number; totals: Record<string, number>; per_serving: Record<string, number>; unmatched_ingredients: Array<{ ingredient_id: number; name: string; reason: string }>; is_complete: boolean; is_nutrition_complete?: boolean; data_status?: 'complete' | 'partial' | 'incomplete'; unknown_nutrients?: Record<string, string[]>; disclaimer?: string; }
export interface Profile { health_conditions: string[]; allergies: string[]; dietary_restrictions: string[]; likes: string[]; dislikes: string[]; visible_to_family: Record<string, boolean>; }
export interface HouseholdProfile { id: number; family_id: number; user_id?: number | null; name: string; relation?: string | null; sex?: string | null; birth_date?: string | null; age_band?: '0-5_months' | '6-11_months' | '12-23_months' | '2-5_years' | '6_plus_years' | null; height_cm?: number | string | null; weight_kg?: number | string | null; activity_level?: string | null; goal?: string | null; health_conditions?: string[]; allergies?: string[]; dietary_restrictions?: string[]; likes?: string[]; dislikes?: string[]; visible_to_family?: Record<string, boolean>; }
export interface FamilyInvitation { id: number; family_id: number; status: 'pending'; family: Family; }
export interface CatalogIngredient { id: number; canonical_name: string; aliases: string[]; category: string; default_units: string[]; }
export interface PantryInputCandidate { name: string; quantity?: string | null; unit?: string | null; purchase_source?: string; storage_type?: string; status?: 'accepted' | 'suggested' | 'rejected'; suggestion?: CatalogIngredient; ingredient?: CatalogIngredient; message?: string; }
export interface PantryInputResult { source: 'barcode' | 'voice' | 'receipt'; needs_review: boolean; message: string; candidates: PantryInputCandidate[]; accepted?: PantryInputCandidate[]; suggested?: PantryInputCandidate[]; rejected?: PantryInputCandidate[]; }
export interface ChildMealPlan { children: Array<{ profile_id: number; name: string; age_band: string; meal_choice: string; portion_multiplier: number; portion: string; adaptation_notes: string[]; guidance_note: string }>; serving_equivalents: number; medical_disclaimer: string; }
export interface MealPlan { id: number; recipe_id: number; family_id?: number | null; meal_plan_batch_id?: number | null; planned_date: string; meal_type: string; status?: 'draft' | 'scheduled' | 'completed'; servings: number; selection_reason?: string[] | null; serving_equivalents?: number; diner_profile_ids?: number[] | null; child_meal_plan?: ChildMealPlan | null; completed_at?: string | null; completion_method?: 'pantry_deducted' | 'without_pantry_deduction' | null; recipe?: RecipeDetail; }
export interface MealPlanPayload { recipe_id: number; family_id?: number | null; planned_date: string; meal_type: string; servings?: number; diner_profile_ids?: number[]; }
export interface MealPlanGenerationPayload { family_id?: number; start_date: string; end_date: string; meal_types: string[]; diner_profile_ids?: number[]; servings?: number; replace_existing?: boolean; }
export interface MealPlanBatch { id: number; user_id: number; family_id?: number | null; start_date: string; end_date: string; status: 'draft' | 'saved' | 'discarded'; generation_options?: { meal_types?: string[]; diner_profile_ids?: number[]; attendance_by_date?: Record<string, number[]>; servings?: number; }; saved_at?: string | null; discarded_at?: string | null; }
export interface MealPlanBatchGenerationPayload { family_id?: number | null; start_date: string; end_date: string; meal_types: Array<'breakfast' | 'lunch' | 'dinner'>; cooking_time_budget?: '15' | '30' | '45' | '60' | '90+'; time_preference?: 'strict' | 'flexible'; leftover_strategy?: 'avoid_leftovers' | 'reuse_ulam' | 'main_with_rice_side'; diner_profile_ids?: number[]; attendance_by_date?: Record<string, number[]>; child_meal_modes?: Record<number, 'family_meal_with_adaptation' | 'separate_child_meal' | 'exclude'>; servings?: number; }
export interface MealPlanBatchMealPayload { recipe_id?: number; planned_date?: string; meal_type?: 'breakfast' | 'lunch' | 'dinner'; servings?: number; diner_profile_ids?: number[]; }
export interface MealPlanIngredientStatus { name: string; unit?: string | null; required_quantity?: number | null; pantry_quantity?: number | null; missing_quantity?: number | null; status: 'ready' | 'low_stock' | 'missing' | 'needs_review'; substitutes?: string[]; }
export interface MealPlanBatchSummary { meal_count: number; ready_count: number; ingredients: MealPlanIngredientStatus[]; shortages: MealPlanIngredientStatus[]; needs_review: MealPlanIngredientStatus[]; }
export interface PersonalMealConflict { diner_profile_id: number; diner_name: string; planned_date: string; meal_type: 'breakfast' | 'lunch' | 'dinner'; }
export interface MealPlanBatchResponse { batch: MealPlanBatch; meal_plans: MealPlan[]; summary: MealPlanBatchSummary; conflicts: MealPlan[]; personal_conflicts?: PersonalMealConflict[]; message?: string; }
export interface PurchasedPlanIngredient { name: string; quantity: number; unit: string; purchase_date?: string; expiry_date?: string; storage_type?: 'room_temperature' | 'refrigerated' | 'frozen' | 'other' | 'unknown'; }
export interface MealPlanIngredientCheck { name: string; quantity?: string | null; required_quantity?: number | null; unit?: string | null; available: boolean; sufficient: boolean; pantry_quantity?: number | null; missing_quantity?: number | null; substitutes?: string[]; status: 'ready' | 'low_stock' | 'missing' | 'needs_review'; }
export interface MealPlanPreflight { meal_plan: MealPlan; recipe: RecipeDetail; diners: Array<Pick<HouseholdProfile, 'id' | 'name' | 'relation'>>; pantry_scope: 'personal' | 'family'; can_cook_from_pantry: boolean; can_mark_cooked_without_deduction: boolean; match_percentage: number; ingredients: MealPlanIngredientCheck[]; ingredients_by_status: Record<'ready' | 'low_stock' | 'missing' | 'needs_review', MealPlanIngredientCheck[]>; }
export interface ShoppingListItem { id: number; family_id?: number | null; ingredient_name: string; quantity?: string | null; unit?: string | null; purchase_quantity?: string | null; purchase_unit?: string | null; purchase_note?: string | null; is_purchased: boolean; }
export interface ConfirmedPurchase { confirmed: true; name?: string; quantity: string | number; unit: string; purchase_date?: string; expiry_date?: string | null; purchase_source?: string; storage_type?: string; }
export interface MealHistoryItem { id: number; family_id?: number | null; recipe_id: number; prepared_at: string; servings?: number | null; notes?: string | null; recipe?: RecipeDetail; }
export interface RecipeReview { id: number; rating: number; review?: string | null; user?: { id: number; name: string }; }

@Injectable({ providedIn: 'root' })
export class ApiService {
  constructor(private http: HttpClient) {}
  private get baseUrl(): string {
    return Capacitor.getPlatform() === 'android'
      ? environment.androidApiBaseUrl
      : environment.apiBaseUrl;
  }
  setToken(token: string): void { localStorage.setItem('whattocook_token', token.trim()); }
  get hasToken(): boolean { return !!localStorage.getItem('whattocook_token'); }
  private options() { return { headers: new HttpHeaders({ Authorization: `Bearer ${localStorage.getItem('whattocook_token') ?? ''}` }) }; }
  pantry(familyId?: number, personal = false): Observable<PantryItem[]> { const params: Record<string, string> = familyId ? { family_id: String(familyId) } : (personal ? { personal: '1' } : {}); return this.http.get<PantryItem[]>(`${this.baseUrl}/pantry`, { ...this.options(), params }); }
  addPantry(item: Omit<PantryItem, 'id'>): Observable<{ item: PantryItem }> { return this.http.post<{ item: PantryItem }>(`${this.baseUrl}/pantry`, item, this.options()); }
  updatePantry(itemId: number, item: Partial<PantryItem>): Observable<{ item: PantryItem }> { return this.http.put<{ item: PantryItem }>(`${this.baseUrl}/pantry/${itemId}`, item, this.options()); }
  confirmPackageConversion(itemId: number, amountPerPackage: number, amountUnit: string): Observable<{ message: string }> { return this.http.post<{ message: string }>(`${this.baseUrl}/pantry/${itemId}/package-conversion`, { amount_per_package: amountPerPackage, amount_unit: amountUnit }, this.options()); }
  deletePantry(itemId: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/pantry/${itemId}`, this.options()); }
  updateFreshness(itemId: number, action: 'still_fresh' | 'spoiled' | 'used' | 'discarded' | 'undo_used', usedQuantity?: number, usageReason?: string): Observable<{ item: PantryItem }> { return this.http.patch<{ item: PantryItem }>(`${this.baseUrl}/pantry/${itemId}/freshness`, { action, ...(usedQuantity ? { used_quantity: usedQuantity } : {}), ...(usageReason?.trim() ? { usage_reason: usageReason.trim() } : {}) }, this.options()); }
  barcodeInput(barcode: string): Observable<PantryInputResult> { return this.http.post<PantryInputResult>(`${this.baseUrl}/pantry-inputs/barcode`, { barcode }, this.options()); }
  voiceInput(transcript: string): Observable<PantryInputResult> { return this.http.post<PantryInputResult>(`${this.baseUrl}/pantry-inputs/voice`, { transcript }, this.options()); }
  receiptInput(receipt: File, recognizedText = ''): Observable<PantryInputResult> { const data = new FormData(); data.append('receipt', receipt); if (recognizedText.trim()) data.append('recognized_text', recognizedText); return this.http.post<PantryInputResult>(`${this.baseUrl}/pantry-inputs/receipt`, data, this.options()); }
  receiptTextInput(text: string): Observable<PantryInputResult> { return this.http.post<PantryInputResult>(`${this.baseUrl}/pantry-inputs/receipt-text`, { text }, this.options()); }
  resolveIngredient(name: string): Observable<PantryInputCandidate> { return this.http.post<PantryInputCandidate>(`${this.baseUrl}/ingredients/resolve`, { name }, this.options()); }
  searchIngredients(query: string): Observable<{ ingredients: CatalogIngredient[] }> { return this.http.get<{ ingredients: CatalogIngredient[] }>(`${this.baseUrl}/ingredients/search`, { ...this.options(), params: { q: query } }); }
  families(): Observable<Family[]> { return this.http.get<Family[]>(`${this.baseUrl}/families`, this.options()); }
  createFamily(name: string): Observable<Family> { return this.http.post<Family>(`${this.baseUrl}/families`, { name }, this.options()); }
  joinFamily(joinCode: string): Observable<{ family: Family; joined: boolean }> { return this.http.post<{ family: Family; joined: boolean }>(`${this.baseUrl}/families/join`, { join_code: joinCode }, this.options()); }
  addFamilyMember(familyId: number, email: string): Observable<unknown> { return this.http.post(`${this.baseUrl}/families/${familyId}/members`, { email, role: 'member' }, this.options()); }
  invitations(): Observable<{ invitations: FamilyInvitation[] }> { return this.http.get<{ invitations: FamilyInvitation[] }>(`${this.baseUrl}/family-invitations`, this.options()); }
  acceptInvitation(invitationId: number): Observable<{ family: Family }> { return this.http.post<{ family: Family }>(`${this.baseUrl}/family-invitations/${invitationId}/accept`, {}, this.options()); }
  removeFamilyMember(familyId: number, userId: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/families/${familyId}/members/${userId}`, this.options()); }
  profile(): Observable<Profile> { return this.http.get<Profile>(`${this.baseUrl}/profile`, this.options()); }
  updateProfile(profile: Partial<Profile>): Observable<Profile> { return this.http.put<Profile>(`${this.baseUrl}/profile`, profile, this.options()); }
  householdProfiles(familyId: number): Observable<{ household_profiles: HouseholdProfile[] }> { return this.http.get<{ household_profiles: HouseholdProfile[] }>(`${this.baseUrl}/families/${familyId}/household-profiles`, this.options()); }
  createHouseholdProfile(familyId: number, profile: Partial<HouseholdProfile>): Observable<{ household_profile: HouseholdProfile }> { return this.http.post<{ household_profile: HouseholdProfile }>(`${this.baseUrl}/families/${familyId}/household-profiles`, profile, this.options()); }
  updateHouseholdProfile(familyId: number, profileId: number, profile: Partial<HouseholdProfile>): Observable<{ household_profile: HouseholdProfile }> { return this.http.put<{ household_profile: HouseholdProfile }>(`${this.baseUrl}/families/${familyId}/household-profiles/${profileId}`, profile, this.options()); }
  deleteHouseholdProfile(familyId: number, profileId: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/families/${familyId}/household-profiles/${profileId}`, this.options()); }
  recommendations(familyId?: number): Observable<{ recommendations: Recommendation[] }> { return this.http.get<{ recommendations: Recommendation[] }>(`${this.baseUrl}/recipes/recommendations`, { ...this.options(), params: familyId ? { family_id: familyId } : undefined }); }
  searchRecipes(query = '', familyId?: number, filters: { mealType?: string; difficulty?: string; maxTime?: number; page?: number } = {}): Observable<RecipeSearchResponse> {
    const params: Record<string, string> = { q: query, include_match: '1', per_page: '12' };
    if (familyId) params['family_id'] = String(familyId);
    if (filters.mealType) params['meal_type'] = filters.mealType;
    if (filters.difficulty) params['difficulty'] = filters.difficulty;
    // Ionic can surface an unselected numeric option as the string
    // "undefined". Only send a valid positive integer to Laravel.
    const maxTime = Number(filters.maxTime);
    if (Number.isInteger(maxTime) && maxTime > 0) params['max_time'] = String(maxTime);
    if (filters.page) params['page'] = String(filters.page);
    return this.http.get<RecipeSearchResponse>(`${this.baseUrl}/recipes`, { ...this.options(), params });
  }
  recipe(recipeId: number): Observable<RecipeDetail> { return this.http.get<RecipeDetail>(`${this.baseUrl}/recipes/${recipeId}`, this.options()); }
  uploadRecipeImage(recipeId: number, image: File): Observable<RecipeDetail> { const data = new FormData(); data.append('image', image); return this.http.post<RecipeDetail>(`${this.baseUrl}/recipes/${recipeId}/image`, data, this.options()); }
  recipeNutrition(recipeId: number): Observable<RecipeNutrition> { return this.http.get<RecipeNutrition>(`${this.baseUrl}/recipes/${recipeId}/nutrition`, this.options()); }
  addMissingToList(recipeId: number, familyId?: number): Observable<unknown> { return this.http.post(`${this.baseUrl}/recipes/${recipeId}/shopping-list`, familyId ? { family_id: familyId } : {}, this.options()); }
  mealPlans(familyId?: number, startDate?: string, endDate?: string): Observable<MealPlan[]> { const params: Record<string, string> = {}; if (familyId) params['family_id'] = String(familyId); if (startDate) params['start_date'] = startDate; if (endDate) params['end_date'] = endDate; return this.http.get<MealPlan[]>(`${this.baseUrl}/meal-plans`, { ...this.options(), params }); }
  createMealPlan(plan: MealPlanPayload): Observable<MealPlan> { return this.http.post<MealPlan>(`${this.baseUrl}/meal-plans`, plan, this.options()); }
  updateMealPlan(planId: number, plan: Partial<MealPlanPayload>): Observable<MealPlan> { return this.http.put<MealPlan>(`${this.baseUrl}/meal-plans/${planId}`, plan, this.options()); }
  deleteMealPlan(planId: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/meal-plans/${planId}`, this.options()); }
  mealPlan(planId: number): Observable<MealPlan> { return this.http.get<MealPlan>(`${this.baseUrl}/meal-plans/${planId}`, this.options()); }
  mealPlanPreflight(planId: number): Observable<MealPlanPreflight> { return this.http.get<MealPlanPreflight>(`${this.baseUrl}/meal-plans/${planId}/preflight`, this.options()); }
  addMealPlanShortagesToShoppingList(planId: number): Observable<{ items: ShoppingListItem[]; message: string }> { return this.http.post<{ items: ShoppingListItem[]; message: string }>(`${this.baseUrl}/meal-plans/${planId}/shopping-list`, {}, this.options()); }
  cookMealPlan(planId: number, notes?: string): Observable<{ meal_plan: MealPlan; message: string }> { return this.http.post<{ meal_plan: MealPlan; message: string }>(`${this.baseUrl}/meal-plans/${planId}/complete`, { notes: notes?.trim() || null }, this.options()); }
  completeMealPlanWithoutDeduction(planId: number, notes?: string): Observable<{ meal_plan: MealPlan; message: string }> { return this.http.post<{ meal_plan: MealPlan; message: string }>(`${this.baseUrl}/meal-plans/${planId}/complete-without-deduction`, { notes: notes?.trim() || null }, this.options()); }
  generateMealPlans(payload: MealPlanGenerationPayload): Observable<{ meal_plans: MealPlan[]; start_date: string; end_date: string }> { return this.http.post<{ meal_plans: MealPlan[]; start_date: string; end_date: string }>(`${this.baseUrl}/meal-plans/generate`, payload, this.options()); }
  generateMealPlanBatch(payload: MealPlanBatchGenerationPayload): Observable<MealPlanBatchResponse> { return this.http.post<MealPlanBatchResponse>(`${this.baseUrl}/meal-plan-batches/generate`, payload, this.options()); }
  mealPlanBatch(batchId: number): Observable<MealPlanBatchResponse> { return this.http.get<MealPlanBatchResponse>(`${this.baseUrl}/meal-plan-batches/${batchId}`, this.options()); }
  updateMealPlanBatchMeal(batchId: number, mealPlanId: number, payload: MealPlanBatchMealPayload): Observable<MealPlanBatchResponse> { return this.http.patch<MealPlanBatchResponse>(`${this.baseUrl}/meal-plan-batches/${batchId}/meals/${mealPlanId}`, payload, this.options()); }
  addMealPlanBatchShortagesToShoppingList(batchId: number): Observable<{ items: ShoppingListItem[]; message: string }> { return this.http.post<{ items: ShoppingListItem[]; message: string }>(`${this.baseUrl}/meal-plan-batches/${batchId}/shopping-list`, {}, this.options()); }
  addMealPlanBatchPurchasedItems(batchId: number, items: PurchasedPlanIngredient[]): Observable<{ items: PantryItem[]; preview: MealPlanBatchResponse }> { return this.http.post<{ items: PantryItem[]; preview: MealPlanBatchResponse }>(`${this.baseUrl}/meal-plan-batches/${batchId}/purchased-items`, { items }, this.options()); }
  saveMealPlanBatch(batchId: number, options: { conflict_action?: 'keep_existing' | 'replace_conflicting'; add_shortages_to_shopping_list?: boolean } = {}): Observable<MealPlanBatchResponse> { return this.http.post<MealPlanBatchResponse>(`${this.baseUrl}/meal-plan-batches/${batchId}/save`, options, this.options()); }
  discardMealPlanBatch(batchId: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/meal-plan-batches/${batchId}`, this.options()); }
  shoppingList(): Observable<ShoppingListItem[]> { return this.http.get<ShoppingListItem[]>(`${this.baseUrl}/shopping-list`, this.options()); }
  addShoppingItem(item: Omit<ShoppingListItem, 'id'>): Observable<ShoppingListItem> { return this.http.post<ShoppingListItem>(`${this.baseUrl}/shopping-list`, item, this.options()); }
  updateShoppingItem(id: number, item: Partial<ShoppingListItem>): Observable<ShoppingListItem> { return this.http.put<ShoppingListItem>(`${this.baseUrl}/shopping-list/${id}`, item, this.options()); }
  confirmShoppingPurchase(id: number, purchase: ConfirmedPurchase): Observable<{ shopping_item: ShoppingListItem; pantry_item: PantryItem; message: string }> { return this.http.post<{ shopping_item: ShoppingListItem; pantry_item: PantryItem; message: string }>(`${this.baseUrl}/shopping-list/${id}/confirm-purchase`, purchase, this.options()); }
  deleteShoppingItem(id: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/shopping-list/${id}`, this.options()); }
  deletePurchasedShoppingItems(): Observable<{ deleted: number; message: string }> { return this.http.delete<{ deleted: number; message: string }>(`${this.baseUrl}/shopping-list/purchased`, this.options()); }
  mealHistory(): Observable<MealHistoryItem[]> { return this.http.get<MealHistoryItem[]>(`${this.baseUrl}/meal-history`, this.options()); }
  favoriteRecipe(recipeId: number): Observable<unknown> { return this.http.post(`${this.baseUrl}/recipes/${recipeId}/favorite`, {}, this.options()); }
  unfavoriteRecipe(recipeId: number): Observable<unknown> { return this.http.delete(`${this.baseUrl}/recipes/${recipeId}/favorite`, this.options()); }
  favorites(): Observable<RecipeDetail[]> { return this.http.get<RecipeDetail[]>(`${this.baseUrl}/favorites`, this.options()); }
  recipeReviews(recipeId: number): Observable<RecipeReview[] | { reviews: RecipeReview[] }> { return this.http.get<RecipeReview[] | { reviews: RecipeReview[] }>(`${this.baseUrl}/recipes/${recipeId}/reviews`, this.options()); }
  reviewRecipe(recipeId: number, rating: number, review: string): Observable<unknown> { return this.http.put(`${this.baseUrl}/recipes/${recipeId}/review`, { rating, review }, this.options()); }
}

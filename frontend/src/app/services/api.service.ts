import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Capacitor } from '@capacitor/core';
import { environment } from '../../environments/environment';
import {
  PantryItem, PackageItem, Family, RecipeIngredient, RecipeSummary, Recommendation, RecipeSearchResponse, RecipeDetail, RecipeNutrition, Profile, HouseholdProfile, FamilyInvitation, CatalogIngredient, PantryInputCandidate, PantryInputResult, ChildMealPlan, MealPlan, MealPlanPayload, MealPlanBatch, MealPlanBatchGenerationPayload, MealPlanBatchMealPayload, MealPlanIngredientStatus, MealPlanBatchSummary, PersonalMealConflict, MealPlanBatchResponse, PurchasedPlanIngredient, MealPlanIngredientCheck, MealPlanPreflight, ShoppingListItem, ConfirmedPurchase, MealHistoryItem, RecipeReview
} from './api.models';

export * from './api.models';

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

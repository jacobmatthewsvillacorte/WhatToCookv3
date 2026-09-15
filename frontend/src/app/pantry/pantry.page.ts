import { Component, OnDestroy } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { forkJoin, Subscription } from 'rxjs';
import { BarcodeFormat, BarcodeScanner } from '@capacitor-mlkit/barcode-scanning';
import { SpeechRecognition } from '@capacitor-community/speech-recognition';
import { TextRecognition } from '@capacitor-mlkit/text-recognition';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Capacitor } from '@capacitor/core';
import { Filesystem } from '@capacitor/filesystem';
import { ApiService, Family, PantryInputCandidate, PantryItem } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';
import { PantryInputCacheService } from '../services/pantry-input-cache.service';
import { ModalController, ToastController } from '@ionic/angular';
import { UseAmountModalComponent } from './use-amount-modal.component';
import { FreshnessReminderService } from '../services/freshness-reminder.service';
import { PantryChangeService } from '../services/pantry-change.service';

interface PantryForm { name: string; quantity: string; unit: string; purchase_date: string; expiry_date: string; freshness_review_date: string; purchase_source: string; storage_type: string; freshness_condition: string; family_id: number | null; }
interface ParsedPantryDraft { name: string; quantity: string; unit: string; selected: boolean; }

/** A deliberately conservative client-only receipt parser. Every result remains editable. */
export function receiptCandidates(text: string): PantryInputCandidate[] {
  const ignored = /^(total|subtotal|change|cash|vat|date|receipt|discount|tender|balance)\b/i;
  return text.split(/\r?\n/).map(line => line.trim()).filter(line => line && !ignored.test(line)).map(line => {
    const withoutPrice = line.replace(/\s+(?:₱|php)?\s*[\d,.]+\s*$/i, '').trim();
    const match = withoutPrice.match(/^(\d+(?:\.\d+)?)\s*(?:x|pcs?\.?|pieces?)?\s+(.+)$/i);
    return { name: (match?.[2] || withoutPrice).replace(/\s{2,}/g, ' ').trim(), quantity: match?.[1] || '1', unit: 'pieces', purchase_source: 'unknown', storage_type: 'unknown' };
  }).filter(candidate => candidate.name.length > 1 && /[a-z]/i.test(candidate.name));
}

@Component({ selector: 'app-pantry', templateUrl: 'pantry.page.html', styleUrls: ['pantry.page.scss'], standalone: false })
export class PantryPage implements OnDestroy {
  personalItems: PantryItem[] = []; householdItems: PantryItem[] = []; families: Family[] = []; activeFamily?: Family; viewFamilyId: number | null = null; form: PantryForm = this.emptyForm(); editingId: number | null = null;
  message = ''; saving = false; processingInput = false; listening = false; receiptText = ''; voiceReviewTranscript = ''; receiptReviewText = ''; confirmingPantryAdd = false;
  drafts: ParsedPantryDraft[] = []; suggestedCandidates: PantryInputCandidate[] = []; rejectedCandidates: PantryInputCandidate[] = []; pendingSuggestion?: PantryInputCandidate; showAdvancedDetails = false; returnToMealId?: number;
  showInventoryFilters = false;
  addPanelOpen = false;
  pantrySearch = '';
  selectedCategory = 'All';
  loading = false;
  pantryLoadError = '';
  updatingFreshnessIds = new Set<number>();
  private hiddenInventoryKeys = new Set<string>();
  private requestedScope?: 'personal' | 'family'; private requestedFamilyId?: number;
  private pantryMutationVersion = 0;
  private pantryLoadRequest = 0;
  private readonly pantryChangeSubscription: Subscription;
  readonly commonUnits = ['pieces', 'cans', 'packs', 'bottles', 'boxes', 'kg', 'g', 'ml', 'litre'];
  private recognition: any; private voiceTranscript = ''; private stoppingVoice = false;
  constructor(private api: ApiService, private householdContext: HouseholdContextService, private auth: AuthService, private inputCache: PantryInputCacheService, private route: ActivatedRoute, private router: Router, private modalController: ModalController, private toastController: ToastController, private reminders: FreshnessReminderService, pantryChanges: PantryChangeService) {
    this.pantryChangeSubscription = pantryChanges.changes$.subscribe(change => this.applyPantryChange(change.items));
  }
  ngOnDestroy(): void { this.pantryChangeSubscription.unsubscribe(); }
  ionViewWillEnter() { this.readMealReturnContext(); this.loadFamilies(); }
  loadPantryItems() {
    if (!this.api.hasToken) {
      this.loading = false;
      this.pantryLoadError = 'Your account session has expired. Please sign in again.';
      return;
    }
    this.loading = true;
    this.pantryLoadError = '';
    const requestId = ++this.pantryLoadRequest;
    const mutationVersion = this.pantryMutationVersion;
    this.api.pantry().subscribe({
      next: items => {
        if (requestId !== this.pantryLoadRequest) return;
        // Do not let a request begun before a confirmed purchase overwrite the
        // immediate update. Reload once with the newer pantry state instead.
        if (mutationVersion !== this.pantryMutationVersion) {
          this.loadPantryItems();
          return;
        }
        this.personalItems = items.filter(item => !item.family_id);
        this.householdItems = items.filter(item => !!item.family_id);
        this.refreshReminders();
        this.loading = false;
      },
      error: () => {
        if (requestId !== this.pantryLoadRequest) return;
        if (mutationVersion !== this.pantryMutationVersion) {
          this.loadPantryItems();
          return;
        }
        this.pantryLoadError = 'Could not load your pantry. Check your connection and try again.';
        this.loading = false;
      },
    });
  }
  loadFamilies() { const userId = this.auth.user?.id; this.householdContext.refresh(userId).subscribe({ next: context => { this.families = context.families; const requestedFamily = this.requestedScope === 'family' ? this.families.find(family => family.id === this.requestedFamilyId) : undefined; if (requestedFamily && userId) this.householdContext.select(userId, requestedFamily); this.activeFamily = requestedFamily || context.activeFamily || undefined; this.viewFamilyId = this.activeFamily?.id || null; if (!this.editingId && !this.form.name.trim() && this.requestedScope) this.form.family_id = this.requestedScope === 'family' ? this.viewFamilyId : null; if (this.form.family_id !== null && !this.families.some(family => family.id === this.form.family_id)) this.form.family_id = this.viewFamilyId; this.loadPantryItems(); }, error: () => { this.families = []; this.activeFamily = undefined; this.viewFamilyId = null; this.message = 'Could not load your household. Showing your personal pantry only.'; this.loadPantryItems(); } }); }
  startAdd() { const familyId = this.form.family_id; this.editingId = null; this.confirmingPantryAdd = false; this.addPanelOpen = true; this.form = this.emptyForm(familyId); this.showAdvancedDetails = false; this.message = ''; }
  closeAddPanel(): void { this.addPanelOpen = false; this.confirmingPantryAdd = false; this.pendingSuggestion = undefined; this.drafts = []; this.voiceReviewTranscript = ''; }
  adjustQuantity(change: number) { this.form.quantity = String(Math.max(1, Number(this.form.quantity || 0) + change)); }
  adjustParsedQuantity(draft: ParsedPantryDraft, change: number): void { draft.quantity = String(Math.max(1, Number(draft.quantity || 0) + change)); }
  chooseUnit(unit: string) { this.form.unit = unit; }
  suggestUnit() { if (!this.form.unit) { const name = this.form.name.toLowerCase(); if (/\b(egg|eggs)\b/.test(name)) this.form.unit = 'pieces'; else if (/\b(can|tuna|sardines|coconut milk)\b/.test(name)) this.form.unit = 'cans'; } this.validateManualIngredient(); }
  validateManualIngredient() { const name = this.form.name.trim(); if (!name || this.editingId) return; this.api.resolveIngredient(name).subscribe({ next: candidate => { if (candidate.status === 'suggested') this.pendingSuggestion = candidate; else if (candidate.status === 'rejected') { this.pendingSuggestion = undefined; this.message = candidate.message || 'This is not a recognised food ingredient.'; } else this.pendingSuggestion = undefined; }, error: () => undefined }); }
  confirmSuggestion() { if (!this.pendingSuggestion?.suggestion) return; this.form.name = this.pendingSuggestion.suggestion.canonical_name; if (!this.form.unit && this.pendingSuggestion.suggestion.default_units?.[0]) this.form.unit = this.pendingSuggestion.suggestion.default_units[0]; this.message = `Using ${this.form.name}.`; this.pendingSuggestion = undefined; }
  edit(item: PantryItem) { this.editingId = item.id; this.confirmingPantryAdd = false; this.addPanelOpen = true; this.form = { name: item.name, quantity: item.quantity || item.quantity_value?.toString() || '', unit: item.unit || '', purchase_date: this.dateOnly(item.purchase_date), expiry_date: this.dateOnly(item.expiry_date), freshness_review_date: this.dateOnly(item.freshness_review_date), purchase_source: item.purchase_source || 'unknown', storage_type: item.storage_type || 'unknown', freshness_condition: item.freshness_condition || 'unknown', family_id: item.family_id ?? null }; this.showAdvancedDetails = true; this.message = `Editing ${item.name}. Update the form below, then review and save.`; document.querySelector('ion-content')?.scrollToTop(250); }
  async scanBarcode() { try { const { supported } = await BarcodeScanner.isSupported(); if (!supported) { this.message = 'Barcode scanning is not supported on this device. Enter the item manually.'; return; } const { barcodes } = await BarcodeScanner.scan({ formats: [BarcodeFormat.Ean13, BarcodeFormat.Ean8, BarcodeFormat.UpcA, BarcodeFormat.UpcE] }); if (barcodes[0]?.displayValue) this.lookupBarcode(barcodes[0].displayValue); } catch { this.message = 'Camera scan was cancelled or permission was not granted.'; } }
  lookupBarcode(barcode: string) { const cached = this.inputCache.getBarcode(barcode); if (cached) { this.applyResult(cached); return; } this.processingInput = true; this.api.barcodeInput(barcode).subscribe({ next: result => { this.inputCache.putBarcode(barcode, result); this.applyResult(result); }, error: () => { this.message = 'Barcode captured, but product lookup failed. Enter the ingredient name, quantity, and unit for review.'; this.processingInput = false; } }); }
  async startVoice() { if (Capacitor.isNativePlatform()) return this.startNativeVoice(); this.startBrowserVoice(); }
  private async startNativeVoice() { try { const available = await SpeechRecognition.available(); if (!available.available) throw new Error('Speech recognition is not available on this device.'); let permission = await SpeechRecognition.checkPermissions(); if (permission.speechRecognition !== 'granted') permission = await SpeechRecognition.requestPermissions(); if (permission.speechRecognition !== 'granted') { this.message = 'Microphone permission was denied. Allow microphone access in Android settings and try again.'; return; } this.voiceTranscript = ''; this.voiceReviewTranscript = ''; this.listening = true; this.message = 'Speak your pantry items in the Android voice prompt.'; const result = await SpeechRecognition.start({ language: 'en-PH', maxResults: 3, partialResults: false, popup: true, prompt: 'Say your pantry items' }); this.voiceTranscript = result.matches?.join(' ').trim() || ''; this.listening = false; this.openVoiceReview(); } catch (error) { this.listening = false; const reason = error instanceof Error ? error.message : String(error || ''); this.message = /permission/i.test(reason) ? 'Microphone permission was denied. Allow microphone access in Android settings and try again.' : /no match|timeout/i.test(reason) ? 'No speech was detected. Tap Start voice and speak after the Android prompt appears.' : 'Voice recognition could not start. Make sure Google voice services are available, then try again.'; } }
  private startBrowserVoice() { const BrowserSpeech = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition; if (!BrowserSpeech) { this.message = 'Voice input is not supported in this browser. Enter the item manually.'; return; } const recognition = this.recognition = new BrowserSpeech(); this.voiceTranscript = ''; this.voiceReviewTranscript = ''; this.stoppingVoice = false; recognition.lang = 'en-PH'; recognition.interimResults = false; recognition.continuous = true; recognition.maxAlternatives = 1; this.listening = true; this.message = 'Recording… say one or more items, then tap Stop voice.'; recognition.onresult = (event: any) => { const transcript = Array.from(event.results).map((result: any) => result[0].transcript).join(' ').trim(); if (transcript) this.voiceTranscript = transcript; }; recognition.onerror = () => { this.listening = false; this.recognition = undefined; this.message = 'Voice recording could not start. Please try again.'; }; recognition.onend = () => { this.listening = false; if (this.recognition === recognition) this.recognition = undefined; if (this.stoppingVoice) { this.stoppingVoice = false; this.openVoiceReview(); } }; recognition.start(); }
  async stopVoice() { this.listening = false; this.message = 'Preparing your voice text for review…'; if (Capacitor.isNativePlatform()) { try { await SpeechRecognition.stop(); } finally { this.openVoiceReview(); } return; } if (!this.recognition) return; this.stoppingVoice = true; this.recognition.stop(); }
  confirmVoiceTranscript() { const transcript = this.voiceReviewTranscript.trim(); if (!transcript) { this.message = 'Enter or speak an item before confirming.'; return; } this.processingInput = true; this.message = 'Processing confirmed voice text…'; this.api.voiceInput(transcript).subscribe({ next: result => { this.voiceReviewTranscript = ''; this.applyResult(result); }, error: () => { this.message = 'Your voice text was not sent. Make sure Laravel is running, then try again.'; this.processingInput = false; } }); }
  discardVoiceTranscript() { this.voiceReviewTranscript = ''; this.voiceTranscript = ''; this.message = 'Voice text discarded.'; }
  private openVoiceReview() { if (!this.voiceTranscript.trim()) { this.message = 'No speech was captured. Check microphone permission, then try again and speak before stopping.'; return; } this.voiceReviewTranscript = this.voiceTranscript; this.message = 'Review and edit the captured voice text, then confirm to parse it.'; }
  async captureReceipt() { if (!Capacitor.isNativePlatform()) { this.message = 'Receipt OCR is available in the Android app. In a browser, paste receipt text below for review.'; return; } this.processingInput = true; try { const photo = await Camera.getPhoto({ quality: 90, resultType: CameraResultType.Uri, source: CameraSource.Camera, saveToGallery: false, correctOrientation: true }); if (!photo.path) throw new Error('No receipt image was captured.'); const result = await TextRecognition.processImage({ path: photo.path }); this.receiptReviewText = result.text.trim(); this.receiptText = this.receiptReviewText; this.message = this.receiptReviewText ? 'Receipt text is ready. Review it before creating item drafts.' : 'No receipt text was found. Enter it manually to continue.'; try { await Filesystem.deleteFile({ path: photo.path }); } catch { /* Camera cache is temporary; the app never persists the image. */ } } catch { this.message = 'Receipt capture or on-device text recognition was cancelled or unavailable.'; } finally { this.processingInput = false; } }
  reviewReceiptText() { const text = this.receiptReviewText.trim() || this.receiptText.trim(); if (!text) { this.message = 'Capture a receipt or enter receipt text before reviewing.'; return; } this.processingInput = true; this.api.receiptTextInput(text).subscribe({ next: result => { this.receiptText = text; this.receiptReviewText = text; this.applyResult(result); }, error: () => { this.processingInput = false; this.message = 'Receipt items could not be validated. Please try again.'; } }); }
  clearReceiptText() { this.receiptText = ''; this.receiptReviewText = ''; this.message = 'Receipt text cleared. No receipt image or text was uploaded.'; }
  private applyCandidates(candidates: PantryInputCandidate[], message: string) { this.applyResult({ message, candidates, accepted: candidates }); }
  private applyResult(result: { message: string; candidates: PantryInputCandidate[]; accepted?: PantryInputCandidate[]; suggested?: PantryInputCandidate[]; rejected?: PantryInputCandidate[] }) { const reviewable = [...(result.accepted || result.candidates || []), ...(result.suggested || [])]; this.drafts = reviewable.map(candidate => ({ name: candidate.suggestion?.canonical_name || candidate.ingredient?.canonical_name || candidate.name, quantity: candidate.quantity || '1', unit: candidate.unit || candidate.suggestion?.default_units?.[0] || candidate.ingredient?.default_units?.[0] || 'pieces', selected: true })); this.rejectedCandidates = [...(result.rejected || [])]; this.processingInput = false; this.message = this.drafts.length ? 'Check the detected items once, then add all selected items together.' : result.message; }
  dismissRejected() { this.rejectedCandidates = []; }
  confirmCandidateSuggestion(_candidate: PantryInputCandidate) { /* Suggestions are applied directly to the batch above. */ }
  addParsedItems() { const items = this.drafts.filter(item => item.selected); if (!items.length) { this.message = 'Select at least one recognised item to add.'; return; } if (items.some(item => !item.name.trim() || Number(item.quantity) <= 0 || !item.unit.trim())) { this.message = 'Each selected item needs a name, positive quantity, and unit.'; return; } this.saving = true; this.message = ''; forkJoin(items.map(item => this.api.addPantry({ ...this.emptyForm(this.form.family_id), name: item.name.trim(), quantity: item.quantity, unit: item.unit.trim() }))).subscribe({ next: () => { this.saving = false; this.drafts = []; this.message = `${items.length} pantry item${items.length === 1 ? '' : 's'} added.`; this.showSavedToast(items.length); this.loadPantryItems(); }, error: error => { this.saving = false; this.message = error?.error?.message || 'Could not add the selected items. Nothing was cleared; correct the item and try again.'; } }); }
  private async showSavedToast(count: number): Promise<void> { const toast = await this.toastController.create({ message: `${count} item${count === 1 ? '' : 's'} added to your pantry`, duration: 2600, position: 'top', color: 'success', icon: 'checkmark-circle-outline', buttons: [{ text: 'OK', role: 'cancel' }] }); await toast.present(); }
  private applyCandidate(candidate: PantryInputCandidate | undefined, message: string) { if (candidate) { this.form.name = candidate.name || this.form.name; this.form.quantity = candidate.quantity || this.form.quantity; this.form.unit = candidate.unit || this.form.unit; this.form.purchase_source = candidate.purchase_source || this.form.purchase_source; this.form.storage_type = candidate.storage_type || this.form.storage_type; } this.message = message; this.processingInput = false; }
  requestSavePantryItem() { if (!this.isCompletePantryForm()) { this.message = 'Enter an ingredient, a positive quantity, and a unit before reviewing.'; return; } this.confirmingPantryAdd = true; }
  cancelPantryConfirmation() { this.confirmingPantryAdd = false; }
  confirmSavePantryItem() { if (!this.isCompletePantryForm()) { this.confirmingPantryAdd = false; this.message = 'The pantry details changed. Review the ingredient, quantity, and unit again.'; return; } this.confirmingPantryAdd = false; this.saving = true; this.message = ''; const request = this.editingId ? this.api.updatePantry(this.editingId, this.form) : this.api.addPantry(this.form); request.subscribe({ next: () => { const familyId = this.form.family_id; this.editingId = null; this.form = this.emptyForm(familyId); this.addPanelOpen = false; this.message = 'Pantry item saved.'; this.saving = false; this.loadPantryItems(); }, error: error => { this.message = error?.error?.message || 'Could not save the pantry item.'; this.saving = false; } }); }
  pantryScopeLabel(): string { return this.form.family_id ? `${this.families.find(family => family.id === this.form.family_id)?.name || 'Selected family'} shared pantry` : 'My personal pantry'; }
  private isCompletePantryForm(): boolean { return !!this.form.name.trim() && Number(this.form.quantity) > 0 && !!this.form.unit.trim(); }
  shareWithActiveFamily(item: PantryItem) {
    if (!this.activeFamily) { this.message = 'Choose a family before sharing pantry stock.'; return; }
    if (!confirm(`Move ${item.name} to ${this.activeFamily.name}'s shared pantry? Family meal plans will then count it as available.`)) return;
    this.api.updatePantry(item.id, { family_id: this.activeFamily.id }).subscribe({
      next: () => { this.message = `${item.name} is now available to ${this.activeFamily!.name}'s meal plans.`; this.loadPantryItems(); },
      error: error => this.message = error?.error?.message || `Could not share ${item.name} with the family pantry.`,
    });
  }
  deletePantryItem(item: PantryItem) { if (!confirm(`Delete ${item.name} from the pantry?`)) return; this.api.deletePantry(item.id).subscribe({ next: () => { if (this.editingId === item.id) this.startAdd(); this.message = 'Pantry item deleted.'; this.loadPantryItems(); }, error: () => this.message = 'Could not delete the pantry item.' }); }
  deleteExpiredItems(items: PantryItem[]) { const expired = this.expiredItems(items); if (!expired.length || !confirm(`Delete ${expired.length} expired pantry item${expired.length === 1 ? '' : 's'}? This cannot be undone.`)) return; forkJoin(expired.map(item => this.api.deletePantry(item.id))).subscribe({ next: () => { this.message = `${expired.length} expired pantry item${expired.length === 1 ? '' : 's'} deleted.`; this.loadPantryItems(); }, error: () => { this.message = 'Could not delete all expired pantry items. Please try again.'; this.loadPantryItems(); } }); }
  async recordFreshnessAction(item: PantryItem, action: 'still_fresh' | 'spoiled' | 'used' | 'discarded' | 'undo_used') { if (action === 'used') { const modal = await this.modalController.create({ component: UseAmountModalComponent, componentProps: { item }, initialBreakpoint: 0.62, breakpoints: [0, 0.62, 0.9] }); await modal.present(); const { data, role } = await modal.onDidDismiss<{ amount: number; reason: string }>(); if (role !== 'confirm' || !data) return; this.api.updateFreshness(item.id, 'used', data.amount, data.reason).subscribe({ next: ({ item: updated }) => { this.message = `${updated.name} updated.`; this.loadPantryItems(); }, error: error => this.message = error?.error?.message || 'Could not record usage.' }); return; } this.updatingFreshnessIds.add(item.id); this.api.updateFreshness(item.id, action).subscribe({ next: ({ item: updated }) => { this.message = action === 'still_fresh' ? `${updated.name} is fresh until tomorrow.` : `${updated.name} updated.`; this.updatingFreshnessIds.delete(item.id); this.loadPantryItems(); }, error: error => { this.updatingFreshnessIds.delete(item.id); this.message = error?.error?.message || 'Could not update freshness.'; } }); }
  canMarkStillFresh(item: PantryItem): boolean { if (!['fresh', 'review'].includes(item.freshness_status || 'fresh')) return false; if (!item.expiry_date) return !!item.freshness_review_date; return this.dateOnly(item.expiry_date) === this.todayDate(); }
  isExpired(item: PantryItem): boolean { return !!item.expiry_date && this.dateOnly(item.expiry_date) < this.todayDate(); }
  expiredItems(items: PantryItem[]): PantryItem[] { return items.filter(item => this.isExpired(item)); }
  isAttention(item: PantryItem): boolean { return item.freshness_status === 'review' || (!!item.freshness_review_date && new Date(item.freshness_review_date).getTime() <= Date.now()); }
  itemCategory(item: PantryItem): string {
    const name = item.name.toLowerCase();
    if (/chicken|pork|beef|fish|tuna|shrimp|egg|meat/.test(name)) return 'Protein';
    if (/milk|cheese|butter|yogurt/.test(name)) return 'Dairy';
    if (/rice|bread|flour|pasta|noodle|oat/.test(name)) return 'Grains';
    if (/onion|garlic|tomato|potato|carrot|vegetable|pepper|cabbage|ginger/.test(name)) return 'Produce';
    return 'Pantry';
  }
  categoryCount(category: string): number { return this.allPantryItems().filter(item => category === 'All' || this.itemCategory(item) === category).length; }
  filteredItems(items: PantryItem[]): PantryItem[] {
    const query = this.pantrySearch.trim().toLowerCase();
    return items.filter(item => (this.selectedCategory === 'All' || this.itemCategory(item) === this.selectedCategory) && (!query || item.name.toLowerCase().includes(query)));
  }
  stockState(item: PantryItem): 'low' | 'expiring' | 'good' { if (this.isAttention(item) || item.freshness_status === 'spoiled') return 'expiring'; return this.isLowStock(item) ? 'low' : 'good'; }
  stockLabel(item: PantryItem): string { if (item.freshness_status === 'discarded') return 'Discarded'; if (this.isExpired(item) || item.freshness_status === 'spoiled') return 'Expired'; const state = this.stockState(item); return state === 'expiring' ? 'Expiring soon' : state === 'low' ? 'Low stock' : 'In stock'; }
  allPantryItems(): PantryItem[] { return [...this.personalItems, ...this.householdItems]; }
  private isLowStock(item: PantryItem): boolean { return Number(item.quantity_value ?? item.quantity ?? 0) <= 1 && !this.isAttention(item); }
  inventoryGroups(): Array<{ title: string; items: PantryItem[]; shared: boolean; key: string }> { return [{ title: 'Your pantry', items: this.personalItems, shared: false, key: 'personal' }, ...this.families.map(family => ({ title: `${family.name} pantry`, items: this.householdItems.filter(item => item.family_id === family.id), shared: true, key: `family-${family.id}` }))]; }
  inventoryVisible(key: string): boolean { return !this.hiddenInventoryKeys.has(key); }
  toggleInventory(key: string): void { this.hiddenInventoryKeys.has(key) ? this.hiddenInventoryKeys.delete(key) : this.hiddenInventoryKeys.add(key); }
  returnToMeal(): void { if (this.returnToMealId) this.router.navigate(['/meal-details', this.returnToMealId]); }
  private applyPantryChange(items: PantryItem[]): void {
    if (!items.length) return;
    this.pantryMutationVersion++;
    const personalItems = items.filter(item => !item.family_id);
    const householdItems = items.filter(item => !!item.family_id);
    this.personalItems = this.mergePantryItems(this.personalItems, personalItems);
    this.householdItems = this.mergePantryItems(this.householdItems, householdItems);
    this.pantryLoadError = '';
    this.refreshReminders();
  }
  private mergePantryItems(current: PantryItem[], changes: PantryItem[]): PantryItem[] {
    const items = new Map(current.map(item => [item.id, item]));
    changes.forEach(item => items.set(item.id, item));
    return Array.from(items.values());
  }
  private readMealReturnContext(): void { const scope = this.route.snapshot.queryParamMap.get('scope'); this.requestedScope = scope === 'family' || scope === 'personal' ? scope : undefined; const familyId = Number(this.route.snapshot.queryParamMap.get('family_id')); this.requestedFamilyId = Number.isInteger(familyId) && familyId > 0 ? familyId : undefined; const mealId = Number(this.route.snapshot.queryParamMap.get('return_to_meal_id')); this.returnToMealId = Number.isInteger(mealId) && mealId > 0 ? mealId : undefined; }
  private emptyForm(familyId: number | null = null): PantryForm { return { name: '', quantity: '1', unit: '', purchase_date: new Date().toISOString().slice(0, 10), expiry_date: '', freshness_review_date: '', purchase_source: 'unknown', storage_type: 'unknown', freshness_condition: 'unknown', family_id: familyId }; }
  private dateOnly(value?: string): string { return value ? value.slice(0, 10) : ''; }
  private todayDate(): string { const today = new Date(); return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`; }
  private refreshReminders(): void { void this.reminders.schedule([...this.personalItems, ...this.householdItems]); }
}

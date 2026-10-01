import { Component } from '@angular/core';
import { forkJoin, Subscription } from 'rxjs';
import { finalize, switchMap } from 'rxjs/operators';
import { ApiService, ConfirmedPurchase, Family, ShoppingListItem } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';
import { ExportLine, ExportService } from '../services/export.service';

@Component({ selector: 'app-shopping-list', templateUrl: './shopping-list.page.html', styleUrls: ['./shopping-list.page.scss'], standalone: false })
export class ShoppingListPage {
  items: ShoppingListItem[] = [];
  household?: Family;
  name = '';
  quantity = '';
  unit = '';
  message = '';
  loading = false;
  loadError = '';
  markingAll = false;
  deletingBought = false;
  confirmDeleteBought = false;
  confirmMarkAll = false;
  purchaseItem?: ShoppingListItem;
  purchase: ConfirmedPurchase = this.emptyPurchase();
  confirmingPurchase = false;
  private loadSubscription?: Subscription;
  private loadAttempt = 0;
  readonly markAllAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Mark all bought', role: 'confirm', handler: () => this.markAllBought() },
  ];
  readonly deleteBoughtAlertButtons = [
    { text: 'Cancel', role: 'cancel' },
    { text: 'Delete bought items', role: 'destructive', handler: () => this.deleteBoughtItems() },
  ];

  constructor(private api: ApiService, private auth: AuthService, private context: HouseholdContextService, private exports: ExportService) {}

  ionViewWillEnter(): void { this.load(); }
  ionViewWillLeave(): void { this.cancelLoad(); }

  get remainingItems(): ShoppingListItem[] { return this.items.filter(item => !item.is_purchased); }
  get purchasedItems(): ShoppingListItem[] { return this.items.filter(item => item.is_purchased); }

  load(): void {
    const id = this.auth.user?.id;
    if (!id) return;
    this.cancelLoad();
    const attempt = ++this.loadAttempt;
    this.loading = true;
    this.loadError = '';
    this.loadSubscription = this.context.refresh(id).pipe(
      switchMap(context => {
        this.household = context.activeFamily || undefined;
        return this.api.shoppingList();
      }),
      finalize(() => {
        if (this.loadAttempt === attempt) this.loading = false;
      }),
    ).subscribe({
      next: items => {
        if (this.loadAttempt !== attempt) return;
        this.items = this.household
          ? items.filter(item => item.family_id === this.household?.id)
          : items.filter(item => !item.family_id);
      },
      error: error => {
        if (this.loadAttempt !== attempt) return;
        this.loadError = error?.status === 429
          ? 'The service is temporarily busy. Please wait a moment, then try again.'
          : 'Could not load the shopping list. Check your connection and try again.';
      },
    });
  }

  private cancelLoad(): void {
    this.loadSubscription?.unsubscribe();
    this.loadSubscription = undefined;
  }

  add(): void {
    if (!this.name.trim()) return;
    this.api.addShoppingItem({ ingredient_name: this.name.trim(), quantity: this.quantity || null, unit: this.unit || null, is_purchased: false, family_id: this.household?.id || null }).subscribe({
      next: () => { this.name = this.quantity = this.unit = ''; this.load(); },
      error: () => this.message = 'Could not add the item.',
    });
  }

  toggle(item: ShoppingListItem): void {
    this.api.updateShoppingItem(item.id, { is_purchased: !item.is_purchased }).subscribe({
      next: updated => item.is_purchased = updated.is_purchased,
      error: () => this.message = 'Could not update the item.',
    });
  }

  beginPurchase(item: ShoppingListItem): void {
    this.purchaseItem = item;
    this.purchase = { ...this.emptyPurchase(), name: item.ingredient_name, quantity: item.purchase_quantity || item.quantity || '', unit: item.purchase_unit || item.unit || '' };
  }

  confirmPurchase(): void {
    if (!this.purchaseItem || !this.purchase.quantity || !this.purchase.unit.trim()) {
      this.message = 'Confirm a positive quantity and unit before adding stock.';
      return;
    }
    this.confirmingPurchase = true;
    this.api.confirmShoppingPurchase(this.purchaseItem.id, this.purchase).subscribe({
      next: result => {
        this.items = this.items.map(item => item.id === result.shopping_item.id ? result.shopping_item : item);
        this.message = result.message;
        this.purchaseItem = undefined;
        this.confirmingPurchase = false;
      },
      error: error => { this.confirmingPurchase = false; this.message = error?.error?.message || 'Could not confirm this purchase.'; },
    });
  }

  cancelPurchase(): void { this.purchaseItem = undefined; }

  markAllBought(): void {
    const remaining = this.remainingItems;
    this.confirmMarkAll = false;
    if (!remaining.length) return;
    this.markingAll = true;
    forkJoin(remaining.map(item => this.api.updateShoppingItem(item.id, { is_purchased: true }))).subscribe({
      next: () => { this.markingAll = false; this.items = this.items.map(item => ({ ...item, is_purchased: item.is_purchased || remaining.some(selected => selected.id === item.id) })); },
      error: () => { this.markingAll = false; this.message = 'Could not mark all items as bought. Please try again.'; },
    });
  }

  deleteBoughtItems(): void {
    this.confirmDeleteBought = false;
    if (!this.purchasedItems.length || this.deletingBought) return;
    this.deletingBought = true;
    this.api.deletePurchasedShoppingItems().subscribe({
      next: result => {
        this.deletingBought = false;
        this.items = this.items.filter(item => !item.is_purchased);
        this.message = result.message;
      },
      error: error => {
        this.deletingBought = false;
        this.message = error?.error?.message || 'Could not delete bought items. Please try again.';
      },
    });
  }

  remove(item: ShoppingListItem): void {
    this.api.deleteShoppingItem(item.id).subscribe({
      next: () => this.items = this.items.filter(current => current.id !== item.id),
      error: () => this.message = 'Could not remove the item.',
    });
  }

  export(kind: 'text' | 'image' | 'pdf'): void {
    const title = `${this.household ? this.household.name + ' shared' : 'Personal'} shopping list`;
    const lines: ExportLine[] = this.items.map(item => ({ title: item.ingredient_name, detail: [item.quantity, item.unit].filter(Boolean).join(' '), checked: item.is_purchased }));
    if (kind === 'text') this.exports.downloadText('whattocook-shopping-list.txt', lines);
    if (kind === 'image') this.exports.downloadImage('whattocook-shopping-list.svg', title, lines);
    if (kind === 'pdf') this.exports.print(title, lines);
  }

  private emptyPurchase(): ConfirmedPurchase { return { confirmed: true, quantity: '', unit: '', purchase_date: new Date().toISOString().slice(0, 10), expiry_date: null, purchase_source: 'unknown', storage_type: 'unknown' }; }
}

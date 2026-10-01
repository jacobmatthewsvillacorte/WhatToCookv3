import { of, throwError } from 'rxjs';
import { ApiService, ShoppingListItem } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';
import { ExportService } from '../services/export.service';
import { ShoppingListPage } from './shopping-list.page';

describe('ShoppingListPage', () => {
  let api: jasmine.SpyObj<ApiService>;
  let component: ShoppingListPage;

  const item = (id: number, purchased: boolean): ShoppingListItem => ({
    id, ingredient_name: `Item ${id}`, quantity: '6.6', unit: 'tbsp',
    purchase_quantity: '100', purchase_unit: 'ml', purchase_note: 'Approximate', is_purchased: purchased,
  });

  beforeEach(() => {
    api = jasmine.createSpyObj<ApiService>('ApiService', ['deletePurchasedShoppingItems']);
    component = new ShoppingListPage(
      api,
      { user: { id: 1 } } as AuthService,
      {} as HouseholdContextService,
      {} as ExportService,
    );
  });

  it('exposes practical purchase suggestions while retaining the recipe amount', () => {
    component.items = [item(1, false)];
    expect(component.items[0].purchase_quantity).toBe('100');
    expect(component.items[0].purchase_unit).toBe('ml');
    expect(component.items[0].quantity).toBe('6.6');
    component.beginPurchase(component.items[0]);
    expect(component.purchase.quantity).toBe('100');
    expect(component.purchase.unit).toBe('ml');
  });

  it('does not request bulk deletion when there are no purchased items', () => {
    component.items = [item(1, false)];
    component.deleteBoughtItems();
    expect(api.deletePurchasedShoppingItems).not.toHaveBeenCalled();
  });

  it('waits for confirmation and keeps unpurchased items after successful deletion', () => {
    component.items = [item(1, true), item(2, false)];
    component.confirmDeleteBought = true;
    api.deletePurchasedShoppingItems.and.returnValue(of({ deleted: 1, message: 'Deleted 1 bought item.' }));

    component.deleteBoughtItems();

    expect(component.confirmDeleteBought).toBeFalse();
    expect(api.deletePurchasedShoppingItems).toHaveBeenCalledTimes(1);
    expect(component.items.map(current => current.id)).toEqual([2]);
    expect(component.message).toBe('Deleted 1 bought item.');
  });

  it('preserves both items and reports a failed bulk deletion', () => {
    component.items = [item(1, true), item(2, false)];
    api.deletePurchasedShoppingItems.and.returnValue(throwError(() => ({ error: { message: 'Denied.' } })));

    component.deleteBoughtItems();

    expect(component.items.map(current => current.id)).toEqual([1, 2]);
    expect(component.message).toBe('Denied.');
    expect(component.deletingBought).toBeFalse();
  });
});
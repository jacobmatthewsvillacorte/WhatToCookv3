import { NgModule } from '@angular/core'; import { CommonModule } from '@angular/common'; import { FormsModule } from '@angular/forms'; import { IonicModule } from '@ionic/angular'; import { ShoppingListPageRoutingModule } from './shopping-list-routing.module'; import { ShoppingListPage } from './shopping-list.page';
import { StateComponent } from '../shared/state/state.component';
@NgModule({ imports: [StateComponent, CommonModule, FormsModule, IonicModule, ShoppingListPageRoutingModule], declarations: [ShoppingListPage] }) export class ShoppingListPageModule {}

import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonicModule } from '@ionic/angular';
import { FavoritesPageRoutingModule } from './favorites-routing.module';
import { FavoritesPage } from './favorites.page';
import { StateComponent } from '../shared/state/state.component';
@NgModule({ imports: [StateComponent, CommonModule, IonicModule, FavoritesPageRoutingModule], declarations: [FavoritesPage] }) export class FavoritesPageModule {}

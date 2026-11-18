import { CommonModule } from '@angular/common';
import { NgModule } from '@angular/core';
import { IonicModule } from '@ionic/angular';
import { FormsModule } from '@angular/forms';
import { CookingPageRoutingModule } from './cooking-routing.module';
import { CookingPage } from './cooking.page';
import { StateComponent } from '../shared/state/state.component';

@NgModule({ imports: [StateComponent, CommonModule, FormsModule, IonicModule, CookingPageRoutingModule], declarations: [CookingPage] })
export class CookingPageModule {}

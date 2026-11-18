import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonicModule } from '@ionic/angular';
import { MorePageRoutingModule } from './more-routing.module';
import { MorePage } from './more.page';
import { StateComponent } from '../shared/state/state.component';
@NgModule({ imports: [StateComponent, CommonModule, IonicModule, MorePageRoutingModule], declarations: [MorePage] })
export class MorePageModule {}

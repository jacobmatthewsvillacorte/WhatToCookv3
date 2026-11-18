import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonicModule } from '@ionic/angular';
import { ProfilePageRoutingModule } from './profile-routing.module';
import { ProfilePage } from './profile.page';
import { StateComponent } from '../shared/state/state.component';
@NgModule({ imports: [StateComponent, CommonModule, FormsModule, IonicModule, ProfilePageRoutingModule], declarations: [ProfilePage] })
export class ProfilePageModule {}

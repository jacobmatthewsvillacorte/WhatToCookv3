import { Component } from '@angular/core';
import { Router } from '@angular/router';
import { ApiService, RecipeDetail } from '../services/api.service';

@Component({ selector: 'app-favorites', templateUrl: './favorites.page.html', styleUrls: ['./favorites.page.scss'], standalone: false })
export class FavoritesPage {
  recipes: RecipeDetail[] = [];
  loading = false;
  message = '';
  loadError = '';

  constructor(private api: ApiService, private router: Router) {}

  ionViewWillEnter(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.message = '';
    this.loadError = '';
    this.api.favorites().subscribe({
      next: recipes => {
        this.recipes = recipes;
        this.loading = false;
      },
      error: () => {
        this.loadError = 'Could not load favorites. Check your connection and try again.';
        this.loading = false;
      },
    });
  }

  open(recipe: RecipeDetail): void {
    this.router.navigate(['/recipes', recipe.id]);
  }

  remove(recipe: RecipeDetail): void {
    this.api.unfavoriteRecipe(recipe.id).subscribe({
      next: () => this.recipes = this.recipes.filter(item => item.id !== recipe.id),
      error: () => this.message = 'Could not remove this favorite.',
    });
  }

  image(recipe: RecipeDetail): string {
    return recipe.image_url || 'assets/shapes.svg';
  }

  fallback(event: Event): void {
    (event.target as HTMLImageElement).src = 'assets/shapes.svg';
  }
}

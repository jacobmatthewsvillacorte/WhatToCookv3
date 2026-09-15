@php
    $formIngredients = old('ingredients', $ingredients);
    if (count($formIngredients) === 0) {
        $formIngredients = [['name' => '', 'quantity' => '', 'nutrition_fdc_id' => '', 'nutrition_grams' => '', 'unit' => '', 'is_substitute' => false]];
    }
@endphp

<form class="card" method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <h2>Recipe details</h2>
    <div class="field-grid">
        <div class="field">
            <label for="name">Recipe name *</label>
            <input id="name" name="name" value="{{ old('name', $recipe->name) }}" required>
            @error('name') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="region">Region / cuisine</label>
            <input id="region" name="region" value="{{ old('region', $recipe->region) }}" placeholder="e.g. Filipino">
            @error('region') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="meal_type">Meal type</label>
            <select id="meal_type" name="meal_type">
                <option value="">Select a type</option>
                @foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'snack' => 'Snack'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('meal_type', $recipe->meal_type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('meal_type') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="difficulty">Difficulty</label>
            <select id="difficulty" name="difficulty">
                @foreach (['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('difficulty', $recipe->difficulty ?: 'easy') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('difficulty') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="prep_time">Prep time (minutes)</label>
            <input id="prep_time" name="prep_time" type="number" min="0" value="{{ old('prep_time', $recipe->prep_time) }}">
            @error('prep_time') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="cook_time">Cook time (minutes)</label>
            <input id="cook_time" name="cook_time" type="number" min="0" value="{{ old('cook_time', $recipe->cook_time) }}">
            @error('cook_time') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="servings">Servings</label>
            <input id="servings" name="servings" type="number" min="1" value="{{ old('servings', $recipe->servings ?: 2) }}">
            @error('servings') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="image">Image URL</label>
            <input id="image" name="image" type="url" value="{{ old('image', $recipe->image) }}" placeholder="https://example.com/recipe.jpg">
            <p class="help">Use an image you own or have permission to use.</p>
            @error('image') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="image_source_url">Image source URL</label>
            <input id="image_source_url" name="image_source_url" type="url" value="{{ old('image_source_url', $recipe->image_source_url) }}" placeholder="https://source.example/photo">
            <p class="help">Required with attribution, so editors can verify image rights.</p>
            @error('image_source_url') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="image_attribution">Image attribution / licence</label>
            <input id="image_attribution" name="image_attribution" value="{{ old('image_attribution', $recipe->image_attribution) }}" placeholder="Photographer, source, and licence">
            @error('image_attribution') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field full">
            <label for="description">Description</label>
            <textarea id="description" name="description" placeholder="A short description of the dish">{{ old('description', $recipe->description) }}</textarea>
            @error('description') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field full">
            <label for="instructions">Instructions *</label>
            <textarea id="instructions" name="instructions" required placeholder="1. Prepare ingredients...&#10;2. Cook...">{{ old('instructions', $recipe->instructions) }}</textarea>
            @error('instructions') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field full">
            <label for="cooking_tips">Cooking tips</label>
            <textarea id="cooking_tips" name="cooking_tips" placeholder="Optional substitutions, serving suggestions, or safety notes">{{ old('cooking_tips', $recipe->cooking_tips) }}</textarea>
            @error('cooking_tips') <p class="error-text">{{ $message }}</p> @enderror
        </div>
    </div>

    <h2 style="margin-top:30px;">Ingredients</h2>
    <p class="subheading" style="margin-top:-8px;">At least one approved pantry-catalogue ingredient is required. Names are standardized for pantry matching and shopping lists.</p>
    @error('ingredients') <p class="error-text">{{ $message }}</p> @enderror

    <div id="ingredientRows">
        @foreach ($formIngredients as $index => $ingredient)
            <div class="ingredient-row field-grid four" data-ingredient-row style="margin-top:12px; padding:14px; border:1px solid var(--line); border-radius:10px;">
                <div class="field">
                    <label>Ingredient *</label>
                    <input name="ingredients[{{ $index }}][name]" value="{{ $ingredient['name'] ?? '' }}" required placeholder="e.g. Chicken">
                    @error("ingredients.$index.name") <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label>Quantity</label>
                    <input name="ingredients[{{ $index }}][quantity]" value="{{ $ingredient['quantity'] ?? '' }}" placeholder="e.g. 500">
                    @error("ingredients.$index.quantity") <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label>Unit</label>
                    <input name="ingredients[{{ $index }}][unit]" value="{{ $ingredient['unit'] ?? '' }}" placeholder="e.g. g, cup, pcs">
                    @error("ingredients.$index.unit") <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label>USDA food record</label>
                    <input data-nutrition-query value="{{ $ingredient['name'] ?? '' }}" placeholder="Search USDA food">
                    <button class="secondary" type="button" data-search-nutrition>Search USDA</button>
                    <div data-nutrition-results class="help" aria-live="polite"></div>
                </div>
                <div class="field">
                    <label>USDA FDC ID</label>
                    <input data-fdc-id name="ingredients[{{ $index }}][nutrition_fdc_id]" type="number" min="1" value="{{ $ingredient['nutrition_fdc_id'] ?? '' }}" placeholder="Select a search result">
                    @error("ingredients.$index.nutrition_fdc_id") <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label>Ingredient weight (g)</label>
                    <input name="ingredients[{{ $index }}][nutrition_grams]" type="number" min="0.001" step="0.001" value="{{ $ingredient['nutrition_grams'] ?? '' }}" placeholder="e.g. 500">
                    <p class="help">Use the actual edible weight for this recipe.</p>
                    @error("ingredients.$index.nutrition_grams") <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div class="field" style="display:flex; align-items:end; gap:10px; padding-bottom:2px;">
                    <label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:500;"><input type="hidden" name="ingredients[{{ $index }}][is_substitute]" value="0"><input type="checkbox" name="ingredients[{{ $index }}][is_substitute]" value="1" @checked((bool) ($ingredient['is_substitute'] ?? false)) style="width:auto;"> Substitute</label>
                    <button class="danger" type="button" data-remove-ingredient>Remove</button>
                </div>
            </div>
        @endforeach
    </div>
    <div class="actions" style="margin-top:14px;">
        <button class="secondary" type="button" id="addIngredient">+ Add another ingredient</button>
    </div>

    <h2 style="margin-top:30px;">Optional nutrition per serving</h2>
    <div class="field-grid four">
        @foreach (['calories' => 'Calories (kcal)', 'protein' => 'Protein (g)', 'carbs' => 'Carbs (g)', 'fat' => 'Fat (g)'] as $field => $label)
            <div class="field">
                <label for="{{ $field }}">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="0.01" value="{{ old($field, $recipe->$field) }}">
                @error($field) <p class="error-text">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>

    <div class="actions">
        <button type="submit">{{ $submitLabel }}</button>
        <a class="button secondary" href="{{ route('admin.recipes.index') }}">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
    (() => {
        const rows = document.getElementById('ingredientRows');
        const addButton = document.getElementById('addIngredient');
        let nextIndex = {{ count($formIngredients) }};

        const rowMarkup = (index) => `
            <div class="ingredient-row field-grid four" data-ingredient-row style="margin-top:12px; padding:14px; border:1px solid var(--line); border-radius:10px;">
                <div class="field"><label>Ingredient *</label><input name="ingredients[${index}][name]" required placeholder="e.g. Chicken"></div>
                <div class="field"><label>Quantity</label><input name="ingredients[${index}][quantity]" placeholder="e.g. 500"></div>
                <div class="field"><label>Unit</label><input name="ingredients[${index}][unit]" placeholder="e.g. g, cup, pcs"></div>
                <div class="field"><label>USDA food record</label><input data-nutrition-query placeholder="Search USDA food"><button class="secondary" type="button" data-search-nutrition>Search USDA</button><div data-nutrition-results class="help" aria-live="polite"></div></div>
                <div class="field"><label>USDA FDC ID</label><input data-fdc-id name="ingredients[${index}][nutrition_fdc_id]" type="number" min="1" placeholder="Select a search result"></div>
                <div class="field"><label>Ingredient weight (g)</label><input name="ingredients[${index}][nutrition_grams]" type="number" min="0.001" step="0.001" placeholder="e.g. 500"><p class="help">Use the actual edible weight for this recipe.</p></div>
                <div class="field" style="display:flex; align-items:end; gap:10px; padding-bottom:2px;"><label style="display:flex; align-items:center; gap:6px; margin:0; font-weight:500;"><input type="hidden" name="ingredients[${index}][is_substitute]" value="0"><input type="checkbox" name="ingredients[${index}][is_substitute]" value="1" style="width:auto;"> Substitute</label><button class="danger" type="button" data-remove-ingredient>Remove</button></div>
            </div>`;

        addButton.addEventListener('click', () => {
            rows.insertAdjacentHTML('beforeend', rowMarkup(nextIndex++));
            rows.lastElementChild.querySelector('input').focus();
        });

        rows.addEventListener('click', (event) => {
            const searchButton = event.target.closest('[data-search-nutrition]');
            if (searchButton) {
                const row = searchButton.closest('[data-ingredient-row]');
                const query = row.querySelector('[data-nutrition-query]').value.trim();
                const results = row.querySelector('[data-nutrition-results]');
                if (query.length < 2) { results.textContent = 'Enter at least two characters to search USDA.'; return; }
                results.textContent = 'Searching USDA…';
                fetch(`{{ route('admin.nutrition.search') }}?query=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                    .then(async response => {
                        if (!response.ok) throw new Error((await response.json()).message || 'USDA search failed.');
                        return response.json();
                    })
                    .then(({ foods }) => {
                        if (!foods.length) { results.textContent = 'No USDA results found. Try a simpler ingredient name.'; return; }
                        results.replaceChildren(...foods.map(food => {
                            const button = document.createElement('button');
                            button.type = 'button'; button.className = 'secondary';
                            button.textContent = `${food.description} (FDC ${food.fdc_id})`;
                            button.addEventListener('click', () => {
                                row.querySelector('[data-fdc-id]').value = food.fdc_id;
                                results.textContent = `Selected: ${food.description}.`;
                            });
                            return button;
                        }));
                    })
                    .catch(error => { results.textContent = error.message || 'Could not search USDA.'; });
                return;
            }
            if (!event.target.matches('[data-remove-ingredient]')) return;
            const allRows = rows.querySelectorAll('[data-ingredient-row]');
            if (allRows.length === 1) {
                allRows[0].querySelector('input[name$="[name]"]').focus();
                return;
            }
            event.target.closest('[data-ingredient-row]').remove();
        });
    })();
</script>
@endpush

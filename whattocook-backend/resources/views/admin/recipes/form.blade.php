@php
    $formIngredients = old('ingredients', $ingredients);
    if (count($formIngredients) === 0) {
        $formIngredients = [['name' => '', 'quantity' => '', 'nutrition_fdc_id' => '', 'nutrition_grams' => '', 'unit' => '', 'is_substitute' => false]];
    }
@endphp

<form class="card" method="POST" action="{{ $action }}" enctype="multipart/form-data">
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
            <label for="image">Recipe image</label>
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" data-recipe-image>
            <p class="help">Upload a JPEG, PNG, or WebP image up to 5 MB. Leave empty to keep the current image.</p>
            @if ($recipe->image)
                <p class="help">Current image: {{ filter_var($recipe->image, FILTER_VALIDATE_URL) ? $recipe->image : asset('storage/'.$recipe->image) }}</p>
            @endif
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
                    <input type="hidden" name="ingredients[{{ $index }}][is_substitute]" value="{{ (int) ($ingredient['is_substitute'] ?? false) }}">
                    @if (!($ingredient['is_substitute'] ?? false))
                        <button class="secondary" type="button" data-toggle-alternatives>{{ (($ingredient['is_substitute'] ?? false) || (($ingredient['name'] ?? '') !== '')) ? 'Hide alternative' : 'Show alternative' }}</button>
                    @endif
                    <button class="danger" type="button" data-remove-ingredient>Remove</button>
                </div>
                @if (!($ingredient['is_substitute'] ?? false))
                    <div class="field full" data-alternatives>
                        <label>Alternative ingredients</label>
                        <div data-alternative-list class="help" aria-live="polite"></div>
                        <div data-alternative-form hidden style="margin-top:8px; display:grid; grid-template-columns:2fr 1fr 1fr auto auto; gap:8px; align-items:end;">
                            <div><label>Ingredient</label><input data-alternative-name placeholder="e.g. Tofu"></div>
                            <div><label>Quantity</label><input data-alternative-quantity placeholder="Same"></div>
                            <div><label>Unit</label><input data-alternative-unit placeholder="Same"></div>
                            <button class="secondary" type="button" data-add-alternative>Add ingredient</button>
                            <button class="secondary" type="button" data-hide-alternative-form>Hide</button>
                        </div>
                    </div>
                @endif
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
                <div style="display:flex; align-items:center; gap:8px;">
                    <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="0.01" value="{{ old($field, $calculatedNutrition[$field] ?? $recipe->$field) }}" style="flex:1;">
                    @if (isset($calculatedNutrition[$field]))
                        <span class="badge" style="font-size:11px; padding:3px 7px; border-radius:999px; background:rgba(16,185,129,0.12); color:#7ce5b5; border:1px solid rgba(16,185,129,0.4); white-space:nowrap;">Auto</span>
                    @endif
                </div>
                @error($field) <p class="error-text">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>
    <p class="help">USDA-calculated calories per serving: <strong>{{ old('calories', $calculatedNutrition['calories'] ?? $recipe->calories) !== null && old('calories', $calculatedNutrition['calories'] ?? $recipe->calories) !== '' ? number_format((float) old('calories', $calculatedNutrition['calories'] ?? $recipe->calories), 2) . ' kcal' : 'Save the recipe after linking USDA food records to calculate this value.' }}</strong></p>

    <div class="actions">
        <button type="submit">{{ $submitLabel }}</button>
        <a class="button secondary" href="{{ route('admin.recipes.index') }}">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
    (() => {
        const imageInput = document.querySelector('[data-recipe-image]');
        if (imageInput) {
            imageInput.addEventListener('change', () => {
                const file = imageInput.files[0];
                if (!file) return;
                const allowed = ['image/jpeg', 'image/png', 'image/webp'];
                if (!allowed.includes(file.type)) {
                    imageInput.value = '';
                    alert('Choose a JPEG, PNG, or WebP image. HEIC and GIF files are not supported.');
                } else if (file.size > 5 * 1024 * 1024) {
                    imageInput.value = '';
                    alert('Choose an image no larger than 5 MB.');
                }
            });
        }

        const rows = document.getElementById('ingredientRows');
        const addButton = document.getElementById('addIngredient');
        const servingsInput = document.getElementById('servings');
        let nextIndex = {{ count($formIngredients) }};

        const round2 = (value) => Number.isFinite(value) ? Number(value.toFixed(2)) : 0;

        const syncOptionalNutritionFields = () => {
            const ingredientRows = [...rows.querySelectorAll('[data-ingredient-row]')];
            const totals = { calories: 0, protein: 0, carbs: 0, fat: 0 };
            let hasUsdaCoverage = false;

            ingredientRows.forEach((row) => {
                const fdcId = row.querySelector('[data-fdc-id]')?.value;
                const gramsInput = row.querySelector('input[name$="[nutrition_grams]"]');
                const grams = Number(gramsInput?.value || 0);
                const nutrients = row.dataset.nutrients ? JSON.parse(row.dataset.nutrients) : null;

                if (!fdcId || !nutrients || !(grams > 0)) {
                    return;
                }

                hasUsdaCoverage = true;
                ['calories', 'protein', 'carbs', 'fat'].forEach((nutrient) => {
                    totals[nutrient] += ((Number(nutrients[nutrient] || 0)) * grams) / 100;
                });
            });

            if (!hasUsdaCoverage) {
                return;
            }

            const servings = Math.max(1, Number(servingsInput?.value || 1));
            ['calories', 'protein', 'carbs', 'fat'].forEach((nutrient) => {
                const input = document.getElementById(nutrient);
                if (!input) return;
                input.value = round2(totals[nutrient] / servings);
            });
        };

        const rowMarkup = (index) => `
            <div class="ingredient-row field-grid four" data-ingredient-row style="margin-top:12px; padding:14px; border:1px solid var(--line); border-radius:10px;">
                <div class="field"><label>Ingredient *</label><input name="ingredients[${index}][name]" required placeholder="e.g. Chicken"></div>
                <div class="field"><label>Quantity</label><input name="ingredients[${index}][quantity]" placeholder="e.g. 500"></div>
                <div class="field"><label>Unit</label><input name="ingredients[${index}][unit]" placeholder="e.g. g, cup, pcs"></div>
                <div class="field"><label>USDA food record</label><input data-nutrition-query placeholder="Search USDA food"><button class="secondary" type="button" data-search-nutrition>Search USDA</button><div data-nutrition-results class="help" aria-live="polite"></div></div>
                <div class="field"><label>USDA FDC ID</label><input data-fdc-id name="ingredients[${index}][nutrition_fdc_id]" type="number" min="1" placeholder="Select a search result"></div>
                <div class="field"><label>Ingredient weight (g)</label><input name="ingredients[${index}][nutrition_grams]" type="number" min="0.001" step="0.001" placeholder="e.g. 500"><p class="help">Use the actual edible weight for this recipe.</p></div>
                <div class="field" style="display:flex; align-items:end; gap:10px; padding-bottom:2px;"><input type="hidden" name="ingredients[${index}][is_substitute]" value="0"><button class="secondary" type="button" data-toggle-alternatives>Show alternative</button><button class="danger" type="button" data-remove-ingredient>Remove</button></div>
                <div class="field full" data-alternatives><label>Alternative ingredients</label><div data-alternative-list class="help" aria-live="polite"></div><div data-alternative-form hidden style="margin-top:8px; display:grid; grid-template-columns:2fr 1fr 1fr auto auto; gap:8px; align-items:end;"><div><label>Ingredient</label><input data-alternative-name placeholder="e.g. Tofu"></div><div><label>Quantity</label><input data-alternative-quantity placeholder="Same"></div><div><label>Unit</label><input data-alternative-unit placeholder="Same"></div><button class="secondary" type="button" data-add-alternative>Add ingredient</button><button class="secondary" type="button" data-hide-alternative-form>Hide</button></div></div>
            </div>`;

        const addAlternative = (row, name, quantity = '', unit = '', fdcId = '', grams = '') => {
            const index = nextIndex++;
            const list = row.querySelector('[data-alternative-list]');
            const item = document.createElement('div');
            item.className = 'alternative-item';
            item.style = 'display:flex; align-items:center; gap:8px; margin-top:6px;';
            const label = document.createElement('strong');
            label.textContent = name;
            const details = document.createElement('span');
            details.textContent = `${quantity || 'same'} ${unit || ''}`;
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'danger'; remove.textContent = 'Remove'; remove.dataset.removeAlternative = '';
            item.append(label, details, remove);
            [['name', name], ['quantity', quantity], ['unit', unit], ['nutrition_fdc_id', fdcId], ['nutrition_grams', grams], ['is_substitute', '1']].forEach(([field, value]) => {
                if (value === '' && (field === 'nutrition_fdc_id' || field === 'nutrition_grams')) return;
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = `ingredients[${index}][${field}]`; input.value = value;
                item.appendChild(input);
            });
            list.appendChild(item);
        };

        const groupSavedAlternatives = () => {
            let mainRow = null;
            [...rows.querySelectorAll('[data-ingredient-row]')].forEach((row) => {
                const substitute = row.querySelector('input[name$="[is_substitute]"]')?.value === '1';
                if (!substitute) {
                    mainRow = row;
                    return;
                }
                if (!mainRow) return;
                const name = row.querySelector('input[name$="[name]"]').value;
                const quantity = row.querySelector('input[name$="[quantity]"]').value;
                const unit = row.querySelector('input[name$="[unit]"]').value;
                const fdcId = row.querySelector('input[name$="[nutrition_fdc_id]"]')?.value || '';
                const grams = row.querySelector('input[name$="[nutrition_grams]"]')?.value || '';
                addAlternative(mainRow, name, quantity, unit, fdcId, grams);
                row.remove();
            });
        };

        const updateAlternativeVisibility = (row, visible) => {
            const section = row.querySelector('[data-alternatives]');
            const form = row.querySelector('[data-alternative-form]');
            const button = row.querySelector('[data-toggle-alternatives]');
            if (!section || !button) return;

            section.hidden = !visible;
            if (form) form.hidden = true;
            button.textContent = visible ? 'Hide alternative' : 'Show alternative';
        };

        groupSavedAlternatives();

        [...rows.querySelectorAll('[data-ingredient-row]')].forEach((row) => {
            const section = row.querySelector('[data-alternatives]');
            const hasItems = section && section.querySelectorAll('.alternative-item').length > 0;
            updateAlternativeVisibility(row, hasItems);
        });

        addButton.addEventListener('click', () => {
            rows.insertAdjacentHTML('beforeend', rowMarkup(nextIndex++));
            const newRow = rows.lastElementChild;
            updateAlternativeVisibility(newRow, false);
            newRow.querySelector('input').focus();
        });

        rows.addEventListener('input', (event) => {
            const row = event.target.closest('[data-ingredient-row]');
            if (!row) return;
            if (event.target.matches('[data-fdc-id]') || event.target.matches('input[name$="[nutrition_grams]"]')) {
                syncOptionalNutritionFields();
            }
        });

        servingsInput?.addEventListener('input', syncOptionalNutritionFields);

        rows.addEventListener('click', (event) => {
            const toggleButton = event.target.closest('[data-toggle-alternatives]');
            if (toggleButton) {
                const row = toggleButton.closest('[data-ingredient-row]');
                const section = row.querySelector('[data-alternatives]');
                const nextVisible = section.hidden;
                updateAlternativeVisibility(row, nextVisible);
                if (nextVisible) {
                    const form = row.querySelector('[data-alternative-form]');
                    form.hidden = true;
                }
                return;
            }
            const addAlternativeButton = event.target.closest('[data-add-alternative]');
            if (addAlternativeButton) {
                const row = addAlternativeButton.closest('[data-ingredient-row]');
                const form = row.querySelector('[data-alternative-form]');
                const nameInput = form.querySelector('[data-alternative-name]');
                const name = nameInput.value.trim();
                if (name.length < 2) { nameInput.focus(); return; }
                addAlternative(row, name, form.querySelector('[data-alternative-quantity]').value.trim(), form.querySelector('[data-alternative-unit]').value.trim());
                form.querySelectorAll('input').forEach((input) => { input.value = ''; });
                form.hidden = true;
                updateAlternativeVisibility(row, true);
                return;
            }
            const hideAlternativeButton = event.target.closest('[data-hide-alternative-form]');
            if (hideAlternativeButton) {
                const row = hideAlternativeButton.closest('[data-ingredient-row]');
                updateAlternativeVisibility(row, false);
                return;
            }
            if (event.target.closest('[data-remove-alternative]')) {
                event.target.closest('.alternative-item').remove();
                return;
            }
            const searchButton = event.target.closest('[data-search-nutrition]');
            if (searchButton) {
                const row = searchButton.closest('[data-ingredient-row]');
                const query = row.querySelector('[data-nutrition-query]').value.trim();
                const results = row.querySelector('[data-nutrition-results]');
                if (query.length < 2) { results.textContent = 'Enter at least two characters to search USDA.'; return; }
                results.textContent = 'Searching USDA…';
                fetch(`{{ route('admin.nutrition.search') }}?query=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                    .then(async response => {
                        const contentType = response.headers.get('content-type') || '';
                        const payload = contentType.includes('application/json')
                            ? await response.json()
                            : { message: 'The server returned an unexpected response.' };
                        if (!response.ok) throw new Error(payload.message || 'USDA search failed.');
                        return payload;
                    })
                    .then(({ foods }) => {
                        if (!foods.length) { results.textContent = 'No USDA results found. Try a simpler ingredient name.'; return; }
                        results.replaceChildren(...foods.map(food => {
                            const button = document.createElement('button');
                            button.type = 'button'; button.className = 'secondary';
                            button.textContent = `${food.description} (FDC ${food.fdc_id})`;
                            button.addEventListener('click', () => {
                                row.querySelector('[data-fdc-id]').value = food.fdc_id;
                                const weightInput = row.querySelector('input[name$="[nutrition_grams]"]');
                                const servingUnit = String(food.serving_size_unit || '').toLowerCase();
                                const servingInGrams = servingUnit === 'g' || servingUnit === 'gram' || servingUnit === 'grams';
                                const weight = servingInGrams && Number(food.serving_size) > 0 ? Number(food.serving_size) : 100;
                                weightInput.value = weight;
                                row.dataset.nutrients = JSON.stringify(food.nutrients_per_100g || {});
                                results.textContent = servingInGrams && Number(food.serving_size) > 0
                                    ? `Selected: ${food.description}. Weight set to ${weight} g from USDA serving size.`
                                    : `Selected: ${food.description}. Weight set to 100 g, the USDA nutrition basis.`;
                                syncOptionalNutritionFields();
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

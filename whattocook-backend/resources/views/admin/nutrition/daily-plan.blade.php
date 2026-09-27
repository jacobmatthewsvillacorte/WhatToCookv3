@extends('admin.layout')

@section('content')
<h1>Daily Serving Plan</h1>
<p>Estimate a daily calorie and macro target, then explore servings by food group.</p>
<form id="daily-plan-form">
    @csrf
    <label>Saved household profile
        <select name="profile_id"><option value="">Enter details below</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach</select>
    </label>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0">
        <label>Age <input name="age" type="number" min="18" max="120"></label>
        <label>Sex <select name="sex"><option value="female">Female</option><option value="male">Male</option></select></label>
        <label>Weight (kg) <input name="weight_kg" type="number" step="0.1"></label>
        <label>Height (cm) <input name="height_cm" type="number" step="0.1"></label>
        <label>Activity <select name="activity_level"><option value="sedentary">Sedentary</option><option value="light">Light</option><option value="moderate" selected>Moderate</option><option value="active">Active</option><option value="very_active">Very active</option></select></label>
        <label>Goal <select name="goal"><option value="maintain">Maintain</option><option value="weight_loss">Weight loss</option><option value="weight_gain">Weight gain</option></select></label>
        <label>Health conditions <input name="health_conditions" placeholder="e.g. diabetic"></label>
    </div>
    <h2>Food per group (optional)</h2>
    @foreach(['rice_staple' => 'Rice / staple', 'ulam_protein' => 'Ulam / protein', 'fruit' => 'Fruit', 'vegetable' => 'Vegetable'] as $key => $label)
        <fieldset data-group="{{ $key }}" style="margin:12px 0;padding:12px"><legend>{{ $label }}</legend>
            <input data-query placeholder="Search USDA food"><button type="button" data-search>Search USDA</button><div data-results aria-live="polite"></div>
            <input type="hidden" name="foods[{{ $key }}][fdc_id]" data-fdc>
            <label>Serving size (g) <input name="foods[{{ $key }}][serving_size_g]" type="number" value="{{ $key === 'rice_staple' ? 150 : ($key === 'ulam_protein' ? 100 : 80) }}" min="1"></label>
        </fieldset>
    @endforeach
    <button type="submit">Calculate plan</button>
</form>
<pre id="plan-result" style="white-space:pre-wrap;margin-top:20px"></pre>
<script>
(() => {
    const form = document.querySelector('#daily-plan-form'), output = document.querySelector('#plan-result');
    form.addEventListener('click', async event => {
        const button = event.target.closest('[data-search]'); if (!button) return;
        const row = button.closest('fieldset'), query = row.querySelector('[data-query]').value.trim(), results = row.querySelector('[data-results]');
        if (query.length < 2) { results.textContent = 'Enter at least two characters.'; return; }
        try {
            const response = await fetch(`{{ route('admin.nutrition.search') }}?query=${encodeURIComponent(query)}`, {headers:{Accept:'application/json'}});
            const isJson = response.headers.get('content-type')?.includes('application/json'), data = isJson ? await response.json() : null;
            if (!response.ok) throw new Error(data?.message || (response.status === 503 ? 'USDA search is unavailable. Check the backend USDA_API_KEY configuration.' : `USDA search failed (HTTP ${response.status}).`));
            if (!data) throw new Error('USDA search returned an unexpected response.');
            results.replaceChildren(...data.foods.map(food => { const choice = document.createElement('button'); choice.type = 'button'; choice.textContent = `${food.description} (FDC ${food.fdc_id})`; choice.onclick = () => { row.querySelector('[data-fdc]').value = food.fdc_id; results.textContent = `Selected ${food.description}`; }; return choice; }));
            if (!data.foods.length) results.textContent = 'No results found.';
        } catch (error) { results.textContent = error.message; }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault(); output.textContent = 'Calculating…';
        try { const response = await fetch('{{ route('admin.nutrition.daily-plan.calculate') }}', {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value}, body:new FormData(form)}); const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Could not calculate plan.'); output.textContent = JSON.stringify(data, null, 2); }
        catch (error) { output.textContent = error.message; }
    });
})();
</script>
@endsection

@extends('admin.layout')

@section('title', 'Meal plans')

@section('content')
    <div class="page-heading">
        <div>
            <h1>Meal plans</h1>
            <p class="subheading">View stored meal plan records created by the app and their status.</p>
        </div>
    </div>

    <section class="card">
        <form method="GET" action="{{ route('admin.meal-plans.index') }}" class="field-grid" style="align-items:end; margin-bottom:20px;">
            <div class="field">
                <label for="q">Search meal plans</label>
                <input id="q" name="q" value="{{ $search }}" placeholder="Recipe, date, meal type, or status">
            </div>
            <div class="actions" style="margin:0;">
                <button type="submit">Search</button>
                @if ($search !== '')
                    <a class="button secondary" href="{{ route('admin.meal-plans.index') }}">Clear</a>
                @endif
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Recipe</th>
                        <th>Date</th>
                        <th>Meal type</th>
                        <th>Servings</th>
                        <th>Status</th>
                        <th>Family</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mealPlans as $mealPlan)
                        <tr>
                            <td><strong>{{ $mealPlan->recipe?->name ?? 'Unknown recipe' }}</strong></td>
                            <td>{{ $mealPlan->planned_date?->toDateString() ?? '—' }}</td>
                            <td>{{ $mealPlan->meal_type ?: '—' }}</td>
                            <td>{{ $mealPlan->servings ?? '—' }}</td>
                            <td><span class="badge">{{ ucfirst((string) ($mealPlan->status ?? 'scheduled')) }}</span></td>
                            <td>
                                {{ $mealPlan->family ? $mealPlan->family->name : ($mealPlan->user?->name ?? 'Personal') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td class="empty" colspan="6">No meal plans found in storage.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($mealPlans->hasPages())
            <nav class="pagination" aria-label="Meal plan pages">
                <span>
                    @if ($mealPlans->onFirstPage()) Previous @else <a href="{{ $mealPlans->previousPageUrl() }}">← Previous</a> @endif
                </span>
                <span>Page {{ $mealPlans->currentPage() }} of {{ $mealPlans->lastPage() }}</span>
                <span>
                    @if ($mealPlans->hasMorePages()) <a href="{{ $mealPlans->nextPageUrl() }}">Next →</a> @else Next @endif
                </span>
            </nav>
        @endif
    </section>
@endsection

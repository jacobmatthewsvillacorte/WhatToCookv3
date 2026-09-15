<?php

namespace App\Http\Controllers;

use App\Models\FamilyMember;
use App\Services\IngredientCatalogService;
use App\Models\PantryItem;
use App\Models\IngredientPackageConversion;
use App\Services\PantryFreshnessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PantryController extends Controller
{
    private const PURCHASE_SOURCES = ['supermarket', 'sari_sari_store', 'wet_market', 'homegrown', 'leftover', 'unknown'];

    private const STORAGE_TYPES = ['room_temperature', 'refrigerated', 'frozen', 'other', 'unknown'];

    private const CONDITIONS = ['fresh', 'good', 'uncertain', 'unknown'];

    public function index(Request $request)
    {
        $personal = $request->boolean('personal');
        $items = $this->visibleItems($request, $this->requestedFamilyId($request), $personal)->orderBy('freshness_review_date')->get();
        // GET remains read-only. The scheduled freshness command persists due items;
        // this presentation fallback keeps an overdue item actionable between runs.
        $items->each(function (PantryItem $item) {
            if (! in_array($item->freshness_status, ['fresh', 'review'], true)) {
                return;
            }
            $reviewDate = $item->freshness_review_date ?? $item->expiry_date;
            if ($reviewDate !== null && $reviewDate->lessThanOrEqualTo(now()->startOfDay()) && $item->freshness_status !== 'review') {
                $item->freshness_status = 'review';
            }
        });

        return response()->json($items);
    }

    public function store(Request $request, PantryFreshnessService $freshness)
    {
        $data = $this->validatedData($request);
        $this->canUseFamily($request, $data['family_id'] ?? null);
        if (! empty($data['purchase_date']) && ! empty($data['expiry_date']) && $data['expiry_date'] < $data['purchase_date']) {
            throw ValidationException::withMessages(['expiry_date' => ['The expiry date must be on or after the purchase date.']]);
        }

        $hasPrintedExpiry = ! empty($data['expiry_date']);
        $source = $data['purchase_source'] ?? 'unknown';
        $estimated = $freshness->estimate($data['name'], $data['unit'] ?? null, $data['storage_type'] ?? null, $source);
        $expiryDate = $data['expiry_date'] ?? $estimated['expiry_date'];

        if ($expiryDate === null) {
            $existing = PantryItem::query()
                ->when(
                    ($data['family_id'] ?? null) === null,
                    fn ($query) => $query->where('user_id', $request->user()->id)->whereNull('family_id'),
                    fn ($query) => $query->where('family_id', $data['family_id'])
                )
                ->whereRaw('lower(name) = ?', [strtolower($data['name'])])
                ->whereRaw('lower(unit) = ?', [strtolower((string) ($data['unit'] ?? ''))])
                ->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('is_expiry_estimated', true))
                ->whereIn('freshness_status', ['fresh', 'review'])
                ->first();
            if ($existing !== null) {
                $total = round((float) ($existing->quantity_value ?? $existing->quantity) + (float) $data['quantity'], 3);
                $existing->update([
                    'quantity_value' => $total,
                    'quantity' => (string) $total,
                    'expiry_date' => null,
                    'freshness_review_date' => null,
                    'freshness_status' => 'fresh',
                ]);

                return response()->json(['item' => $existing->fresh(), 'message' => 'Pantry stock added to the existing item.'], 201);
            }
        }

        $item = PantryItem::create([
            ...$data,
            'user_id' => $request->user()->id,
            'quantity' => (string) $data['quantity'],
            'quantity_value' => $data['quantity'],
            'purchase_source' => $source,
            'storage_type' => $data['storage_type'] ?? 'unknown',
            'freshness_condition' => $data['freshness_condition'] ?? 'unknown',
            'expiry_date' => $expiryDate,
            'freshness_review_date' => $data['freshness_review_date'] ?? ($hasPrintedExpiry ? $data['expiry_date'] : $estimated['review_date']),
            'freshness_status' => $hasPrintedExpiry ? 'fresh' : $estimated['status'],
            'freshness_confidence' => $hasPrintedExpiry ? 'high' : $estimated['confidence'],
            'is_expiry_estimated' => ! $hasPrintedExpiry,
        ]);

        return response()->json(['item' => $item, 'message' => 'Pantry item added successfully.'], 201);
    }

    public function update(Request $request, $id, PantryFreshnessService $freshness)
    {
        $data = $this->validatedData($request, true);
        $item = $this->visibleItems($request)->findOrFail($id);
        $this->canUseFamily($request, $data['family_id'] ?? $item->family_id);

        $purchaseDate = $data['purchase_date'] ?? $item->purchase_date?->toDateString();
        $expiryDate = array_key_exists('expiry_date', $data) ? $data['expiry_date'] : $item->expiry_date?->toDateString();
        if (array_key_exists('expiry_date', $data) && $data['expiry_date'] !== null) {
            // Entering a printed date replaces all assumptions made by the estimator.
            $data['freshness_review_date'] = $data['freshness_review_date'] ?? $data['expiry_date'];
            $data['freshness_status'] = 'fresh';
            $data['freshness_confidence'] = 'high';
            $data['is_expiry_estimated'] = false;
        } elseif ($expiryDate === null) {
            $estimate = $freshness->estimate(
                $data['name'] ?? $item->name,
                $data['unit'] ?? $item->unit,
                $data['storage_type'] ?? $item->storage_type,
                $data['purchase_source'] ?? $item->purchase_source ?? 'unknown',
            );
            $expiryDate = $estimate['expiry_date'];
            $data['expiry_date'] = $expiryDate;
            $data['is_expiry_estimated'] = true;
            $data['freshness_review_date'] = $data['freshness_review_date'] ?? $estimate['review_date'];
            $data['freshness_status'] = $estimate['status'];
            $data['freshness_confidence'] = $estimate['confidence'];
        }
        if ($purchaseDate !== null && $expiryDate < $purchaseDate) {
            throw ValidationException::withMessages(['expiry_date' => ['The expiry date must be on or after the purchase date.']]);
        }
        if (array_key_exists('quantity', $data)) {
            $data['quantity_value'] = $data['quantity'];
            $data['quantity'] = (string) $data['quantity'];
        }
        $item->update($data);

        return response()->json(['item' => $item->fresh(), 'message' => 'Pantry item updated successfully.']);
    }

    public function updateFreshness(Request $request, $id)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['still_fresh', 'spoiled', 'used', 'discarded', 'undo_used'])],
            'review_date' => ['nullable', 'date'],
            'used_quantity' => ['nullable', 'numeric', 'gt:0'],
            'usage_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $item = $this->visibleItems($request)->findOrFail($id);
        if ($data['action'] === 'used') {
            $available = (float) ($item->quantity_value ?? $item->quantity ?? 0);
            $used = (float) ($data['used_quantity'] ?? $available);
            if ($used > $available) {
                throw ValidationException::withMessages(['used_quantity' => ['You cannot use more than the available quantity.']]);
            }
            $remaining = round($available - $used, 3);
            $item->update([
                'quantity_value' => $remaining, 'quantity' => (string) $remaining, 'last_used_quantity' => $used, 'last_usage_reason' => $data['usage_reason'] ?? null,
                'previous_freshness_status' => $item->freshness_status,
                'freshness_status' => $remaining <= 0 ? 'used' : ($item->freshness_status === 'review' ? 'review' : 'fresh'),
            ]);

            return response()->json(['item' => $item->fresh(), 'message' => $remaining <= 0 ? 'Item marked as used.' : 'Usage recorded and remaining stock updated.']);
        }
        if ($data['action'] === 'undo_used') {
            abort_unless($item->freshness_status === 'used' && $item->last_used_quantity !== null, 422, 'There is no usage action to undo.');
            $restored = (float) ($item->quantity_value ?? 0) + (float) $item->last_used_quantity;
            $item->update(['quantity_value' => $restored, 'quantity' => (string) $restored, 'freshness_status' => $item->previous_freshness_status ?: 'fresh', 'last_used_quantity' => null, 'last_usage_reason' => null, 'previous_freshness_status' => null]);

            return response()->json(['item' => $item->fresh(), 'message' => 'Last usage was undone.']);
        }
        if ($data['action'] === 'still_fresh' && $item->expiry_date !== null && $item->expiry_date->lt(today())) {
            throw ValidationException::withMessages([
                'action' => ['An expired ingredient cannot be marked as still fresh.'],
            ]);
        }
        $updates = match ($data['action']) {
            'still_fresh' => [
                'freshness_status' => 'fresh',
                'freshness_condition' => 'fresh',
                'freshness_review_date' => now()->addDay()->toDateString(),
            ],
            'spoiled' => ['freshness_status' => 'spoiled'],
            'used' => ['freshness_status' => 'used'],
            'discarded' => ['freshness_status' => 'discarded'],
        };
        if ($data['action'] === 'still_fresh') {
            // A user-confirmed extension also gives legacy undated items a date.
            $updates['expiry_date'] = $updates['freshness_review_date'];
        }
        $item->update($updates);

        return response()->json(['item' => $item->fresh(), 'message' => 'Freshness status updated.']);
    }

    public function destroy(Request $request, $id)
    {
        $item = $this->visibleItems($request)->findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Pantry item deleted successfully.']);
    }

    /** Save a reusable package weight/count for this pantry scope. */
    public function confirmPackageConversion(Request $request, $id)
    {
        $item = $this->visibleItems($request)->findOrFail($id);
        $data = $request->validate([
            'amount_per_package' => 'required|numeric|gt:0|max:1000000',
            'amount_unit' => 'required|string|max:50',
        ]);
        $conversion = IngredientPackageConversion::updateOrCreate([
            'user_id' => $item->user_id,
            'family_id' => $item->family_id,
            'ingredient_name' => strtolower(trim($item->name)),
            'package_unit' => strtolower(trim((string) $item->unit)),
            'amount_unit' => strtolower(trim($data['amount_unit'])),
        ], ['amount_per_package' => $data['amount_per_package']]);

        return response()->json(['conversion' => $conversion, 'message' => 'Package conversion saved and will be used for future recipe matches.']);
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'name' => $required.'string|max:255',
            'quantity' => $required.'numeric|gt:0|max:999999999.999',
            'unit' => $required.'string|max:50',
            'purchase_date' => 'sometimes|nullable|date',
            'expiry_date' => 'sometimes|nullable|date',
            'freshness_review_date' => 'sometimes|nullable|date',
            'purchase_source' => ['sometimes', Rule::in(self::PURCHASE_SOURCES)],
            'storage_type' => ['sometimes', Rule::in(self::STORAGE_TYPES)],
            'freshness_condition' => ['sometimes', Rule::in(self::CONDITIONS)],
            'family_id' => 'sometimes|nullable|exists:families,id',
        ]);

        if (array_key_exists('name', $data)) {
            if (! app(IngredientCatalogService::class)->isCanonicalApproved($data['name'])) {
                throw ValidationException::withMessages(['name' => ['Choose a recognised canonical ingredient. Confirm a suggestion before saving it.']]);
            }
        }

        return $data;
    }


    private function canUseFamily(Request $request, ?int $familyId): void
    {
        if ($familyId !== null) {
            abort_unless(FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(), 403);
        }
    }

    private function requestedFamilyId(Request $request): ?int
    {
        $familyId = $request->validate(['family_id' => 'nullable|integer|exists:families,id'])['family_id'] ?? null;
        $this->canUseFamily($request, $familyId);

        return $familyId;
    }

    private function visibleItems(Request $request, ?int $familyId = null, bool $personal = false)
    {
        if ($personal) {
            return PantryItem::where('user_id', $request->user()->id)->whereNull('family_id');
        }
        $familyIds = FamilyMember::where('user_id', $request->user()->id)->where('status', 'accepted')->pluck('family_id');
        if ($familyId !== null) {
            return PantryItem::where('family_id', $familyId);
        }

        return PantryItem::where(fn ($query) => $query->where('user_id', $request->user()->id)->orWhereIn('family_id', $familyIds));
    }
}

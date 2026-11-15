<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\HouseholdProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HouseholdProfileController extends Controller
{
    private const PRIVATE_FIELDS = [
        'health_conditions',
        'health_condition',
        'allergies',
        'dietary_restrictions',
        'likes',
        'dislikes',
    ];

    public function index(Request $request, Family $family)
    {
        $this->member($request, $family);

        return response()->json([
            'household_profiles' => $family->householdProfiles()
                ->orderBy('name')
                ->get()
                ->map(fn (HouseholdProfile $profile) => $this->forViewer($profile, $request, $family))
                ->values(),
        ]);
    }

    public function store(Request $request, Family $family)
    {
        $this->owner($request, $family);
        $data = $this->validatedData($request);
        $this->validateLinkedUser($family, $data);

        $profile = $family->householdProfiles()->create($data);

        return response()->json([
            'household_profile' => $this->forViewer($profile, $request, $family),
        ], 201);
    }

    public function show(Request $request, Family $family, HouseholdProfile $householdProfile)
    {
        $this->member($request, $family);
        $this->belongsToFamily($family, $householdProfile);

        return response()->json([
            'household_profile' => $this->forViewer($householdProfile, $request, $family),
        ]);
    }

    public function update(Request $request, Family $family, HouseholdProfile $householdProfile)
    {
        $this->member($request, $family);
        $this->belongsToFamily($family, $householdProfile);
        $this->manager($request, $family, $householdProfile);

        $data = $this->validatedData($request, partial: true);

        // A linked account can maintain its own dietary profile, but cannot re-link it.
        if ((int) $family->owner_id !== (int) $request->user()->id) {
            unset($data['user_id']);
        }

        $this->validateLinkedUser($family, $data, $householdProfile);
        $householdProfile->update($data);
        if ($householdProfile->user_id !== null && (int) $householdProfile->user_id === (int) $request->user()->id) {
            $personalFields = collect($data)->only(self::PRIVATE_FIELDS)->all();
            if ($personalFields) {
                $request->user()->profile()->updateOrCreate([], $personalFields);
                HouseholdProfile::where('user_id', $request->user()->id)->update($personalFields);
            }
        }

        return response()->json([
            'household_profile' => $this->forViewer($householdProfile->fresh(), $request, $family),
        ]);
    }

    public function destroy(Request $request, Family $family, HouseholdProfile $householdProfile)
    {
        $this->owner($request, $family);
        $this->belongsToFamily($family, $householdProfile);
        $householdProfile->delete();

        return response()->noContent();
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'name' => $partial
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
            'relation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sex' => ['sometimes', 'nullable', 'string', 'max:50'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'height_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:300'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'activity_level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:255'],
            'health_condition' => ['sometimes', 'nullable', 'string', 'in:none,diabetic,low_sugar'],
            'health_conditions' => ['sometimes', 'nullable', 'array'],
            'health_conditions.*' => ['nullable', 'string', 'max:255'],
            'allergies' => ['sometimes', 'nullable', 'array'],
            'allergies.*' => ['nullable', 'string', 'max:255'],
            'dietary_restrictions' => ['sometimes', 'nullable', 'array'],
            'dietary_restrictions.*' => ['nullable', 'string', 'max:255'],
            'likes' => ['sometimes', 'nullable', 'array'],
            'likes.*' => ['nullable', 'string', 'max:255'],
            'dislikes' => ['sometimes', 'nullable', 'array'],
            'dislikes.*' => ['nullable', 'string', 'max:255'],
            'visible_to_family' => ['sometimes', 'nullable', 'array'],
            'visible_to_family.health_conditions' => ['sometimes', 'boolean'],
            'visible_to_family.health_condition' => ['sometimes', 'boolean'],
            'visible_to_family.allergies' => ['sometimes', 'boolean'],
            'visible_to_family.dietary_restrictions' => ['sometimes', 'boolean'],
            'visible_to_family.likes' => ['sometimes', 'boolean'],
            'visible_to_family.dislikes' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateLinkedUser(Family $family, array $data, ?HouseholdProfile $ignore = null): void
    {
        if (! array_key_exists('user_id', $data) || $data['user_id'] === null) {
            return;
        }

        $isFamilyMember = FamilyMember::where([
            'family_id' => $family->id,
            'user_id' => $data['user_id'],
            'status' => 'accepted',
        ])->exists();

        if (! $isFamilyMember) {
            throw ValidationException::withMessages([
                'user_id' => ['The linked user must be a member of this family.'],
            ]);
        }

        $alreadyLinked = HouseholdProfile::query()
            ->where('family_id', $family->id)
            ->where('user_id', $data['user_id'])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($alreadyLinked) {
            throw ValidationException::withMessages([
                'user_id' => ['This family member already has a household profile.'],
            ]);
        }
    }

    private function forViewer(HouseholdProfile $profile, Request $request, Family $family): array
    {
        $attributes = $profile->toArray();

        if ($this->canManage($request, $family, $profile)) {
            return $attributes;
        }

        $visibility = $attributes['visible_to_family'] ?? [];

        foreach (self::PRIVATE_FIELDS as $field) {
            if (($visibility[$field] ?? false) !== true) {
                unset($attributes[$field]);
            }
        }

        unset($attributes['visible_to_family']);

        return $attributes;
    }

    private function belongsToFamily(Family $family, HouseholdProfile $profile): void
    {
        abort_unless((int) $profile->family_id === (int) $family->id, 404);
    }

    private function member(Request $request, Family $family): void
    {
        abort_unless(FamilyMember::where([
            'family_id' => $family->id,
            'user_id' => $request->user()->id,
            'status' => 'accepted',
        ])->exists(), 403);
    }

    private function owner(Request $request, Family $family): void
    {
        abort_unless((int) $family->owner_id === (int) $request->user()->id, 403);
    }

    private function manager(Request $request, Family $family, HouseholdProfile $profile): void
    {
        abort_unless($this->canManage($request, $family, $profile), 403);
    }

    private function canManage(Request $request, Family $family, HouseholdProfile $profile): bool
    {
        return (int) $family->owner_id === (int) $request->user()->id
            || ($profile->user_id !== null && (int) $profile->user_id === (int) $request->user()->id);
    }
}

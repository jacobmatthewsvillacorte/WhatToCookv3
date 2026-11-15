<?php

namespace App\Models;

use App\Services\ChildMealPlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdProfile extends Model
{
    protected $appends = ['age_band'];
    protected $fillable = [
        'family_id',
        'user_id',
        'name',
        'relation',
        'sex',
        'birth_date',
        'height_cm',
        'weight_kg',
        'activity_level',
        'goal',
        'health_condition',
        'health_conditions',
        'allergies',
        'dietary_restrictions',
        'likes',
        'dislikes',
        'visible_to_family',
    ];

    /** Sensitive dietary and health fields are private unless explicitly shared. */
    protected $attributes = [
        'health_condition' => 'none',
        'visible_to_family' => '{"health_condition":false,"health_conditions":false,"allergies":false,"dietary_restrictions":false,"likes":false,"dislikes":false}',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'health_conditions' => 'array',
            'allergies' => 'array',
            'dietary_restrictions' => 'array',
            'likes' => 'array',
            'dislikes' => 'array',
            'visible_to_family' => 'array',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Dependents remain selectable, while account-linked diners must still be
     * accepted household members. This prevents a removed account's stale
     * profile from being used in a plan.
     */
    public function scopeSelectableForFamily($query, int $familyId)
    {
        return $query->where('family_id', $familyId)->where(function ($profiles) use ($familyId) {
            $profiles->whereNull('user_id')->orWhereExists(function ($members) use ($familyId) {
                $members->selectRaw('1')->from('family_members')
                    ->whereColumn('family_members.user_id', 'household_profiles.user_id')
                    ->where('family_members.family_id', $familyId)
                    ->where('family_members.status', 'accepted');
            });
        });
    }

    /** The API derives this from the stored date; clients never submit an age. */
    public function getAgeBandAttribute(): ?string
    {
        return app(ChildMealPlanner::class)->ageBand($this->birth_date, now());
    }
}

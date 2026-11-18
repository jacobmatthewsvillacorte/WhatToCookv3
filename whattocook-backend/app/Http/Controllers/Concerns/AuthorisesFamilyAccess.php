<?php

namespace App\Http\Controllers\Concerns;

use App\Models\FamilyMember;
use Illuminate\Http\Request;

/**
 * The single definition of "may this caller act for this family?".
 * Family scope is allowed only for an *accepted* member; anything else is 403.
 * Personal scope (null family) always passes. See docs/12-api-reference.md.
 */
trait AuthorisesFamilyAccess
{
    protected function canUseFamily(Request $request, ?int $familyId): void
    {
        if ($familyId !== null) {
            abort_unless(
                FamilyMember::where(['family_id' => $familyId, 'user_id' => $request->user()->id, 'status' => 'accepted'])->exists(),
                403,
            );
        }
    }
}

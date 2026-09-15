<?php

namespace App\Actions;

use App\Enums\AccessLevel;
use App\Enums\BrandRole;
use App\Enums\PortalSection;
use App\Models\Client;
use App\Models\User;

/**
 * Bring a brand's members back under what its owners can actually grant.
 *
 * Privileges narrow going down the chain, but the chain is only checked at the
 * moment of granting — so when Breakfast lowers an owner afterwards, members
 * can be left holding more than that owner could have given them today. This
 * closes that gap by lowering them in the same write.
 *
 * Run it after any change to an owner's permissions. It is idempotent: on a
 * brand that is already consistent it changes nothing and returns 0.
 */
class EnforceBrandPermissionCeiling
{
    /** @return int How many members were lowered. */
    public function handle(Client $client): int
    {
        $ceiling = $this->ceilingFor($client);
        $lowered = 0;

        // ⚠️ The role is on the MEMBERSHIP, not on the account (ACC-01). The
        // same person can own one brand and be a member of another, so asking
        // users.role would clamp the wrong people in the wrong brand.
        $members = $client->users()
            ->wherePivot('role', BrandRole::Miembro->value)
            ->get();

        foreach ($members as $member) {
            $held = $this->mapOf($member);
            $permissions = $this->clamp($held, $ceiling);

            // Compare against the decoded map, not the raw column, so a member
            // who is already correct is not written to and does not count.
            if ($permissions !== $held) {
                $client->users()->updateExistingPivot($member->id, [
                    'permissions' => json_encode((object) $permissions),
                ]);
                $lowered++;
            }
        }

        return $lowered;
    }

    /**
     * The highest level any owner of this brand holds, per section.
     *
     * Maximum rather than minimum, and across all owners rather than one: a
     * member's grant is legitimate if *some* owner could have made it. With a
     * single owner — the normal case — this is just that owner's permissions.
     *
     * @return array<string, AccessLevel>
     */
    private function ceilingFor(Client $client): array
    {
        $owners = $client->users()
            ->wherePivot('role', BrandRole::Owner->value)
            ->get();

        $ceiling = [];

        foreach (PortalSection::grantable() as $section) {
            foreach ($owners as $owner) {
                // The brand is passed explicitly: an owner here may be a plain
                // member elsewhere, and the active brand is whichever tab they
                // happen to have open — nothing to do with this question.
                $level = $owner->grantCeiling($section, $client);

                if ($level === null) {
                    continue;
                }

                $held = $ceiling[$section->value] ?? null;

                if ($held === null || ! $held->covers($level)) {
                    $ceiling[$section->value] = $level;
                }
            }
        }

        return $ceiling;
    }

    /**
     * One membership's stored map, decoded.
     *
     * The pivot is not a model, so nothing casts the JSON on the way out — the
     * one real cost of moving the map off users, and it is paid here and in
     * User::permissionsIn() rather than at every call site.
     *
     * @return array<string, string>
     */
    private function mapOf(User $member): array
    {
        $stored = $member->pivot->permissions;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param  array<string, string>  $permissions
     * @param  array<string, AccessLevel>  $ceiling
     * @return array<string, string>
     */
    private function clamp(array $permissions, array $ceiling): array
    {
        $clamped = [];

        foreach ($permissions as $slug => $value) {
            $held = AccessLevel::tryFrom((string) $value);
            $cap = $ceiling[$slug] ?? null;

            // No owner holds it any more, so neither may the member.
            if ($held === null || $cap === null) {
                continue;
            }

            $clamped[$slug] = $cap->covers($held) ? $held->value : $cap->value;
        }

        return $clamped;
    }
}

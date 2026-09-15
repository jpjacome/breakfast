<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which brand is this request about? — ACC-02.
 *
 * THE ONE PLACE THAT DECIDES IT. Every portal screen used to read
 * $user->client, because there was only ever one answer; with several brands
 * per account the answer becomes a choice, and a choice made in fifteen places
 * is fifteen chances for two parts of one page to disagree about which brand
 * the person is looking at.
 *
 * Registered as a singleton, so the resolution below runs once per request
 * rather than once per call site.
 */
class ActiveBrand
{
    /** Memoised per request, keyed by user id. false = resolved to nothing. */
    private array $resolved = [];

    private const SESSION_KEY = 'active_client_id';

    /**
     * The brand this user is working in, or null if they have none.
     *
     * Three steps, each one a narrowing that fails closed:
     *
     *   1. what they chose, IF it is still one of their brands;
     *   2. otherwise their first brand, by name, so the choice is stable
     *      between requests rather than whatever the database returns first;
     *   3. otherwise null.
     *
     * ⚠️ STEP 1 RE-CHECKS MEMBERSHIP EVERY REQUEST, and that is the point of
     * doing it this way rather than trusting the session. A session outlives a
     * permission change: if a brand owner removes somebody while they have the
     * portal open, the id sitting in their session must stop answering
     * immediately. Reading it back through the relation is what makes that
     * true without anyone having to remember to clear a session.
     *
     * ⚠️ Breakfast staff get null. They carry no brands — they reach every
     * brand through /admin instead — and a portal page that dereferences this
     * flatly is a 500 for them (CLAUDE.md §11).
     */
    public function for(User $user): ?Client
    {
        $key = $user->getKey();

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key] ?: null;
        }

        $brands = $this->options($user);

        $chosen = session(self::SESSION_KEY);

        $brand = ($chosen !== null ? $brands->firstWhere('id', (int) $chosen) : null)
            ?? $brands->first();

        // Stored as false rather than null so array_key_exists() above can tell
        // "resolved to nothing" from "not resolved yet" — a user with no brands
        // must not re-run the query on every call.
        $this->resolved[$key] = $brand ?: false;

        return $brand;
    }

    /**
     * Every brand this user may work in, in the order a picker should show
     * them.
     *
     * ⚠️ ARCHIVED BRANDS ARE ABSENT, and that is a mechanism rather than a
     * detail. Before multi-marca, User::accessTo() closed the portal by finding
     * client() null — the relation excludes soft-deleted rows — so archiving a
     * brand shut its portal and restoring gave everyone back exactly what they
     * had, because nothing had been taken away to put back. brands() keeps that
     * exclusion, so an archived brand simply drops out of the picker: a person
     * with two brands keeps the other one, and a person with only that one is
     * closed out exactly as they were before.
     */
    public function options(User $user): Collection
    {
        if ($user->isBreakfast()) {
            return collect();
        }

        return $user->relationLoaded('brands')
            ? $user->brands->sortBy('name')->values()
            : $user->brands()->orderBy('name')->get();
    }

    /**
     * Remember a choice. Refuses a brand that is not theirs rather than
     * storing it and letting for() quietly ignore it later — a switch that
     * silently does nothing is harder to diagnose than one that fails.
     */
    public function set(User $user, Client $client): bool
    {
        if (! $this->options($user)->contains('id', $client->id)) {
            return false;
        }

        session([self::SESSION_KEY => $client->id]);

        unset($this->resolved[$user->getKey()]);

        return true;
    }

    /** True when a picker is worth drawing at all. */
    public function hasChoice(User $user): bool
    {
        return $this->options($user)->count() > 1;
    }
}

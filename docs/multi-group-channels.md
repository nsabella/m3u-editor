# Multi-Group Channels & Per-Group Sorting

## Contents

- Branch Topology — which branch has these features and why they never merge to master
- Data Model — tags vs `group_id`, `taggables.sort` fallback chain, ID/UUID mismatch
- Code Paths — model layer, query layer, `buildSortedEmissionList()`, Xtream API, Filament UI
- SortService Interaction — bulk alpha sort & recount bypass `taggables.sort`
- Xtream Category Filtering Semantics — the untagged-channel fallback branch
- Auto-Sync Behavior — mode matrix and sort reset/preserve rules
- Invariants & Known Limitations
- Design Rationale — why one-EXTINF-per-group and pivot-primary ordering
- Testing — test files, helper recipes, infrastructure gotchas

## Overview

These two related features change how channels relate to groups inside **custom playlists**:

1. **Multi-Group Channels** — a single channel can belong to multiple groups (and a series to multiple categories). The channel is emitted once per group in M3U output and once per category in Xtream API output.
2. **Per-Group Sorting** — each channel-in-group pair has its own sort value, so a channel can appear at position 3 in "Sports" and position 10 in "News".

Both live entirely on the **Spatie Tags** layer (`tags` / `taggables` tables). They do **not** touch `channels.group_id`, which remains the source playlist's single group.

> **Audience note:** This document describes code on the `feature/group-sort` branch (see Branch Topology below — that branch is the superset containing both features). All file/line references were verified against it.

## Branch Topology

`feature/group-sort` is the **authoritative branch** for both features — it is the superset. The other branches are fully merged into it (ancestor commits) and exist only for history:

| Branch | Contents | Status |
|---|---|---|
| `feature/group-sort` | Multi-group + per-group sort, synced with upstream master | **Work here** |
| `feature/multi_group` | Multi-group only, no per-group sort | Ancestor of `feature/group-sort` — superseded |
| `multi_group_backup` | Early draft of per-group sort | Ancestor of `feature/group-sort` — superseded |
| `master` | Tracks the upstream `m3ue/master` | **Does not contain these features** |

**These docs only exist on `feature/group-sort`** (and `origin/feature/group-sort`). An agent landing on `master`, `feature/multi_group`, or `multi_group_backup` will not find this file — if the docs are missing, check out `feature/group-sort`.

This repo does **not** merge feature branches back to master. The workflow is: fetch upstream (`git fetch m3ue master`), merge `m3ue/master` into the feature branch, resolve conflicts. Features live indefinitely on their own branches in the `nsabella/m3u-editor` fork.

## Data Model

### Source groups vs. custom playlist groups

| Mechanism | Table | Scope |
|---|---|---|
| Source group | `channels.group_id` → `groups` | One per channel, set by import |
| Custom playlist group | `tags` + `taggables` (Spatie Tags) | Many per channel; tag `type` = custom playlist UUID |
| Custom playlist category (series) | same, but tag `type` = `{uuid}-category` | Series content |

### Key columns

- `tags.type` — stores the **custom playlist UUID** (string), scoping tags per playlist. Categories append `-category`.
- `tags.order_column` — orders the **groups themselves** in the Groups tab UI. Not a per-channel sort.
- `taggables.sort` — **per-group channel sort** (decimal 10,4, nullable). Added by migration `2026_07_30_000001_add_sort_to_taggables_table.php`; backfilled from the pivot by `2026_07_30_000002_backfill_taggables_sort_from_pivot.php`.
- `channel_custom_playlist.sort` / `.channel_number` — per-playlist shared sort and channel number. Used as fallback when `taggables.sort` is NULL.

### The sort fallback chain

```
taggables.sort  →  channel_custom_playlist.sort  →  channels.sort  →  PHP_INT_MAX
(per-group)        (per-playlist shared)             (global)
```

NULL means "not explicitly set, fall through." This is why the `taggables.sort` migration uses `nullable()` and **not** `default(0)` — `0` is truthy for `??` and would break the chain.

### ID vs UUID mismatch (critical gotcha)

`channel_custom_playlist.custom_playlist_id` is an **integer FK** to `custom_playlists.id`, but `tags.type` stores the **UUID string**. Any query bridging the two must go through `custom_playlists` (see the backfill migration for a working example). Raw queries that match `taggable_type`/`type` against the wrong form silently update zero rows.

Also: when inserting raw `taggables` rows for a CustomPlaylist, use `$playlist->getMorphClass()` (stores `custom_playlist`), not `CustomPlaylist::class` — the morph map alias differs. `Channel::class` has no alias issue.

## Code Paths

### Model layer — `app/Models/Channel.php` / `app/Models/Series.php`

Both override the `HasTags` trait's `tags()` relationship to expose the pivot column:

```php
public function tags(): MorphToMany
{
    return $this->morphToMany(Tag::class, 'taggable')
        ->withPivot('sort')
        ->ordered();
}
```

**Do not use `parent::tags()` for this** — trait overrides require redefining the relationship.

**Spatie's `attachTags()` / `syncTags()` strip pivot data.** To set `sort`, use Laravel's native `$model->tags()->attach($tagId, ['sort' => $value])`.

### Query layer — `getChannelQuery()` in `app/Http/Controllers/PlaylistGenerateController.php`

For custom contexts (`CustomPlaylist` or `PlaylistAlias` with `custom_playlist_id`):

- Eager loads all group tags: `$query->with(['tags' => fn ($q) => $q->where('type', $playlistUuid)->orderBy('order_column')])`. This is a separate batch query — no cartesian explosion.
- Retains a correlated subquery for `custom_group_name` (first tag, `LIMIT 1`) as the **no-tag fallback display name** and for SQL-level default ordering.
- SQL ordering: `COALESCE(channel_custom_playlist.sort, channels.sort)` → `channels.channel` → `channels.title`. The per-group ordering happens in PHP (below), not SQL.

### Emission layer — `buildSortedEmissionList()`

`PlaylistGenerateController::buildSortedEmissionList($channels, $playlistUuid)` (line ~826) is the **single shared helper** for both M3U and Xtream output. It:

1. Flattens channels × tags into `(channel, tag, sort, group_name, group_id)` entries — one entry per tag.
2. Channels with no tags emit once, falling back to `custom_group_name` (custom contexts) or `channels.group` (standard/merged).
3. Sorts entries by the fallback chain above, tiebroken by channel number then title.

Both controllers changed `cursor()` → `get()` to feed this (global ordering requires the full set in memory). The `response()->stream()` wrapper remains — output is still streamed after sorting. `hdhrLineup()` does **not** do multi-group (one entry per channel) and needs no changes.

Tag `name` values may be a JSON object (`{"en": "Sports"}`) or a plain string. Always use the `is_array()` / `json_last_error()` guard pattern before `array_key_first()`.

### Xtream API — `app/Http/Controllers/XtreamApiController.php`

`get_live_streams`, `get_vod_streams`, and `get_series` all use `buildSortedEmissionList()`. Per entry:

- `category_id` = the current tag ID (or `'all'` when no tags)
- `category_ids` = **all** tag IDs for the channel (empty array when no tags)
- Standard playlists use the actual `group_id` for both fields

### Filament UI — `app/Filament/Resources/CustomPlaylists/RelationManagers/`

- **Group assignment** (Channels/Vod/Series RelationManagers): "Add to group" is a multi-select that **appends** via `attachTags()`; "remove from group" detaches only the selected tags. EPG cache (`EpgCacheService::clearForCustomPlaylistId()`) is cleared after every tag change.
- **Per-group tabs already existed**: tab 0 = All, tab N = Nth group tag.
- **Sort column** (`ChannelsRelationManager.php` ~lines 160–210): context-aware. On the "All" tab it reads/writes `channel_custom_playlist.sort` via `updateExistingPivot()`. On a group tab it reads/writes `taggables.sort` for the active tag.
- **`getActiveGroupTagId()`** (~line 582): maps Filament's numeric `$this->activeTab` string (`"1"`, `"2"`, …) to a tag ID. It **must replicate the exact query and ordering used by `getTabs()`** — divergent queries map indices to the wrong tags.
- **`ReordersCustomPlaylistPivotSort` trait** (Concerns/): drag-and-drop reorder. On a group tab it writes `taggables.sort`; on "All" it uses the existing pivot slot pattern. It reuses the "sort slots" approach: collect existing values, sort them, reassign to the new drag order (preserves fractional spacing rather than regenerating `10, 20, 30…`).

## Auto-Sync Behavior

`app/Jobs/AutoSyncGroupsToCustomPlaylist.php` (and `AddGroupsToCustomPlaylist.php`) attach tags mode-dependently:

| Mode | Tag behavior | Per-group sort behavior |
|---|---|---|
| `original` | **Replace** — `detachTags($playlistTags)` then attach | **Reset** — old `taggables.sort` rows are deleted; new rows get `sort = null` |
| `select` | **Append** | **Preserve** — existing rows untouched; only attaches if not already attached |
| `create` | **Append** (creates new tag first) | **Preserve** — same as select |

The select/create path uses an explicit `if (! $item->tags()->where('tags.id', $tag->id)->exists())` guard before `tags()->attach($tag->id, ['sort' => null])` — re-running the job must not clobber user-set sort values. Spatie's `attachTag()` is not used here for the same reason.

Other invariants:

- Pivot rows are created with `DB::table($pivotTable)->insertOrIgnore()` — preserves existing `channel_custom_playlist.sort`.
- Tags are attached to **both** the Channel and the CustomPlaylist (`$playlist->attachTag()`), required for `get_live_categories`.
- `EpgCacheService::clearForCustomPlaylistId()` runs at the end.
- `syncMode` can be `full_sync` (adds orphaned-pivot cleanup, ghost-tag pruning, soft-deleted-group config cleanup) or `incremental`.

## SortService Interaction — Bulk Alpha Sort & Recount

`app/Services/SortService.php` has two bulk operations used by the Filament bulk actions `recount_custom` and `sort_alpha_custom` in the RelationManagers:

- **`bulkSortAlphaCustomPlaylistChannels()`** (~line 583) — alphabetically reorders selected channels by writing sequential `ROW_NUMBER()` values into `channel_custom_playlist.sort` (dialect-specific SQL: MySQL `UPDATE...JOIN`, Postgres `UPDATE...FROM`, SQLite, plus a CASE fallback).
- **`bulkRecountCustomPlaylistChannels()`** (~line 703) — recalculates `channel_custom_playlist.channel_number` per playlist, ordering by `COALESCE(ccp.sort, c.sort), c.channel, c.id`.

**Both bypass `taggables.sort` entirely.** They are *not* tab-aware: running "Sort A–Z" or "Recount" while a group tab is active rewrites the **shared pivot sort**, which affects the channel's position in **every** group (per-group values are left untouched, but the fallback layer they rely on changes). There is no per-group alpha-sort or per-group recount.

If asked to make these tab-aware, follow the pattern already used by the sort column and reorder trait: detect the active tab via `getActiveGroupTagId()` and write `taggables.sort` (batched via a single dialect-aware statement over `taggables` joined to the selected tag) instead of the pivot. `EpgCacheService::clearForCustomPlaylistId()` must be called after either operation.

Also note these are raw `DB::statement()` operations guarded by driver detection — the project's PR review standards flag unguarded DDL/raw SQL, so preserve the driver matrix when modifying.

## Xtream Category Filtering Semantics

When an Xtream client requests a specific `category_id`, the filter for custom playlists is **not** a simple tag match. At `XtreamApiController.php` ~749 (live), ~934 (VOD), ~1081 (series):

```php
$channelsQuery->where(function ($query) use ($categoryId, $tagUuid) {
    // Channel has the requested tag in this playlist's scope
    $query->whereHas('tags', fn ($t) => $t->where('type', $tagUuid)->where('id', $categoryId));
})->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
    // OR channel has NO tags in this playlist but its source group_id matches
    $subQuery->whereDoesntHave('tags', fn ($t) => $t->where('type', $tagUuid))
             ->where('group_id', $categoryId);
});
```

The second branch is the untagged-channel fallback: a channel with no custom tags appears under the Xtream category matching its **source** `group_id`. Standard/merged playlists filter simply on `channels.group_id`.

This means: (1) a multi-group channel appears in **every** requested category it holds a tag for; (2) changing this filter changes which untagged channels appear per category — treat the fallback branch as behavior to preserve, not an oversight.

## Invariants & Known Limitations

1. **`channel_number` / `tvg-chno` is shared** across all group instances of a channel (single pivot value). Per-group auto-increment happens only when `auto_channel_increment` is enabled.
2. **`enabled` is global** — a disabled channel is off in every group and playlist. The pivot has no `enabled` column.
3. **Semicolon-split import is unrelated**: source M3U `group-title="Sports;News"` creates **two separate Channel records** at the import layer (`ProcessM3uImport.php`), each with its own `source_key`/`source_id`/`group_id`. It does not create tags.
4. **MergedPlaylists don't use tags** — each source channel keeps its own group; no dedup across playlists.
5. **Detaching a tag deletes its `taggables` row** — per-group sort for that pair is permanently lost (re-attach starts at NULL).
6. **Xtream `category_ids` appears on every entry** and contains all tag IDs, even for single-group channels.

## Design Rationale

Why these shapes were chosen — preserved here because the "obvious simpler" alternatives were evaluated and rejected:

**One `#EXTINF` per group (duplicated entries), not comma-separated `group-title`.** The alternative — one entry with `group-title="Sports,News"` — requires PHP-side aggregation of tag names into a single string, and players have inconsistent support for multi-value group-titles. Duplicated entries are the standard behavior for Xtream-compatible servers, players handle duplicate URLs fine, and each group instance gets its own ordering. The cost is an increased channel count in output, accepted deliberately. The same logic applies to Xtream output: the protocol is fundamentally single-`category_id` per stream, so one entry per category is the only client-compatible way to represent multi-group. (`category_ids` carries the full list as a bonus field, but the per-category entries are what clients actually consume.)

**Pivot sort as the primary SQL ORDER BY.** An earlier approach ordered channels by `MIN(t.order_column)` from the taggables/tags tables via a LEFT JOIN + GROUP BY. That was removed: the LEFT JOIN produced duplicate/broken ordering for multi-tag channels, and tag `order_column` orders the *groups themselves* in the UI, not channels within a group. The fix made `COALESCE(channel_custom_playlist.sort, channels.sort)` the primary key (SQL-level ordering for the one-row-per-channel query) and moved all multi-group emission and per-group ordering into PHP via `buildSortedEmissionList()` — where SQL cannot know about the per-tag expansion, PHP must own the ordering. Confirmed by the full multi-group test suite passing when the JOIN approach was dropped.

**Per-group sort on `taggables.sort` rather than a new table.** The `taggables` pivot already represents the channel↔group relationship; a separate `channel_tags` table would add joins and sync issues, and `tags.order_column` orders groups, not channels-within-groups. Adding a nullable column to Spatie's pivot table is safe — the relationship uses a standard `MorphToMany` with no custom pivot model, so `$tag->pivot->sort` works as soon as `withPivot('sort')` is declared.

## Testing

Test files (all passing on `feature/group-sort`):

| File | Coverage |
|---|---|
| `tests/Unit/MultiGroupChannelTest.php` | Core multi-tag unit behavior |
| `tests/Feature/MultiGroupM3uOutputTest.php` | M3U/Xtream multi-group emission, ordering, categories |
| `tests/Feature/MultiGroupEdgeCasesTest.php` | 0/1/20 tags, aliases, merged, detach, tag delete, auto-sync modes |
| `tests/Feature/MultiGroupPerGroupSortTest.php` | 12 per-group sort cases: fallback chain, tiebreakers, auto-sync sort reset/preserve, cross-group isolation |
| `tests/Feature/ChannelCustomGroupTest.php` | Updated: same-type multi-tag test |
| `tests/Feature/AutoSyncGroupsToCustomPlaylistTest.php` | Auto-sync sort handling |

Test infrastructure gotchas:

- `Event::fake()` (+ `Notification::fake()`) in `beforeEach()` — prevents Redis connection errors
- `$response->streamedContent()` for M3U stream responses (`getContent()` returns empty)
- Set `'group' => 'SourceGroup'` explicitly on channel factories — the no-tag fallback reads the `channels.group` **string column**, which faker randomizes
- Run scoped: `php artisan test --filter=MultiGroup` (never the full suite — see CLAUDE.md)

### Canonical test helper pattern

Each test file defines its own file-scoped helpers (they cannot be shared across Pest files without a composer autoload touch). The reference implementation is in `tests/Feature/MultiGroupPerGroupSortTest.php` (`perGroupSortCreateTag`, `perGroupSortCreateMultiGroupChannel`, `perGroupSortCreateChannelWithNullSort`); `MultiGroupM3uOutputTest.php` and `MultiGroupEdgeCasesTest.php` have `output*`/`edge*` variants of the same recipe. The pattern:

```php
// 1. Create tags via raw DB insert — bypasses Spatie's HasSlug trait, which
//    doesn't fire reliably in tests and leaves tags.slug NOT NULL violated.
function createTag(CustomPlaylist $playlist, string $name, ?int $orderColumn = null): int
{
    $existing = \DB::table('tags')->where('slug', Str::slug($name))->where('type', $playlist->uuid)->first();
    if ($existing) {
        return (int) $existing->id;
    }
    return \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => $name]),
        'slug' => Str::slug($name),
        'type' => $playlist->uuid,          // UUID string, NOT the integer ID
        'order_column' => $orderColumn ?? 999,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// 2. Create the channel: source Playlist FK is required on both Group and Channel.
// 3. Attach the pivot row via the relationship (no model events fired this way).
// 4. Wipe any existing taggables, then attach raw — attachOrIgnore so re-runs don't duplicate.
// 5. Attach the tag to BOTH the Channel AND the CustomPlaylist.
foreach ($tagSorts as $tagName => $sortValue) {
    $tagId = createTag($customPlaylist, $tagName);
    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => $tagId,
        'taggable_type' => Channel::class,        // Channel has no morph alias — class name is correct
        'taggable_id' => $channel->id,
        'sort' => $sortValue,                     // the per-group sort under test
    ]);
    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => $tagId,
        'taggable_type' => $customPlaylist->getMorphClass(),  // → 'custom_playlist', NOT CustomPlaylist::class
        'taggable_id' => $customPlaylist->id,
    ]);
}
```

Why raw DB instead of Spatie's API: `Tag::create()` needs a manually-supplied slug (HasSlug skips in tests), `attachTags()` strips pivot data (can't set `sort`), and `findOrCreate()` has side effects. Step 5 exists because `get_live_categories` queries `$playlist->groupTags()` — without the playlist-side row, Xtream categories return only "All".

## Full Suite Comparison (Aug 2026)

Master vs `feature/group-sort`, complete suite in the dev test container:

| Metric | Master | feature/group-sort |
|---|---|---|
| Failed | 586 | 586 (all Redis `Connection refused` — environment, not code) |
| Passed | 1,788 | 1,822 (+34) |
| Assertions | 6,728 | 6,948 (+220) |

No new failures introduced by the feature.

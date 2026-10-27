# Multi-Group Channels & Per-Group Sorting

## Overview

These two related features change how channels relate to groups inside **custom playlists**:

1. **Multi-Group Channels** — a single channel can belong to multiple groups (and a series to multiple categories). The channel is emitted once per group in M3U output and once per category in Xtream API output.
2. **Per-Group Sorting** — each channel-in-group pair has its own sort value, so a channel can appear at position 3 in "Sports" and position 10 in "News".

Both live entirely on the **Spatie Tags** layer (`tags` / `taggables` tables). They do **not** touch `channels.group_id`, which remains the source playlist's single group.

> **Audience note:** This document describes code on the `feature/group-sort` branch. All file/line references were verified against that branch.

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

## Invariants & Known Limitations

1. **`channel_number` / `tvg-chno` is shared** across all group instances of a channel (single pivot value). Per-group auto-increment happens only when `auto_channel_increment` is enabled.
2. **`enabled` is global** — a disabled channel is off in every group and playlist. The pivot has no `enabled` column.
3. **Semicolon-split import is unrelated**: source M3U `group-title="Sports;News"` creates **two separate Channel records** at the import layer (`ProcessM3uImport.php`), each with its own `source_key`/`source_id`/`group_id`. It does not create tags.
4. **MergedPlaylists don't use tags** — each source channel keeps its own group; no dedup across playlists.
5. **Detaching a tag deletes its `taggables` row** — per-group sort for that pair is permanently lost (re-attach starts at NULL).
6. **Xtream `category_ids` appears on every entry** and contains all tag IDs, even for single-group channels.

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

Test infrastructure gotchas (full list in `research/docker-testing-guide.md` upstream):

- `Event::fake()` in `beforeEach()` — prevents Redis connection errors
- `$response->streamedContent()` for M3U stream responses (`getContent()` returns empty)
- Manual tag slugs (`Str::slug(...)`) — Spatie's HasSlug doesn't fire in tests
- Set `'group' => 'SourceGroup'` explicitly on channel factories — the no-tag fallback reads the `channels.group` **string column**, which faker randomizes
- Run scoped: `php artisan test --filter=MultiGroup` (never the full suite — see CLAUDE.md)

## Full Suite Comparison (Aug 2026)

Master vs `feature/group-sort`, complete suite in the dev test container:

| Metric | Master | feature/group-sort |
|---|---|---|
| Failed | 586 | 586 (all Redis `Connection refused` — environment, not code) |
| Passed | 1,788 | 1,822 (+34) |
| Assertions | 6,728 | 6,948 (+220) |

No new failures introduced by the feature.

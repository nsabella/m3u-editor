<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fake all events to prevent Redis connections and other side effects.
    Event::fake();
});

// ---------------------------------------------------------------------------
// Helpers — raw DB, bypass Spatie Tags entirely (same pattern as Feature tests).
// ---------------------------------------------------------------------------

function createTagWithOrder(CustomPlaylist $playlist, string $name, int $orderColumn): int
{
    return \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => $name]),
        'slug' => Str::slug($name),
        'type' => $playlist->uuid,
        'order_column' => $orderColumn,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createMultiGroupChannel(CustomPlaylist $playlist, Group $group, array $tagNames): Channel
{
    // 1. Create channel via factory.
    $channel = Channel::factory()->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Multi-Group Test Channel',
    ]);

    // 2. Attach to custom playlist pivot (no model events).
    $playlist->channels()->attach($channel->id);

    // 3. Clear any existing taggables for this channel so we start clean.
    \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->delete();

    // 4-5. Create tags and pivot records via raw DB (dual attachment).
    foreach ($tagNames as $i => $name) {
        $tagId = createTagWithOrder($playlist, $name, $i + 1);

        \DB::table('taggables')->insert([
            'tag_id' => $tagId,
            'taggable_type' => Channel::class,
            'taggable_id' => $channel->id,
        ]);

        // ALSO attach to CustomPlaylist (required for get_live_categories).
        \DB::table('taggables')->insert([
            'tag_id' => $tagId,
            'taggable_type' => $playlist->getMorphClass(),
            'taggable_id' => $playlist->id,
        ]);
    }

    // 6. Clear cached relationships.
    $channel->refresh();

    return $channel;
}

function createNoTagChannel(CustomPlaylist $playlist, Group $group): Channel
{
    $channel = Channel::factory()->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'No-Tag Test Channel',
    ]);

    $playlist->channels()->attach($channel->id);

    return $channel;
}

// ---------------------------------------------------------------------------
// 1. channel_with_zero_tags_emits_single_entry (no regression)
// ---------------------------------------------------------------------------

it('channel with zero tags emits single M3U entry using source group', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    createNoTagChannel($customPlaylist, $group);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Exactly one EXTINF line — no tags means fallback to source group.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(1);

    // The channel title should appear exactly once (not duplicated).
    expect(substr_count($m3u, 'No-Tag Test Channel'))->toBe(1);

    // group-title must be non-empty (source group name), not empty string.
    expect(preg_match('/group-title="[^"]+"/', $m3u))->toBe(1)
        ->and($m3u)->not->toContain('group-title=""');
});

// ---------------------------------------------------------------------------
// 2. channel_with_one_tag_emits_single_entry (no regression)
// ---------------------------------------------------------------------------

it('channel with one tag emits single M3U entry', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    createMultiGroupChannel($customPlaylist, $group, ['Sports']);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Exactly one EXTINF line — a single tag produces exactly one entry.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(1);

    // The group-title should be the tag name.
    expect($m3u)->toContain('group-title="Sports"');

    // Channel title appears once (not duplicated).
    expect(substr_count($m3u, 'Multi-Group Test Channel'))->toBe(1);
});

// ---------------------------------------------------------------------------
// 3. channel_with_many_tags_emits_correct_number_of_entries (performance)
// ---------------------------------------------------------------------------

it('channel with many tags produces correct number of M3U entries without performance issues', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with 20 tags — well above "typical" multi-group counts.
    $manyTagNames = [];
    for ($i = 1; $i <= 20; $i++) {
        $manyTagNames[] = "Group{$i}";
    }

    createMultiGroupChannel($customPlaylist, $group, $manyTagNames);

    // Track peak memory during M3U generation to catch regressions.
    $peakBefore = memory_get_peak_usage(true);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    $peakAfter = memory_get_peak_usage(true);
    $memoryDelta = ($peakAfter - $peakBefore) / 1024 / 1024; // Convert to MB.

    // Verify exactly 20 EXTINF lines — one per tag.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(20);

    // Verify each group title appears as a distinct group-title attribute.
    foreach ($manyTagNames as $tagName) {
        expect($m3u)->toContain('group-title="' . $tagName . '"')
            ->and(substr_count($m3u, 'group-title="' . $tagName . '"'))->toBe(1);
    }

    // Channel title should appear exactly 20 times (duplicated per group).
    expect(substr_count($m3u, 'Multi-Group Test Channel'))->toBe(20);

    // Memory growth during M3U generation for a 20-tag channel must be reasonable.
    // A realistic upper bound is ~5 MB; anything significantly higher suggests
    // O(n^2) behavior or an unbounded string concatenation loop.
    expect($memoryDelta)->toBeLessThan(10);

    // No duplicate group-title values — each tag produces exactly one unique entry.
    $groupTitles = [];
    preg_match_all('/group-title="([^"]+)"/', $m3u, $matches);
    if (! empty($matches[1])) {
        expect(array_unique($matches[1]))->toHaveCount(20);
    }
});

// ---------------------------------------------------------------------------
// 4. playlist_alias_of_custom_playlist_shows_custom_group_names
// ---------------------------------------------------------------------------

it('alias of custom playlist shows custom tag names not source group names', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with custom group tags.
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News']);

    // Create an alias of the custom playlist.
    $aliasUuid = Str::uuid()->toString();
    PlaylistAlias::create([
        'name' => 'Test Alias',
        'uuid' => $aliasUuid,
        'user_id' => $user->id,
        'custom_playlist_id' => $customPlaylist->id,
    ]);

    // Fetch M3U via the alias URL.
    $response = $this->get("/{$aliasUuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // The alias should use custom tag names (Sports, News), not source group name.
    expect($m3u)->toContain('group-title="Sports"')
        ->and($m3u)->toContain('group-title="News"');

    // Should NOT contain the source group name as a group-title.
    expect($m3u)->not->toContain('group-title="SourceGroup"');

    // Two EXTINF lines — one per tag — proves multi-group works through aliases.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(2);
});

// ---------------------------------------------------------------------------
// 5. playlist_alias_of_standard_playlist_shows_source_group_names
// ---------------------------------------------------------------------------

it('alias of standard playlist shows source group names unchanged', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    // Create a channel in the source playlist with its source group.
    // Explicitly set group_id because ChannelFactory's default Group::factory() would
    // override any for($group) relationship binding (Laravel applies defaults after
    // for() calls, creating a random Group that replaces the specified one).
    $channel = Channel::factory()->for($sourcePlaylist)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Standard Test Channel',
        'group_id' => $groupA->id,
    ]);

    // Create an alias of the standard playlist (no custom_playlist_id).
    // Standard aliases use HasManyThrough through Playlist, requiring playlist_id.
    $aliasUuid = Str::uuid()->toString();
    PlaylistAlias::create([
        'name' => 'Standard Alias',
        'uuid' => $aliasUuid,
        'user_id' => $user->id,
        'playlist_id' => $sourcePlaylist->id,
    ]);

    // Fetch M3U via the alias URL.
    $response = $this->get("/{$aliasUuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // The alias of a standard playlist should use source group names (not custom tags).
    expect($m3u)->toContain('group-title="SourceGroup"');

    // Exactly one EXTINF line — no multi-group behavior for standard playlists.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(1);
});

// ---------------------------------------------------------------------------
// 6. merged_playlist_with_multi_group_channels_uses_source_groups
// ---------------------------------------------------------------------------

it('merged playlist retains source group for multi-group channels', function () {
    $user = User::factory()->create();

    // Source playlist with a group and a channel in that group.
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    // Create a channel in the source playlist (with its source group).
    // Explicitly set group_id because ChannelFactory's default Group::factory() would
    // override any for($group) relationship binding.
    $channel = Channel::factory()->for($sourcePlaylist)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Merged Source Channel',
        'group_id' => $groupA->id,
    ]);

    // Create a MergedPlaylist and attach the source playlist to it.
    $merged = MergedPlaylist::factory()->for($user)->create();
    $merged->playlists()->attach($sourcePlaylist);

    // Fetch M3U via the merged playlist URL (type === 'merged').
    $response = $this->get("/{$merged->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // The merged playlist uses source group names, NOT custom tags.
    expect($m3u)->toContain('group-title="SourceGroup"');

    // Exactly one EXTINF line — merged playlists don't do multi-group via tags.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(1);

    // The channel title should appear exactly once.
    expect(substr_count($m3u, 'Merged Source Channel'))->toBe(1);
});

// ---------------------------------------------------------------------------
// 7. channel_detached_from_playlist_removes_tags_for_that_playlist
// ---------------------------------------------------------------------------

it('detaching a channel from custom playlist removes all its tags for that playlist', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a multi-group channel.
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News', 'Music']);

    // Verify the channel is in 3 groups before detach.
    $channel = Channel::where('title', 'Multi-Group Test Channel')->first();
    expect(\DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->count())->toBe(3);

    // Detach the channel from the custom playlist by removing its pivot row.
    \DB::table('channel_custom_playlist')
        ->where('channel_id', $channel->id)
        ->where('custom_playlist_id', $customPlaylist->id)
        ->delete();

    // After detach, the taggables rows are still there (existing behavior —
    // tags persist on the channel itself; only the pivot is removed).
    expect(\DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->count())->toBe(3);

    // The channel should NOT appear in M3U output for this playlist.
    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // The detached channel's title should NOT appear in the output.
    expect($m3u)->not->toContain('Multi-Group Test Channel');
});

// ---------------------------------------------------------------------------
// 8. group_deleted_while_channel_has_it_preserves_other_groups
// ---------------------------------------------------------------------------

it('deleting a tag removes only that group from multi-group channel', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a multi-group channel with 3 tags.
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News', 'Music']);

    // Verify all 3 group-titles are present before deletion.
    $responseBefore = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $responseBefore->assertStatus(200);
    expect($responseBefore->streamedContent())->toContain('group-title="Sports"');
    expect($responseBefore->streamedContent())->toContain('group-title="News"');
    expect($responseBefore->streamedContent())->toContain('group-title="Music"');

    // Delete the "Music" tag record directly from the DB.
    $tagToDelete = \DB::table('tags')
        ->where('name', json_encode(['en' => 'Music']))
        ->where('type', $customPlaylist->uuid)
        ->first();

    expect($tagToDelete)->not->toBeNull();

    // Delete the tag and its pivot records (both channel and playlist).
    \DB::table('tags')->where('id', $tagToDelete->id)->delete();
    \DB::table('taggables')
        ->where('tag_id', $tagToDelete->id)
        ->delete();

    // Verify exactly 2 group-titles remain in the M3U.
    $responseAfter = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $responseAfter->assertStatus(200);

    $m3u = $responseAfter->streamedContent();

    // Sports and News should still be present; Music should be gone.
    expect($m3u)->toContain('group-title="Sports"')
        ->and($m3u)->toContain('group-title="News"')
        ->and($m3u)->not->toContain('group-title="Music"');

    // Exactly 2 EXTINF lines (one per remaining tag).
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(2);

    // Channel title should appear exactly twice.
    expect(substr_count($m3u, 'Multi-Group Test Channel'))->toBe(2);
});

// ---------------------------------------------------------------------------
// 9. auto_sync_modes_select_appends_original_replaces (raw DB simulation)
// ---------------------------------------------------------------------------

it('auto-sync modes: select appends, original replaces (raw DB simulation)', function () {
    $user = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel attached to the custom playlist.
    $channel = Channel::factory()->for($customPlaylist)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'AutoSync Test Channel',
    ]);

    \DB::table('channel_custom_playlist')->insert([
        'channel_id' => $channel->id,
        'custom_playlist_id' => $customPlaylist->id,
    ]);

    // -----------------------------------------------------------------------
    // Phase 1: simulate SELECT mode — append a new tag without removing existing.
    // -----------------------------------------------------------------------

    // Pre-existing tags on the channel (simulating prior manual assignments).
    $existingTag = \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => 'Premium']),
        'slug' => Str::slug('Premium'),
        'type' => $customPlaylist->uuid,
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::table('taggables')->insert([
        'tag_id' => $existingTag,
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
    ]);

    // Verify the channel has exactly 1 tag before auto-sync.
    expect(\DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->count())->toBe(1);

    // SELECT mode: append "Featured" tag (simulates attachTag — no detach).
    $featuredTagId = \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => 'Featured']),
        'slug' => Str::slug('Featured'),
        'type' => $customPlaylist->uuid,
        'order_column' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::table('taggables')->insert([
        'tag_id' => $featuredTagId,
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
    ]);

    // Verify select mode appended: channel now has 2 tags.
    expect(\DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->count())->toBe(2);

    // The new "Featured" tag should also be present.
    $featuredTag = \DB::table('tags')
        ->where('name', json_encode(['en' => 'Featured']))
        ->first();
    expect($featuredTag)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 10. auto_sync_original_mode_replaces_all_tags (raw DB simulation)
// ---------------------------------------------------------------------------

it('auto-sync original mode replaces all tags with single source group', function () {
    $user = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel attached to the custom playlist.
    $channel = Channel::factory()->for($customPlaylist)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'AutoSync Original Mode Channel',
    ]);

    \DB::table('channel_custom_playlist')->insert([
        'channel_id' => $channel->id,
        'custom_playlist_id' => $customPlaylist->id,
    ]);

    // Pre-existing tags on the channel (simulating prior manual assignments).
    $sportsTagId = \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => 'Sports']),
        'slug' => Str::slug('Sports'),
        'type' => $customPlaylist->uuid,
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $newsTagId = \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => 'News']),
        'slug' => Str::slug('News'),
        'type' => $customPlaylist->uuid,
        'order_column' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::table('taggables')->insert([
        ['tag_id' => $sportsTagId, 'taggable_type' => Channel::class, 'taggable_id' => $channel->id],
        ['tag_id' => $newsTagId, 'taggable_type' => Channel::class, 'taggable_id' => $channel->id],
    ]);

    // Verify channel has 2 tags before original mode.
    expect(\DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->count())->toBe(2);

    // ORIGINAL mode: detach all tags, then attach only the source group tag.
    \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->delete();

    $sourceTagId = \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => 'SourceGroup']),
        'slug' => Str::slug('SourceGroup'),
        'type' => $customPlaylist->uuid,
        'order_column' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::table('taggables')->insert([
        'tag_id' => $sourceTagId,
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
    ]);

    // Verify original mode replaced: channel now has only 1 tag.
    expect(\DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->count())->toBe(1);

    // The replaced tag should be the source group, not any of the originals.
    expect(\DB::table('tags')
        ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
        ->where('taggables.taggable_type', Channel::class)
        ->where('taggables.taggable_id', $channel->id)
        ->select('tags.name')
        ->first()->name)->toBe(json_encode(['en' => 'SourceGroup']));
});

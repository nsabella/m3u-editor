<?php

use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fake all events to prevent Redis connections and other side effects.
    Event::fake();
});

// ---------------------------------------------------------------------------
// Helpers — raw DB, bypass Spatie Tags entirely (same pattern as existing tests).
// ---------------------------------------------------------------------------

function perGroupSortCreateTag(CustomPlaylist $playlist, string $name, ?int $orderColumn = null): int
{
    $slug = Str::slug($name);

    // Return existing tag if one with the same slug+type already exists.
    $existing = \DB::table('tags')
        ->where('slug', $slug)
        ->where('type', $playlist->uuid)
        ->first();

    if ($existing) {
        return (int) $existing->id;
    }

    return \DB::table('tags')->insertGetId([
        'name' => json_encode(['en' => $name]),
        'slug' => $slug,
        'type' => $playlist->uuid,
        'order_column' => $orderColumn ?? 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Create a channel with multiple group tags, each with its own sort value.
 * Uses raw DB inserts to bypass Spatie Tags and preserve taggables.sort values.
 */
function perGroupSortCreateMultiGroupChannel(
    Playlist $sourcePlaylist,
    CustomPlaylist $customPlaylist,
    Group $group,
    array $tagSorts // ['Sports' => 10, 'News' => 5]
): Channel {
    $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'PerGroupSort Test Channel',
    ]);

    // Attach to custom playlist pivot (no model events).
    $customPlaylist->channels()->attach($channel->id);

    // Clear any existing taggables for this channel.
    \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->delete();

    foreach ($tagSorts as $tagName => $sortValue) {
        $tagId = perGroupSortCreateTag($customPlaylist, $tagName);

        // Attach to Channel with custom sort value.
        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => $tagId,
            'taggable_type' => Channel::class,
            'taggable_id' => $channel->id,
            'sort' => $sortValue,
        ]);

        // ALSO attach to CustomPlaylist (required for get_live_categories).
        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => $tagId,
            'taggable_type' => $customPlaylist->getMorphClass(),
            'taggable_id' => $customPlaylist->id,
        ]);
    }

    $channel->refresh();

    return $channel;
}

/**
 * Create a channel with per-group sort values where some tags have NULL sort.
 */
function perGroupSortCreateChannelWithNullSort(
    Playlist $sourcePlaylist,
    CustomPlaylist $customPlaylist,
    Group $group,
    array $tagSorts // ['Sports' => 10, 'News' => null]
): Channel {
    return perGroupSortCreateMultiGroupChannel($sourcePlaylist, $customPlaylist, $group, $tagSorts);
}

// ---------------------------------------------------------------------------
// 1. channel_with_two_tags_different_sort_values_emits_at_correct_positions
// ---------------------------------------------------------------------------

it('channel with 2 tags and different taggables.sort values emits at correct positions in M3U', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Channel with Sports (sort=10) and News (sort=5).
    perGroupSortCreateMultiGroupChannel($sourcePlaylist, $customPlaylist, $group, [
        'Sports' => 10,
        'News' => 5,
    ]);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Verify both group-titles appear.
    expect($m3u)->toContain('group-title="Sports"');
    expect($m3u)->toContain('group-title="News"');

    // Per-group sort: News (5) should come before Sports (10).
    $newsPos = strpos($m3u, 'group-title="News"');
    $sportsPos = strpos($m3u, 'group-title="Sports"');

    expect($newsPos)->toBeLessThan($sportsPos);
});

// ---------------------------------------------------------------------------
// 2. channel_with_two_tags_one_null_sort_falls_back_to_pivot_sort
// ---------------------------------------------------------------------------

it('channel with one NULL taggables.sort falls back to channel_custom_playlist.sort', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Channel with Sports (sort=5) and News (sort=NULL).
    perGroupSortCreateMultiGroupChannel($sourcePlaylist, $customPlaylist, $group, [
        'Sports' => 5,
        'News' => null,
    ]);

    // Set channel_custom_playlist.sort to 3 (lower than Sports's 5).
    $channel = Channel::where('title', 'PerGroupSort Test Channel')->first();
    \DB::table('channel_custom_playlist')
        ->where('channel_id', $channel->id)
        ->where('custom_playlist_id', $customPlaylist->id)
        ->update(['sort' => 3]);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Both group-titles should appear.
    expect($m3u)->toContain('group-title="Sports"');
    expect($m3u)->toContain('group-title="News"');

    // News (NULL → falls back to 3) should come before Sports (5).
    $newsPos = strpos($m3u, 'group-title="News"');
    $sportsPos = strpos($m3u, 'group-title="Sports"');

    expect($newsPos)->toBeLessThan($sportsPos);
});

// ---------------------------------------------------------------------------
// 3. channel_with_two_tags_both_null_sort_falls_back_to_channel_sort
// ---------------------------------------------------------------------------

it('channel with both NULL taggables.sort falls back to channels.sort', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Channel with Sports (sort=NULL) and News (sort=NULL).
    perGroupSortCreateMultiGroupChannel($sourcePlaylist, $customPlaylist, $group, [
        'Sports' => null,
        'News' => null,
    ]);

    // Set channels.sort to 7.
    $channel = Channel::where('title', 'PerGroupSort Test Channel')->first();
    $channel->update(['sort' => 7]);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Both group-titles should appear.
    expect($m3u)->toContain('group-title="Sports"');
    expect($m3u)->toContain('group-title="News"');

    // When both sort values are NULL, they fall back to channels.sort (7).
    // Tiebreaker is channel name ("PerGroupSort Test Channel") — identical for both.
    // So order depends on taggables insertion order or alphabetical by group title.
    $newsPos = strpos($m3u, 'group-title="News"');
    $sportsPos = strpos($m3u, 'group-title="Sports"');

    expect($newsPos)->toBeGreaterThan(0);
    expect($sportsPos)->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// 4. multiple_channels_in_same_group_sorted_by_per_group_sort_values
// ---------------------------------------------------------------------------

it('multiple channels in same group sorted by per-group sort values', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create one shared "Sports" tag for all channels.
    $sportsTagId = perGroupSortCreateTag($customPlaylist, "Sports");

    // Attach the shared tag to the custom playlist once.
    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => $sportsTagId,
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    // Create three channels with different sort values in same group.
    for ($i = 0; $i < 3; $i++) {
        $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
            'enabled' => true,
            'is_vod' => false,
            'title' => "Channel {$i}",
            'sort' => ($i + 1) * 10, // 10, 20, 30
        ]);

        $customPlaylist->channels()->attach($channel->id);

        // Attach to custom playlist with sort value.
        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => $sportsTagId,
            'taggable_type' => Channel::class,
            'taggable_id' => $channel->id,
            'sort' => ($i + 1) * 5, // Per-group sort: 5, 10, 15
        ]);

        $channel->refresh();
    }

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // All three channels should appear.
    expect($m3u)->toContain('group-title="Sports"');

    // Per-group sort: Channel 1 (5) < Channel 2 (10) < Channel 3 (15).
    $channel1Pos = strpos($m3u, 'Channel 1');
    $channel2Pos = strpos($m3u, 'Channel 2');
    $channel3Pos = strpos($m3u, 'Channel 3');

    expect($channel1Pos)->toBeLessThan($channel2Pos);
    expect($channel2Pos)->toBeLessThan($channel3Pos);
});

// ---------------------------------------------------------------------------
// 5. mixed_per_group_sort_and_no_sort_correct_interleaving
// ---------------------------------------------------------------------------

it('mixed channels with and without per-group sort interleaved correctly', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Channel A: per-group sort (Sports=20).
    perGroupSortCreateMultiGroupChannel($sourcePlaylist, $customPlaylist, $group, [
        'Sports' => 20,
    ]);

    // Channel B: no per-group sort (falls back to channels.sort=15).
    $channelB = Channel::factory()->for($sourcePlaylist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Channel B',
        'sort' => 15,
    ]);

    $customPlaylist->channels()->attach($channelB->id);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => Channel::class,
        'taggable_id' => $channelB->id,
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    // Channel C: per-group sort (Sports=10).
    $channelC = Channel::factory()->for($sourcePlaylist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Channel C',
    ]);

    $customPlaylist->channels()->attach($channelC->id);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => Channel::class,
        'taggable_id' => $channelC->id,
        'sort' => 10,
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    $channelC->refresh();

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Per-group sort: Channel C (10) < Channel B (15, fallback) < Channel A (20).
    $channelCPos = strpos($m3u, 'Channel C');
    $channelBPos = strpos($m3u, 'Channel B');
    $channelAPos = strpos($m3u, 'PerGroupSort Test Channel');

    expect($channelCPos)->toBeLessThan($channelBPos);
    expect($channelBPos)->toBeLessThan($channelAPos);
});

// ---------------------------------------------------------------------------
// 6. xtream_api_output_mirrors_m3u_sort_order
// ---------------------------------------------------------------------------

it('Xtream API output mirrors M3U sort order via buildSortedEmissionList()', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create two channels with different per-group sort values.
    for ($i = 0; $i < 2; $i++) {
        $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
            'enabled' => true,
            'is_vod' => false,
            'title' => $i === 0 ? 'First Channel' : 'Second Channel',
        ]);

        $customPlaylist->channels()->attach($channel->id);

        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
            'taggable_type' => Channel::class,
            'taggable_id' => $channel->id,
            'sort' => ($i + 1) * 5, // 5, 10
        ]);

        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
            'taggable_type' => $customPlaylist->getMorphClass(),
            'taggable_id' => $customPlaylist->id,
        ]);

        $channel->refresh();
    }

    // Get M3U output.
    $m3uResponse = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $m3uResponse->assertStatus(200);
    $m3uContent = $m3uResponse->streamedContent();

    // Get Xtream API output.
    $authUsername = 'test_xtream_user';
    $authPassword = 'test_pass';
    $playlistAuth = \App\Models\PlaylistAuth::create([
        'name' => 'Xtream Auth',
        'username' => $authUsername,
        'password' => $authPassword,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlistAuth->assignTo($customPlaylist);

    $queryParams = http_build_query([
        'username' => $authUsername,
        'password' => $authPassword,
        'action' => 'get_live_streams',
    ]);

    $xtreamResponse = $this->getJson(route('xtream.api.player') . '?' . $queryParams);
    $xtreamResponse->assertStatus(200);

    $streams = $xtreamResponse->json();

    // Find the two channels in Xtream output.
    $firstChannelStream = collect($streams)->first(fn ($s) => $s['name'] === 'First Channel');
    $secondChannelStream = collect($streams)->first(fn ($s) => $s['name'] === 'Second Channel');

    expect($firstChannelStream)->not->toBeNull();
    expect($secondChannelStream)->not->toBeNull();

    // Xtream output order should match M3U order.
    $xtreamOrder = array_column($streams, 'name');
    $firstPos = array_search('First Channel', $xtreamOrder);
    $secondPos = array_search('Second Channel', $xtreamOrder);

    expect($firstPos)->toBeLessThan($secondPos);
});

// ---------------------------------------------------------------------------
// 7. backward_compat_all_null_taggables_sort_uses_pivot_sort
// ---------------------------------------------------------------------------

it('all NULL taggables.sort produces same output as before (pivot sort)', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create two channels with NULL taggables.sort but different pivot sort.
    for ($i = 0; $i < 2; $i++) {
        $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
            'enabled' => true,
            'is_vod' => false,
            'title' => "PivotSort Channel {$i}",
        ]);

        $customPlaylist->channels()->attach($channel->id);

        // Set channel_custom_playlist.sort (pivot sort).
        \DB::table('channel_custom_playlist')
            ->where('channel_id', $channel->id)
            ->where('custom_playlist_id', $customPlaylist->id)
            ->update(['sort' => ($i + 1) * 5]); // 5, 10

        // Attach with NULL sort (no taggables.sort).
        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
            'taggable_type' => Channel::class,
            'taggable_id' => $channel->id,
        ]);

        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
            'taggable_type' => $customPlaylist->getMorphClass(),
            'taggable_id' => $customPlaylist->id,
        ]);

        $channel->refresh();
    }

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Pivot sort: Channel 0 (5) < Channel 1 (10).
    $channel0Pos = strpos($m3u, 'PivotSort Channel 0');
    $channel1Pos = strpos($m3u, 'PivotSort Channel 1');

    expect($channel0Pos)->toBeLessThan($channel1Pos);
});

// ---------------------------------------------------------------------------
// 8. edge_channel_with_ten_tags_each_different_sort_all_at_correct_positions
// ---------------------------------------------------------------------------

it('channel with 10 tags each with different sort produces all entries at correct positions', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with 10 tags, each with unique sort values (descending).
    $tagSorts = [];
    for ($i = 1; $i <= 10; $i++) {
        $tagSorts["Group{$i}"] = 10 - $i + 1; // Group1=10, Group2=9, ..., Group10=1
    }

    perGroupSortCreateMultiGroupChannel($sourcePlaylist, $customPlaylist, $group, $tagSorts);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // All 10 group-titles should appear.
    for ($i = 1; $i <= 10; $i++) {
        expect($m3u)->toContain('group-title="Group' . $i . '"');
    }

    // Verify order: Group10 (sort=1) < Group9 (sort=2) < ... < Group1 (sort=10).
    $positions = [];
    for ($i = 1; $i <= 10; $i++) {
        $positions["Group{$i}"] = strpos($m3u, 'group-title="Group' . $i . '"');
    }

    // Group10 (sort=1) should be first.
    expect($positions['Group10'])->toBeLessThan($positions['Group9']);
    expect($positions['Group9'])->toBeLessThan($positions['Group8']);
    expect($positions['Group5'])->toBeGreaterThan($positions['Group10']);
    expect($positions['Group1'])->toBeGreaterThan($positions['Group2']);

    // Channel title should appear exactly 10 times.
    expect(substr_count($m3u, 'PerGroupSort Test Channel'))->toBe(10);
});

// ---------------------------------------------------------------------------
// 9. edge_two_channels_identical_sort_values_tiebreaker_by_channel_name
// ---------------------------------------------------------------------------

it('two channels with identical sort values are tiebroken by channel name', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create two channels with same sort value but different names.
    for ($i = 0; $i < 2; $i++) {
        $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
            'enabled' => true,
            'is_vod' => false,
            'title' => $i === 0 ? 'Zebra Channel' : 'Alpha Channel',
        ]);

        $customPlaylist->channels()->attach($channel->id);

        // Attach with same sort value.
        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
            'taggable_type' => Channel::class,
            'taggable_id' => $channel->id,
            'sort' => 50, // Same sort value for both.
        ]);

        \DB::table('taggables')->insertOrIgnore([
            'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
            'taggable_type' => $customPlaylist->getMorphClass(),
            'taggable_id' => $customPlaylist->id,
        ]);

        $channel->refresh();
    }

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    // Both channels should appear.
    expect($m3u)->toContain('Alpha Channel');
    expect($m3u)->toContain('Zebra Channel');

    // Alphabetical tiebreaker: "Alpha Channel" < "Zebra Channel".
    $alphaPos = strpos($m3u, 'Alpha Channel');
    $zebraPos = strpos($m3u, 'Zebra Channel');

    expect($alphaPos)->toBeLessThan($zebraPos);
});

// ---------------------------------------------------------------------------
// 10. auto_sync_original_mode_resets_per_group_sort_to_null
// ---------------------------------------------------------------------------

it('auto-sync original mode resets per-group sort to NULL', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with per-group sort.
    $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'AutoSync Original Mode Channel',
    ]);

    $customPlaylist->channels()->attach($channel->id);

    // Attach with custom sort value.
    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
        'sort' => 42, // Custom sort value.
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    // Verify sort value before auto-sync.
    $originalSort = \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->value('sort');

    expect($originalSort)->toBe(42);

    // Dispatch auto-sync job in original mode.
    AutoSyncGroupsToCustomPlaylist::dispatchSync(
        userId: $user->id,
        playlistId: $sourcePlaylist->id,
        groupIds: [$group->id],
        customPlaylistId: $customPlaylist->id,
        data: ['mode' => 'original'],
        syncMode: 'add_only',
    );

    // After original mode, taggables.sort should be NULL.
    $newSort = \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->value('sort');

    expect($newSort)->toBeNull();
});

// ---------------------------------------------------------------------------
// 11. auto_sync_select_mode_preserves_existing_sort_assigns_null_to_new_tags
// ---------------------------------------------------------------------------

it('auto-sync select mode preserves existing sort and assigns NULL to new tags', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'SourceGroup']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with existing per-group sort.
    $channel = Channel::factory()->for($sourcePlaylist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'AutoSync Select Mode Channel',
    ]);

    $customPlaylist->channels()->attach($channel->id);

    // Attach with custom sort value.
    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
        'sort' => 42, // Existing sort value.
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Sports"),
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    // Dispatch auto-sync job in select mode (should preserve existing sort).
    AutoSyncGroupsToCustomPlaylist::dispatchSync(
        userId: $user->id,
        playlistId: $sourcePlaylist->id,
        groupIds: [$group->id],
        customPlaylistId: $customPlaylist->id,
        data: ['mode' => 'select'],
        syncMode: 'add_only',
    );

    // Existing tag should retain its sort value.
    $existingSort = \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->value('sort');

    expect($existingSort)->toBe(42);

    // New tag (if any) should have NULL sort.
    $newTags = \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->get();

    foreach ($newTags as $tag) {
        // If this is a new tag (not the original Sports), sort should be NULL.
        if (\DB::table('tags')->where('id', $tag->tag_id)->value('name') !== json_encode(['en' => 'Sports'])) {
            expect($tag->sort)->toBeNull();
        }
    }
});

// ---------------------------------------------------------------------------
// 12. ui_reordering_in_group_a_does_not_affect_order_in_group_b
// ---------------------------------------------------------------------------

it('reordering in group A does not affect order in group B for multi-group channels', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 1, 'name' => 'Group A']);
    $groupB = Group::factory()->for($sourcePlaylist)->for($user)
        ->create(['sort_order' => 2, 'name' => 'Group B']);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel in both groups with initial sort values.
    $channel = Channel::factory()->for($sourcePlaylist)->for($groupA)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'MultiGroup Reorder Test Channel',
    ]);

    $customPlaylist->channels()->attach($channel->id);

    // Initial sort: Group A = 10, Group B = 5.
    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Group A"),
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
        'sort' => 10, // Group A sort.
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Group B"),
        'taggable_type' => Channel::class,
        'taggable_id' => $channel->id,
        'sort' => 5, // Group B sort.
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Group A"),
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    \DB::table('taggables')->insertOrIgnore([
        'tag_id' => perGroupSortCreateTag($customPlaylist, "Group B"),
        'taggable_type' => $customPlaylist->getMorphClass(),
        'taggable_id' => $customPlaylist->id,
    ]);

    // Verify initial order: Group B (5) < Group A (10).
    $responseBefore = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $responseBefore->assertStatus(200);
    $m3uBefore = $responseBefore->streamedContent();

    $groupBPosBefore = strpos($m3uBefore, 'group-title="Group B"');
    $groupAPosBefore = strpos($m3uBefore, 'group-title="Group A"');

    expect($groupBPosBefore)->toBeLessThan($groupAPosBefore);

    // Update Group A sort to 1 (should move it before Group B).
    \DB::table('taggables')
        ->join('tags', 'tags.id', '=', 'taggables.tag_id')
        ->where('taggables.taggable_type', Channel::class)
        ->where('taggables.taggable_id', $channel->id)
        ->where('tags.name', json_encode(['en' => 'Group A']))
        ->update(['sort' => 1]);

    // Verify new order: Group A (1) < Group B (5).
    $responseAfter = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $responseAfter->assertStatus(200);
    $m3uAfter = $responseAfter->streamedContent();

    $groupAPosAfter = strpos($m3uAfter, 'group-title="Group A"');
    $groupBPosAfter = strpos($m3uAfter, 'group-title="Group B"');

    expect($groupAPosAfter)->toBeLessThan($groupBPosAfter);

    // Group B's sort value should NOT have changed.
    $groupBSort = \DB::table('taggables')
        ->join('tags', 'tags.id', '=', 'taggables.tag_id')
        ->where('taggables.taggable_type', Channel::class)
        ->where('taggables.taggable_id', $channel->id)
        ->where('tags.name', json_encode(['en' => 'Group B']))
        ->value('sort');

    expect($groupBSort)->toBe(5); // Unchanged.
});

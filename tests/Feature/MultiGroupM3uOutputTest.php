<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fake all events to prevent Redis connections and other side effects
    Event::fake();
});

// ---------------------------------------------------------------------------
// Helpers — raw DB, bypass Spatie Tags entirely
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

    // 3. Delete any existing taggables for this channel.
    \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->delete();

    // 4-5. Create tags and pivot records via raw DB.
    foreach ($tagNames as $i => $name) {
        $tagId = createTagWithOrder($playlist, $name, $i + 1);

        // Attach to Channel
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

    // 6. Clear any cached relationships.
    $channel->refresh();

    return $channel;
}

function createSingleGroupChannel(CustomPlaylist $playlist, Group $group): Channel
{
    $channel = Channel::factory()->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Single-Group Test Channel',
    ]);

    $playlist->channels()->attach($channel->id);

    \DB::table('taggables')
        ->where('taggable_type', Channel::class)
        ->where('taggable_id', $channel->id)
        ->delete();

    $tagId = createTagWithOrder($playlist, 'Sports', 1);

    // Attach to Channel
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
// 1. m3u_output_with_multi_group_channels
// ---------------------------------------------------------------------------

it('M3U output with multi-group channels', function () {
    $user = User::factory()->create();
    $sourcePlaylist = \App\Models\Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);
    $groupB = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 2]);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Add a multi-group channel (3 tags).
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News', 'Music']);

    // Add a single-group channel.
    createSingleGroupChannel($customPlaylist, $groupB);

    // Add a no-tag channel (should fall back to source group).
    createNoTagChannel($customPlaylist, $groupA);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    expect(strlen($m3u))->toBeGreaterThan(0);

    // Count EXTINF lines: 3 (multi-group) + 1 (single-group) + 1 (no-tag) = 5
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(5);

    // Multi-group channel should appear 3 times with correct group-titles.
    expect($m3u)->toContain('group-title="Sports"')
        ->and($m3u)->toContain('group-title="News"')
        ->and($m3u)->toContain('group-title="Music"');

    // Single-group channel should appear once with "Sports".
    expect($m3u)->not->toContain('group-title=""');

    // No-tag channel should use source group (non-empty).
    $noTagExtinf = substr_count($m3u, 'No-Tag Test Channel');
    expect($noTagExtinf)->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. m3u_output_ordering_respects_tag_order_column
// ---------------------------------------------------------------------------

it('M3U output ordering respects tag order column', function () {
    $user = User::factory()->create();
    $sourcePlaylist = \App\Models\Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a multi-group channel with tags ordered News(1) → Sports(2) → Music(3).
    createMultiGroupChannel($customPlaylist, $groupA, ['News', 'Sports', 'Music']);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    expect(strlen($m3u))->toBeGreaterThan(0);

    // All three groups should appear in the output.
    expect($m3u)->toContain('group-title="Music"')
        ->and($m3u)->toContain('group-title="News"')
        ->and($m3u)->toContain('group-title="Sports"');

    // Verify ordering: News (order=1) < Sports (order=2) < Music (order=3).
    $musicPos = strpos($m3u, 'group-title="Music"');
    $newsPos = strpos($m3u, 'group-title="News"');
    $sportsPos = strpos($m3u, 'group-title="Sports"');

    // All positions should be valid.
    expect($musicPos)->toBeGreaterThan(0);
    expect($newsPos)->toBeGreaterThan(0);
    expect($sportsPos)->toBeGreaterThan(0);

    // Verify the order: News comes before Sports, which comes before Music.
    expect($newsPos)->toBeLessThan($sportsPos);
    expect($sportsPos)->toBeLessThan($musicPos);
});

// ---------------------------------------------------------------------------
// 3. m3u_output_channel_number_per_group
// ---------------------------------------------------------------------------

it('M3U output channel number per group', function () {
    $user = User::factory()->create();
    $sourcePlaylist = \App\Models\Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a multi-group channel.
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News']);

    // Manually set channel_number on the pivot (simulating auto-increment).
    $channel = Channel::where('title', 'Multi-Group Test Channel')->first();
    \DB::table('channel_custom_playlist')
        ->where('channel_id', $channel->id)
        ->where('custom_playlist_id', $customPlaylist->id)
        ->update(['channel_number' => 42]);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    $m3u = $response->streamedContent();

    expect(strlen($m3u))->toBeGreaterThan(0);

    // The channel number should be the same across all group instances (shared pivot).
    $tvgChnoMatches = [];
    preg_match_all('/tvg-chno="(\d+)"/', $m3u, $matches);
    $channelNumbers = $matches[1];

    expect(count($channelNumbers))->toBe(2); // Multi-group channel appears twice.
    expect(array_unique($channelNumbers))->toHaveCount(1); // All instances have the same number.
    expect($channelNumbers[0])->toBe('42');
});

// ---------------------------------------------------------------------------
// 4. xtream_mixed_playlist_with_multi_single_no_tag_channels
// ---------------------------------------------------------------------------

it('Xtream mixed playlist with multi-group, single-tag, and no-tag channels', function () {
    $user = User::factory()->create();
    $sourcePlaylist = \App\Models\Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a PlaylistAuth and assign it to the custom playlist.
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

    // 1. Multi-group channel (3 tags).
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News', 'Music']);

    // 2. Single-tag channel.
    createSingleGroupChannel($customPlaylist, $groupA);

    // 3. No-tag channel (falls back to source group).
    createNoTagChannel($customPlaylist, $groupA);

    // Make the Xtream API request for live streams.
    $queryParams = http_build_query([
        'username' => $authUsername,
        'password' => $authPassword,
        'action' => 'get_live_streams',
    ]);

    $response = $this->getJson(route('xtream.api.player') . '?' . $queryParams);

    $response->assertStatus(200);

    $streams = $response->json();

    // Total: 3 (multi-group) + 1 (single-tag) + 1 (no-tag) = 5 entries.
    expect(count($streams))->toBe(5);

    // Find each channel's entry by name.
    $multiGroupStreams = array_filter($streams, fn ($s) => $s['name'] === 'Multi-Group Test Channel');
    $singleGroupStream = collect($streams)->first(fn ($s) => $s['name'] === 'Single-Group Test Channel');
    $noTagStream = collect($streams)->first(fn ($s) => $s['name'] === 'No-Tag Test Channel');

    // Multi-group: 3 entries, each with different category_id.
    expect(count($multiGroupStreams))->toBe(3);
    $categoryIds = collect($multiGroupStreams)->pluck('category_id')->all();
    expect(collect($categoryIds)->unique()->count())->toBe(3);

    // Single-tag: 1 entry, has a category_id set.
    expect($singleGroupStream['category_ids'])->toHaveCount(1);
    expect($singleGroupStream['stream_type'])->toBe('live');

    // No-tag channel uses group_id as its single category.
    expect($noTagStream)->not->toBeNull();
    expect($noTagStream['category_ids'])->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// 5. xtream_get_live_categories_includes_all_groups
// ---------------------------------------------------------------------------

it('Xtream get live categories includes all groups', function () {
    $user = User::factory()->create();
    $sourcePlaylist = \App\Models\Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a PlaylistAuth and assign it to the custom playlist.
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

    // Create a multi-group channel (3 tags).
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News', 'Music']);

    // Make the Xtream API request for live categories.
    $queryParams = http_build_query([
        'username' => $authUsername,
        'password' => $authPassword,
        'action' => 'get_live_categories',
    ]);

    $response = $this->getJson(route('xtream.api.player') . '?' . $queryParams);

    $response->assertStatus(200);

    $categories = $response->json();

    expect(count($categories))->toBeGreaterThan(0);

    // All three tag group names should be present as categories.
    $sportsFound = false;
    $newsFound = false;
    $musicFound = false;

    foreach ($categories as $category) {
        $name = $category['category_name'] ?? '';

        if (is_array($name)) {
            // Spatie already decoded the JSON column
            $actualName = $name['en'] ?? array_values($name)[0] ?? '';
        } else {
            $decoded = json_decode($name, true);
            if (is_array($decoded)) {
                $actualName = $decoded['en'] ?? array_values($decoded)[0] ?? '';
            } else {
                $actualName = $name;
            }
        }

        if ($actualName === 'Sports') $sportsFound = true;
        if ($actualName === 'News') $newsFound = true;
        if ($actualName === 'Music') $musicFound = true;
    }

    expect($sportsFound)->toBeTrue()->and($newsFound)->toBeTrue()->and($musicFound)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 6. remove_group_preserves_others
// ---------------------------------------------------------------------------

it('Removing one tag preserves other group entries in M3U', function () {
    $user = User::factory()->create();
    $sourcePlaylist = \App\Models\Playlist::factory()->for($user)->create();
    $groupA = Group::factory()->for($sourcePlaylist)->for($user)->create(['sort_order' => 1]);

    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with 3 tags.
    createMultiGroupChannel($customPlaylist, $groupA, ['Sports', 'News', 'Music']);

    // Verify all 3 group-titles are present before deletion.
    $responseBefore = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $responseBefore->assertStatus(200);
    expect($responseBefore->streamedContent())->toContain('group-title="Sports"');
    expect($responseBefore->streamedContent())->toContain('group-title="News"');
    expect($responseBefore->streamedContent())->toContain('group-title="Music"');

    // Delete one tag record directly from the DB.
    $tagToDelete = \DB::table('tags')
        ->where('name', json_encode(['en' => 'Music']))
        ->where('type', $customPlaylist->uuid)
        ->first();

    expect($tagToDelete)->not->toBeNull();

    // Delete the tag and its pivot record.
    \DB::table('tags')->where('id', $tagToDelete->id)->delete();
    \DB::table('taggables')
        ->where('tag_id', $tagToDelete->id)
        ->delete();

    // Verify exactly 2 group-titles remain in the M3U.
    $responseAfter = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $responseAfter->assertStatus(200);

    $m3u = $responseAfter->streamedContent();

    // Should still have Sports and News.
    expect($m3u)->toContain('group-title="Sports"')
        ->and($m3u)->not->toContain('group-title="Music"');

    // Exactly 2 EXTINF lines (one per remaining tag).
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(2);

    // Channel title should appear exactly twice.
    expect(substr_count($m3u, 'Multi-Group Test Channel'))->toBe(2);
});

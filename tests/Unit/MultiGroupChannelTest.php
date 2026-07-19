<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (no $this dependency)
// ---------------------------------------------------------------------------

function createMultiGroupChannel(CustomPlaylist $playlist, Group $group, array $tagNames): Channel
{
    // Create tags scoped to this custom playlist (type = playlist UUID).
    $tags = collect($tagNames)->map(function ($name) use ($playlist): Tag {
        return Tag::create([
            'name' => ['en' => $name],
            'type' => $playlist->uuid,
        ]);
    });

    // Create the channel and attach it to both the playlist pivot and all tags.
    $channel = Channel::factory()->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Multi-Group Test Channel',
    ]);

    $playlist->channels()->attach($channel->id);
    $channel->attachTags($tags->all());

    return $channel;
}

// ---------------------------------------------------------------------------
// 1. channel_with_multiple_tags_emits_multiple_m3u_entries
// ---------------------------------------------------------------------------

it('channel with multiple tags emits multiple M3U entries', function () {
    $user = User::factory()->create();
    $group = Group::factory()->for($user)->create(['sort_order' => 1]);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    createMultiGroupChannel($customPlaylist, $group, ['Sports', 'News', 'Music']);

    // Fetch M3U output directly (no auth required for custom playlists without PlaylistAuth).
    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $m3u = $response->getContent();

    // Count EXTINF lines — each group tag produces one entry.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(3);

    // Each group should appear as a distinct group-title attribute.
    expect($m3u)->toContain('group-title="Sports"')
        ->and($m3u)->toContain('group-title="News"')
        ->and($m3u)->toContain('group-title="Music"');

    // The channel title should appear once per EXTINF line (duplicated).
    expect(substr_count($m3u, 'Multi-Group Test Channel'))->toBe(3);
});

// ---------------------------------------------------------------------------
// 2. channel_with_no_tags_uses_source_group
// ---------------------------------------------------------------------------

it('channel with no tags uses source group', function () {
    $user = User::factory()->create();
    $group = Group::factory()->for($user)->create(['sort_order' => 1, 'name' => 'SourceGroup']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel attached to the playlist but with NO group tags.
    $channel = Channel::factory()->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'No-Tag Channel',
    ]);

    // Attach to playlist only (no tags).
    $customPlaylist->channels()->attach($channel->id);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $m3u = $response->getContent();

    // Should emit exactly 1 EXTINF line using the source group name.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(1);

    // The group-title should come from the channel's source group (not empty).
    expect($m3u)->toContain('group-title="SourceGroup"')
        ->and($m3u)->not->toContain('group-title=""');
});

// ---------------------------------------------------------------------------
// 3. channel_with_one_tag_emits_single_entry
// ---------------------------------------------------------------------------

it('channel with one tag emits single entry', function () {
    $user = User::factory()->create();
    $group = Group::factory()->for($user)->create(['sort_order' => 1]);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    createMultiGroupChannel($customPlaylist, $group, ['Sports']);

    $response = $this->get("/{$customPlaylist->uuid}/playlist.m3u");
    $m3u = $response->getContent();

    // Exactly one EXTINF line for a single-tag channel.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(1);

    // The group-title should be the tag name.
    expect($m3u)->toContain('group-title="Sports"');
});

// ---------------------------------------------------------------------------
// 4. multi_group_xtream_streams_one_per_category
// ---------------------------------------------------------------------------

it('multi-group channel produces one Xtream stream entry per category', function () {
    $user = User::factory()->create();
    $playlist = \App\Models\Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($playlist)->for($user)->create(['sort_order' => 1]);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a standard playlist auth for Xtream API access.
    $authUsername = 'test_xtream_user';
    $authPassword = 'test_pass';
    \App\Models\PlaylistAuth::create([
        'name' => 'Xtream Auth',
        'username' => $authUsername,
        'password' => $authPassword,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach(\App\Models\PlaylistAuth::first());

    // Build a custom playlist with multi-group channels.
    createMultiGroupChannel($customPlaylist, $group, ['Sports', 'News']);

    // Make the Xtream API request for live streams.
    $queryParams = http_build_query([
        'username' => $authUsername,
        'password' => $authPassword,
        'action' => 'get_live_streams',
    ]);

    $response = $this->getJson(route('xtream.api.player').'?'.$queryParams);

    $response->assertStatus(200);

    // The response should contain multiple entries for the multi-group channel.
    // Each tag produces one entry, so we expect 2 stream entries with the same stream_id.
    $streams = $response->json();
    $multiGroupStreams = array_filter($streams, fn ($s) => $s['name'] === 'Multi-Group Test Channel');

    expect(count($multiGroupStreams))->toBe(2);

    // Each entry should have a different category_id (one per tag).
    $categoryIds = collect($multiGroupStreams)->pluck('category_id')->all();
    expect($categoryIds)->toHaveCount(2)
        ->and(collect($categoryIds)->unique()->count())->toBe(2);

    // Each entry should have category_ids containing all tag IDs.
    foreach ($multiGroupStreams as $stream) {
        expect($stream['category_ids'])->toHaveCount(2)
            ->and($stream['stream_type'])->toBe('live');
    }
});

// ---------------------------------------------------------------------------
// 5. playlist_alias_uses_custom_group_names
// ---------------------------------------------------------------------------

it('playlist alias of custom playlist uses custom group names', function () {
    $user = User::factory()->create();
    $group = Group::factory()->for($user)->create(['sort_order' => 1]);
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create a channel with multiple custom group tags.
    createMultiGroupChannel($customPlaylist, $group, ['Sports', 'News']);

    // Create an alias of the custom playlist.
    $aliasUuid = \Illuminate\Support\Str::uuid()->toString();
    PlaylistAlias::create([
        'name' => 'Test Alias',
        'uuid' => $aliasUuid,
        'user_id' => $user->id,
        'custom_playlist_id' => $customPlaylist->id,
    ]);

    // Verify the alias resolves correctly.
    $resolved = \App\Models\PlaylistFacade::resolvePlaylistByUuid($aliasUuid);
    expect($resolved)->toBeInstanceOf(\App\Models\PlaylistAlias::class);

    $response = $this->get("/{$resolved->uuid}/playlist.m3u");
    $m3u = $response->getContent();

    // The alias should use the custom tag names (Sports, News), not source group names.
    expect($m3u)->toContain('group-title="Sports"')
        ->and($m3u)->toContain('group-title="News"');

    // Should emit 2 EXTINF lines (one per tag) — proving multi-group works through aliases too.
    $extinfCount = substr_count($m3u, '#EXTINF:');
    expect($extinfCount)->toBe(2);
});

// ---------------------------------------------------------------------------
// 6. taggables_table_accepts_multiple_same_type_tags
// ---------------------------------------------------------------------------

it('taggables table accepts multiple same-type tags on a channel', function () {
    $user = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Create two tags with the SAME type (same playlist UUID).
    $tagA = Tag::create([
        'name' => ['en' => 'Group A'],
        'type' => $customPlaylist->uuid,
    ]);
    $tagB = Tag::create([
        'name' => ['en' => 'Group B'],
        'type' => $customPlaylist->uuid,
    ]);

    // Create a channel and attach both tags.
    $channel = Channel::factory()->for($user)->create();
    $channel->attachTags([$tagA, $tagB]);

    // Verify both taggables rows exist in the database.
    expect(\DB::table('taggables')
        ->where('taggable_id', $channel->id)
        ->where('taggable_type', Channel::class)
        ->whereIn('tag_id', [$tagA->id, $tagB->id])
        ->count())->toBe(2);

    // Verify the channel's tags relationship returns both.
    expect($channel->tags)->toHaveCount(2);

    // Verify getCustomGroupName still works (returns first tag).
    expect($channel->getCustomGroupName($customPlaylist->uuid))->toBe('Group A');
});

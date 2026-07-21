<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

beforeEach(function () {
    Event::fake();
    $this->user = User::factory()->create();
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

it('can get custom group name for a channel', function () {
    // Create a channel
    $channel = Channel::factory()->create(['user_id' => $this->user->id]);

    // Create a tag for the custom playlist
    $tag = Tag::create([
        'name' => ['en' => 'Sports'],
        'slug' => Str::slug('Sports'),
        'type' => $this->customPlaylist->uuid,
    ]);

    // Attach the tag to the channel
    $channel->attachTag($tag);

    // Test the custom group name method
    expect($channel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Sports');
});

it('returns uncategorized when channel has no custom group', function () {
    // Create a channel without any tags
    $channel = Channel::factory()->create(['user_id' => $this->user->id]);

    // Test that it returns 'Uncategorized'
    expect($channel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Uncategorized');
});

it('returns correct group when channel has multiple tags of different types', function () {
    // Create a channel
    $channel = Channel::factory()->create(['user_id' => $this->user->id]);

    // Create multiple tags of different types
    $sportsTag = Tag::create([
        'name' => ['en' => 'Sports'],
        'slug' => Str::slug('Sports'),
        'type' => $this->customPlaylist->uuid,
    ]);

    $otherTag = Tag::create([
        'name' => ['en' => 'News'],
        'slug' => Str::slug('News'),
        'type' => 'other-playlist-uuid',
    ]);

    // Attach both tags
    $channel->attachTags([$sportsTag, $otherTag]);

    // Test that it returns the correct tag for the specific custom playlist
    expect($channel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Sports');
    expect($channel->getCustomGroupName('other-playlist-uuid'))->toBe('News');
});

it('returns first tag when channel has multiple tags with same type', function () {
    // Create a channel
    $channel = Channel::factory()->create(['user_id' => $this->user->id]);

    // Create multiple tags with the SAME playlist UUID (same type)
    $sportsTag1 = Tag::create([
        'name' => ['en' => 'Sports'],
        'slug' => Str::slug('Sports'),
        'type' => $this->customPlaylist->uuid,
    ]);

    $newsTag = Tag::create([
        'name' => ['en' => 'News'],
        'slug' => Str::slug('News'),
        'type' => $this->customPlaylist->uuid,
    ]);

    // Attach both tags to the channel
    $channel->attachTags([$sportsTag1, $newsTag]);

    // getCustomGroupName should return the first tag for this playlist UUID
    expect($channel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Sports');
});

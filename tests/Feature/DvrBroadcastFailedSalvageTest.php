<?php

/**
 * Tests for DVR broadcast_failed salvage handling.
 *
 * When m3u-proxy gives up on a DVR capture (upstream feed down longer than
 * DVR_RESTART_WINDOW_SECONDS), it sends a broadcast_failed callback. The editor
 * must keep proxy_network_id and dispatch post-processing so Step 0 can salvage
 * whatever partial HLS capture exists on the proxy — instead of immediately
 * marking the recording failed and letting the proxy's GC delete the segments.
 */

use App\Jobs\PostProcessDvrRecording;
use App\Http\Middleware\ThrottleM3uProxyCallback;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['proxy.m3u_proxy_token' => null, 'proxy.allow_unauthenticated_callbacks' => true]);

    // The callback route throttles via ThrottleRequestsWithRedis, which needs a
    // live Redis — not available in the one-off test container. Auth is not
    // under test here.
    $this->withoutMiddleware(ThrottleM3uProxyCallback::class);

    Queue::fake();

    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped'], 200),
        '*' => Http::response([], 200),
    ]);
});

function salvageRecording(array $overrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create([
        'storage_disk' => 'dvr',
    ]);

    return DvrRecording::factory()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create(array_merge([
            'status' => 'recording',
            'proxy_network_id' => 'net-salvage',
            'season' => null,
            'episode' => null,
        ], $overrides));
}

it('dispatches post-processing with proxy_network_id preserved on broadcast_failed', function () {
    $recording = salvageRecording();

    $response = $this->postJson('/api/dvr/callback', [
        'network_id' => 'net-salvage',
        'event' => 'broadcast_failed',
        'data' => ['exit_code' => 8, 'error' => 'FFmpeg exited with code 8'],
    ]);

    $response->assertOk();

    $recording->refresh();

    expect($recording->status->value)->toBe('post_processing');
    expect($recording->proxy_network_id)->toBe('net-salvage');
    expect($recording->error_message)->toBe('FFmpeg exited with code 8');
    expect($recording->actual_end)->not->toBeNull();

    Queue::assertPushedOn('dvr-post', PostProcessDvrRecording::class);
});

it('does not salvage twice for the supplementary input_error callback', function () {
    // The proxy sends a primary callback (exit code) plus a detail event
    // (error_type=input_error). Only the first may trigger salvage.
    $recording = salvageRecording(['status' => 'post_processing']);

    $response = $this->postJson('/api/dvr/callback', [
        'network_id' => 'net-salvage',
        'event' => 'broadcast_failed',
        'data' => ['error' => 'Error opening input: 403 Forbidden', 'error_type' => 'input_error'],
    ]);

    $response->assertOk();

    $recording->refresh();

    expect($recording->status->value)->toBe('post_processing');

    Queue::assertNotPushed(PostProcessDvrRecording::class);
});

it('does not salvage an already completed recording', function () {
    $recording = salvageRecording(['status' => 'completed']);

    $response = $this->postJson('/api/dvr/callback', [
        'network_id' => 'net-salvage',
        'event' => 'broadcast_failed',
        'data' => ['exit_code' => 8, 'error' => 'FFmpeg exited with code 8'],
    ]);

    $response->assertOk();

    $recording->refresh();

    expect($recording->status->value)->toBe('completed');
    expect($recording->proxy_network_id)->not->toBeNull();

    Queue::assertNotPushed(PostProcessDvrRecording::class);
});

it('does not salvage a cancelled recording', function () {
    $recording = salvageRecording(['status' => 'cancelled']);

    $response = $this->postJson('/api/dvr/callback', [
        'network_id' => 'net-salvage',
        'event' => 'broadcast_failed',
        'data' => ['exit_code' => 8, 'error' => 'FFmpeg exited with code 8'],
    ]);

    $response->assertOk();

    $recording->refresh();

    expect($recording->status->value)->toBe('cancelled');

    Queue::assertNotPushed(PostProcessDvrRecording::class);
});

it('salvages a recording matched by uuid when recording_db_id is absent', function () {
    $recording = salvageRecording(['proxy_network_id' => 'uuid-shaped-network-id']);

    $response = $this->postJson('/api/dvr/callback', [
        'network_id' => 'uuid-shaped-network-id',
        'event' => 'broadcast_failed',
        'data' => ['exit_code' => 8, 'error' => 'FFmpeg exited with code 8'],
    ]);

    $response->assertOk();

    $recording->refresh();

    expect($recording->status->value)->toBe('post_processing');
    expect($recording->proxy_network_id)->toBe('uuid-shaped-network-id');
});

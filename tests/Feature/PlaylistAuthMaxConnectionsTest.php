<?php

/**
 * Tests for per-PlaylistAuth max_connections enforcement.
 *
 * Covers:
 * - max_connections=null means unlimited (no enforcement)
 * - max_connections limit blocks a new stream when active count is at limit
 * - stop_oldest_on_limit per-auth setting overrides global setting
 * - playlist_auth_id is included in stream metadata when PlaylistAuth is authenticated
 * - XtreamApiController returns PlaylistAuth.max_connections in user_info
 */

use App\Models\ArrIntegration;
use App\Models\Channel;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistRequestSetting;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();

    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

// ── PlaylistAuth model ────────────────────────────────────────────────────────

test('PlaylistAuth casts max_connections as integer', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create(['max_connections' => 3]);

    expect($auth->max_connections)->toBe(3)
        ->and($auth->stop_oldest_on_limit)->toBeNull();
});

test('PlaylistAuth max_connections defaults to null (unlimited)', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create();

    expect($auth->max_connections)->toBeNull();
});

// ── checkAndEnforceAuthStreamLimit (via getChannelUrl) ───────────────────────

test('stream is allowed when PlaylistAuth has no max_connections set', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create(['max_connections' => null]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    // Proxy: no active streams and stream creation succeeds
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams*' => Http::response(['stream_id' => 'test-stream-1']),
    ]);

    // Should not throw — unlimited auth bypasses per-auth check
    expect(fn () => app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    ))->not->toThrow(Exception::class);
});

test('stream is blocked when PlaylistAuth is at max_connections', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 1,
        'stop_oldest_on_limit' => false,
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    // Proxy reports 1 active stream for this auth (already at limit)
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 1, 'total_clients' => 1]),
    ]);

    expect(fn () => app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    ))->toThrow(HttpException::class);
});

test('stop_oldest_on_limit per-auth setting evicts oldest stream when limit is reached', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 1,
        'stop_oldest_on_limit' => true,
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $deleteCallCount = 0;

    Http::fake(function ($request) use (&$deleteCallCount) {
        $url = $request->url();

        if (str_contains($url, '/streams/oldest-by-metadata')) {
            $deleteCallCount++;

            return Http::response([
                'message' => 'Deleted oldest stream',
                'deleted_stream' => 'old-stream-id',
                'deleted_count' => 1,
                'stream_age_seconds' => 120,
            ]);
        }

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response([
                'matching_streams' => [],
                'total_matching' => $deleteCallCount > 0 ? 0 : 1,
                'total_clients' => $deleteCallCount > 0 ? 0 : 1,
            ]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    $url = app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    );

    expect($deleteCallCount)->toBeGreaterThanOrEqual(1)
        ->and($url)->toBeString()->not->toBeEmpty();
});

test('catchup request at limit evicts the auth oldest other timeshift stream (same channel) instead of blocking', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 2,
        'stop_oldest_on_limit' => true,
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $deletedStreamIds = [];
    $genericEvictionCalled = false;

    Http::fake(function ($request) use (&$deletedStreamIds, &$genericEvictionCalled) {
        $url = $request->url();

        if (str_contains($url, '/streams/by-metadata')) {
            // Before eviction: live stream + zombie timeshift stream of the SAME channel
            // are both active (at the limit). After eviction only the live one remains.
            $evicted = count($deletedStreamIds) > 0;
            $active = $evicted ? 1 : 2;

            return Http::response([
                'matching_streams' => $evicted ? [
                    [
                        'stream_id' => 'live-stream',
                        'url' => 'http://provider.example/live/u/p/459609.m3u8',
                        'last_access' => '2026-11-12T00:15:00.000000+00:00',
                        'metadata' => ['playlist_auth_id' => '2'],
                    ],
                ] : [
                    [
                        'stream_id' => 'ts-old-stream',
                        'url' => 'http://provider.example/timeshift/u/p/459609/1700000000/7200.ts',
                        'last_access' => '2026-11-12T00:10:00.000000+00:00',
                        'metadata' => ['playlist_auth_id' => '2'],
                    ],
                    [
                        'stream_id' => 'live-stream',
                        'url' => 'http://provider.example/live/u/p/459609.m3u8',
                        'last_access' => '2026-11-12T00:15:00.000000+00:00',
                        'metadata' => ['playlist_auth_id' => '2'],
                    ],
                ],
                'total_matching' => $active,
                'total_clients' => $active,
            ]);
        }

        if ($request->method() === 'DELETE' && str_contains($url, '/streams/oldest-by-metadata')) {
            $genericEvictionCalled = true;

            return Http::response(['deleted_count' => 0]);
        }

        if ($request->method() === 'DELETE' && preg_match('#/streams/[^/]+$#', $url)) {
            $deletedStreamIds[] = basename($url);

            return Http::response([], 200);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            return Http::response(['stream_id' => 'new-catchup-stream']);
        }

        return Http::response([], 200);
    });

    $timeshiftRequest = Request::create('/streaming/timeshift.php', 'GET', [
        'utc' => '1700000000',
        'lutc' => (string) time(),
    ]);
    $timeshiftRequest->server->set('REMOTE_ADDR', '127.0.0.1');
    $timeshiftRequest->headers->set('User-Agent', 'TiviMate/4');

    $url = app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, $timeshiftRequest, null, $auth->username, $auth->id,
    );

    expect($deletedStreamIds)->toBe(['ts-old-stream'])
        ->and($genericEvictionCalled)->toBeFalse()
        ->and($url)->toBeString()->not->toBeEmpty();
});

test('catchup session is reused across repeated requests despite different lutc values', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create();
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $postCalls = 0;

    Http::fake(function ($request) use (&$postCalls) {
        $url = $request->url();

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]);
        }

        // streamExists() probes the running catchup stream before reuse
        if (str_contains($url, '/streams/ts-session-stream')) {
            return Http::response(['stream_id' => 'ts-session-stream']);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            $postCalls++;

            return Http::response(['stream_id' => 'ts-session-stream']);
        }

        return Http::response([], 200);
    });

    $makeRequest = fn (string $lutc) => tap(Request::create('/streaming/timeshift.php', 'GET', [
        'utc' => '1700000000',
        'lutc' => $lutc,
    ]), function (Request $request) {
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'TiviMate/4');
    });

    $service = app(M3uProxyService::class);

    $firstUrl = $service->getChannelUrl($playlist, $channel, $makeRequest('1700000100'), null, $auth->username, $auth->id);
    $secondUrl = $service->getChannelUrl($playlist, $channel, $makeRequest('1700000200'), null, $auth->username, $auth->id);

    expect($postCalls)->toBe(1)
        ->and($secondUrl)->toBe($firstUrl)
        ->and($secondUrl)->toBeString()->not->toBeEmpty();
});

test('global stop_oldest_on_limit is used when per-auth setting is null', function () {
    // Mock GeneralSettings to return proxy_stop_oldest_on_limit=true without DB persistence
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->proxy_stop_oldest_on_limit = true;
    app()->instance(GeneralSettings::class, $settings);

    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 1,
        'stop_oldest_on_limit' => null, // Falls back to global setting (true)
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $deleteCalled = false;

    Http::fake(function ($request) use (&$deleteCalled) {
        $url = $request->url();

        if (str_contains($url, '/streams/oldest-by-metadata')) {
            $deleteCalled = true;

            return Http::response([
                'message' => 'Deleted oldest stream',
                'deleted_stream' => 'old-stream-id',
                'deleted_count' => 1,
                'stream_age_seconds' => 60,
            ]);
        }

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response([
                'matching_streams' => [],
                'total_matching' => $deleteCalled ? 0 : 1,
                'total_clients' => $deleteCalled ? 0 : 1,
            ]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    );

    expect($deleteCalled)->toBeTrue();
});

// ── Metadata injection ────────────────────────────────────────────────────────

test('playlist_auth_id is included in stream metadata when PlaylistAuth is active', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create(['max_connections' => null]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $capturedPayload = null;

    Http::fake(function ($request) use (&$capturedPayload) {
        $url = $request->url();

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            $capturedPayload = $request->data();

            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    );

    expect($capturedPayload)->not->toBeNull()
        ->and($capturedPayload['metadata']['playlist_auth_id'] ?? null)->toBe((string) $auth->id);
});

test('playlist_auth_id is absent from metadata when no PlaylistAuth is used', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $capturedPayload = null;

    Http::fake(function ($request) use (&$capturedPayload) {
        $url = $request->url();

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            $capturedPayload = $request->data();

            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($capturedPayload)->not->toBeNull()
        ->and(isset($capturedPayload['metadata']['playlist_auth_id']))->toBeFalse();
});

// ── DVR feature access ─────────────────────────────────────────────────────────

test('PlaylistAuth dvr_enabled defaults to false', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create();

    expect($auth->dvr_enabled)->toBeFalse();
});

test('Xtream auth advertises dvr when PlaylistAuth has DVR access and playlist has enabled DVR settings', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-dvr-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
        'username' => 'dvr-user',
        'password' => 'dvr-pass',
    ]);
    $auth->assignTo($playlist);

    DvrSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username=dvr-user&password=dvr-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress', 'dvr']);
});

test('Xtream auth does not advertise dvr when PlaylistAuth DVR access is disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-no-dvr-auth-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => false,
        'username' => 'no-dvr-user',
        'password' => 'no-dvr-pass',
    ]);
    $auth->assignTo($playlist);

    DvrSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username=no-dvr-user&password=no-dvr-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress']);
});

test('Xtream auth does not advertise dvr when playlist has disabled DVR settings', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-without-tuner-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
        'username' => 'missing-tuner-user',
        'password' => 'missing-tuner-pass',
    ]);
    $auth->assignTo($playlist);

    DvrSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => false,
    ]);

    $response = $this->getJson('/player_api.php?username=missing-tuner-user&password=missing-tuner-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress']);
});

test('Xtream owner auth advertises dvr when playlist has enabled DVR settings', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    DvrSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.$playlist->uuid);

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress', 'dvr']);
});

// ── Request integration feature access ────────────────────────────────────────

test('PlaylistAuth request_enabled defaults to false', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create();

    expect($auth->request_enabled)->toBeFalse();
});

test('Xtream auth advertises requests when PlaylistAuth has request access and playlist request integration is enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-requests-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'request_enabled' => true,
        'username' => 'request-user',
        'password' => 'request-pass',
    ]);
    $auth->assignTo($playlist);

    PlaylistRequestSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);
    ArrIntegration::factory()->for($this->user)->create([
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username=request-user&password=request-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress', 'requests']);
});

test('Xtream auth does not advertise requests when PlaylistAuth request access is disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-no-request-auth-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'request_enabled' => false,
        'username' => 'no-request-user',
        'password' => 'no-request-pass',
    ]);
    $auth->assignTo($playlist);

    PlaylistRequestSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);
    ArrIntegration::factory()->for($this->user)->create([
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username=no-request-user&password=no-request-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress']);
});

test('Xtream auth does not advertise requests when playlist request integration is disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-request-disabled-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'request_enabled' => true,
        'username' => 'request-disabled-user',
        'password' => 'request-disabled-pass',
    ]);
    $auth->assignTo($playlist);

    PlaylistRequestSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => false,
    ]);
    ArrIntegration::factory()->for($this->user)->create([
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username=request-disabled-user&password=request-disabled-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress']);
});

test('Xtream auth does not advertise requests when no guest-enabled ARR integration exists', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'playlist-no-guest-arr-uuid',
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'request_enabled' => true,
        'username' => 'no-guest-arr-user',
        'password' => 'no-guest-arr-pass',
    ]);
    $auth->assignTo($playlist);

    PlaylistRequestSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);
    ArrIntegration::factory()->for($this->user)->create([
        'enabled' => true,
        'guest_enabled' => false,
    ]);

    $response = $this->getJson('/player_api.php?username=no-guest-arr-user&password=no-guest-arr-pass');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress']);
});

test('Xtream owner auth does not advertise requests when playlist request integration is enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'uuid' => 'owner-request-uuid',
    ]);

    PlaylistRequestSetting::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
    ]);
    ArrIntegration::factory()->for($this->user)->create([
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password=owner-request-uuid');

    $response->assertOk()
        ->assertJsonPath('m3u_editor.features', ['viewers', 'progress']);
});

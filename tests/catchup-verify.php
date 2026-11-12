<?php

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Catchup verification for Trex-style Xtream providers.
 *
 * Usage (per memory: catchup-verification-procedure):
 *   docker cp tests/catchup-verify.php m3u-editor-dev:/tmp/catchup-verify.php
 *   docker exec m3u-editor-dev php artisan tinker //tmp/catchup-verify.php -- --quick 4048,4052
 *   docker exec m3u-editor-dev php artisan tinker //tmp/catchup-verify.php -- --frames 4048,4052
 *   docker exec m3u-editor-dev php artisan tinker //tmp/catchup-verify.php   (list all catchup-enabled)
 *
 * Modes:
 *   (no mode)            List channels with catchup='1' (id, name, url shape).
 *   --quick <ids>        Playlist-shape check on the /timeshift/ dialect.
 *   --frames <ids>       Quick check + frame grabs (archive vs live JPGs to /tmp/catchup-frames/,
 *                        docker cp them out and VIEW them for the content-level verdict).
 *
 * NEVER trust ffprobe exit codes for catchup: providers serve the LIVE stream
 * for any catchup-shaped URL, so exit-0 means nothing. See memory
 * catchup-verification-procedure for the decision table.
 */

$mode = '--list';
$idsArg = '';
$argv = $_SERVER['argv'] ?? [];
foreach ($argv as $i => $a) {
    if ($a === '--quick' || $a === '--frames') {
        $mode = $a;
        $idsArg = $argv[$i + 1] ?? '';
    }
}
$hourAgo = time() - 7200;

if ($mode === '--list') {
    App\Models\Channel::query()
        ->where('catchup', '1')
        ->orderBy('playlist_id')->orderBy('name')
        ->get(['id', 'name', 'url', 'url_custom'])
        ->each(fn ($c) => printf("%-7d %-40s %s\n", $c->id, mb_substr($c->name, 0, 38), (string) ($c->url_custom ?: $c->url)));

    exit;
}

if ($idsArg === '') {
    echo "usage: --quick|--frames <comma-separated ids>\n";
    exit(1);
}
$ids = array_map('intval', explode(',', trim($idsArg, '"')));

$http = Http::timeout(45)->withOptions(['allow_redirects' => true]);
$fetch = function (string $url) use ($http): string {
    // Provider throttles bursts (even single non-throttled runs got blanked
    // moments after a batch) — retry once after a pause, 3 attempts total.
    $lastStatus = 0;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $res = $http->get($url);
            $lastStatus = $res->status();
            if ($res->successful() && $res->body() !== '') {
                return $res->body();
            }
        } catch (Throwable) {
            $lastStatus = 0;
        }
        if ($lastStatus >= 400 && $lastStatus < 500) {
            break; // 4xx is definitive — retrying just burns session quota
        }
        if ($attempt < 3) {
            usleep(5000000); // 5s backoff before retry
        }
    }

    return "HTTP_".$lastStatus;
};
$parse = function (string $body): array {
    $seq = preg_match('~#EXT-X-MEDIA-SEQUENCE:(\d+)~', $body, $m) ? (int) $m[1] : null;
    $type = preg_match('~#EXT-X-PLAYLIST-TYPE:(\S+)~', $body, $m2) ? $m2[1] : '';
    preg_match_all('~#EXTINF:([\d.]+)~', $body, $m3);
    $first = '';
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if ($line !== '' && ! str_starts_with($line, '#')) {
            $first = basename($line);
            break;
        }
    }

    return [
        'isHls' => str_contains($body, '#EXTM3U'),
        'isMaster' => str_contains($body, '#EXT-X-STREAM-INF'),
        'seq' => $seq,
        'type' => $type,
        'dur' => (int) round(array_sum(array_map('floatval', $m3[1]))),
        'first' => $first,
    ];
};
$resolveVariant = function (string $url, string $body) use ($fetch, $parse): array {
    // Return [variantBody, variantUrl] — descend master -> first variant once.
    for ($i = 0; $i < 2 && $parse($body)['isMaster']; $i++) {
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                if (! preg_match('~^https?://~', $line)) {
                    $line = preg_replace('~/[^/]*$~', '/', $url).$line;
                }
                $url = $line;
                $body = $fetch($url);
                continue 2;
            }
        }
        break;
    }

    return [$body, $url];
};

$results = [];
foreach ($ids as $id) {
    $ch = App\Models\Channel::find($id);
    if (! $ch) {
        echo "MISSING #{$id}\n";
        continue;
    }
    $url = $ch->url_custom ?: $ch->url;
    if (! $url || ! preg_match('~/live/([^/]+)/([^/]+)/(\d+)(\.[a-z0-9]+)?~', $url, $m)) {
        printf("NO-XTREAM-URL  [%s] #%d %s\n", $ch->name, $id, (string) $url);
        continue;
    }
    $base = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
    $ext = $m[4] ?? ''; // group includes the dot (".m3u8"); bare-id URLs get none

    // Live-edge reference.
    [$liveBody] = $resolveVariant($url, $fetch($url));
    $L = $parse($liveBody);

    // Xtream /timeshift/ dialect — try provider-local stamp then UTC (some
    // channels only accept one, e.g. 336907 WFSB Hartford = UTC only).
    $verdict = 'NO-CATCHUP';
    $detail = '';
    $chosenUrl = null;
    foreach (['local' => date('Y-m-d:H-i', $hourAgo), 'UTC' => gmdate('Y-m-d:H-i', $hourAgo)] as $tz => $stamp) {
        $tsUrl = "{$base}/timeshift/{$m[1]}/{$m[2]}/60/{$stamp}/{$m[3]}{$ext}";
        $body = $fetch($tsUrl);
        if ($body === 'HTTP_0') {
            $detail .= " ts/{$tz}:network-err";
            continue;
        }
        if (str_starts_with($body, 'HTTP_')) {
            $detail .= " ts/{$tz}:".substr($body, 5);
            continue;
        }
        if ($body === '') {
            $detail .= " ts/{$tz}:empty-200";
            continue;
        }
        [$vBody, $vUrl] = $resolveVariant($tsUrl, $body);
        $T = $parse($vBody);
        if (! $T['isHls']) {
            $detail .= " ts/{$tz}:nonHLS(".strlen($vBody).'B)';
            continue;
        }
        // REAL = VOD archive: seq 0 + duration ~= requested window (60 min).
        // FAKE = live edge: sliding window resembling the live playlist.
        if ($T['type'] === 'VOD' && $T['dur'] > 1800) {
            $verdict = 'REAL';
            $detail .= " ts/{$tz}:VOD(dur={$T['dur']}s)";
            $chosenUrl = $vUrl;
            break;
        }
        $sameEdge = $T['seq'] !== null && $T['seq'] === $L['seq'] && $T['first'] === $L['first'];
        $verdict = $sameEdge ? 'FAKE (live edge)' : 'AMBIGUOUS';
        $detail .= " ts/{$tz}:slide(seq={$T['seq']} dur={$T['dur']}s type={$T['type']})";
        $chosenUrl = $vUrl;
    }

    printf("%-16s [%s] #%d%s\n", $verdict, $ch->name, $id, $detail);
    $results[$id] = compact('verdict', 'chosenUrl', 'url');

    if ($mode === '--frames' && isset($chosenUrl)) {
        @mkdir('/tmp/catchup-frames', 0777, true);
        usleep(1500000);
        $p = new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', '6', '-i', $chosenUrl, '-frames:v', '1', "/tmp/catchup-frames/{$id}-catchup.jpg"]);
        $p->setTimeout(60);
        $p->run();
        usleep(1500000);
        $p = new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', '4', '-i', $url, '-frames:v', '1', "/tmp/catchup-frames/{$id}-live.jpg"]);
        $p->setTimeout(40);
        $p->run();
        echo "  frames: /tmp/catchup-frames/{$id}-catchup.jpg + {$id}-live.jpg\n";
    }

    usleep(2000000); // 2s pacing between channels (provider throttles bursts)
}

echo "DONE\n";

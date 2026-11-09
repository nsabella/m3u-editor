<?php

/**
 * Channel probe + CSV generator for the curated 460-channel list.
 *
 * What it does (all throttled, safe for the provider):
 *   1. Enables provider request throttling for the run.
 *   2. ffprobe live/dead check on every channel's main stream.
 *   3. ffprobe catchup check (utc = 2h ago / lutc = now) on channels with
 *      catchup enabled; failing channels are reported but stay ENABLED
 *      (uncomment one line to disable them instead).
 *   4. Full ffprobe stream stats (resolution, codecs, frame rate, HDR).
 *   5. Writes /tmp/channel-probe-results.csv sorted by:
 *      target A-Z, then LIVE first, catchup first, resolution desc,
 *      frame rate desc, country (US -> CA -> UK -> other, alphabetical).
 *   6. Disables provider request throttling again.
 *
 * Usage:
 *   docker exec -i m3u-editor-dev sh -c 'cat > /tmp/channel-probe.php' < tests/channel-probe.php
 *   docker exec m3u-editor-dev php artisan tinker //tmp/channel-probe.php > tests/probe-run.log 2>&1
 *   docker exec m3u-editor-dev sh -c 'cat /tmp/channel-probe-results.csv' > tests/channel-probe-results.csv
 *
 * To add/remove channels, edit the $channels map below.
 * Dead channels are NEVER disabled (disableDead equivalent is not applied);
 * failed catchups ARE disabled (catchup = "0") — comment that block out if
 * you only want a report.
 */

$channels = [
    10126 => "A&E",
    131 => "A&E",
    780 => "A&E",
    3893 => "ABC 12 Flint",
    3894 => "ABC 13 Grand Rapids",
    3895 => "ABC 53 Lansing",
    3892 => "ABC 7 Detroit",
    337431 => "ABC Detroit News",
    337763 => "ABC Localish",
    786 => "ABC Localish",
    1266 => "ABC Localish",
    787 => "ABC News Live",
    337437 => "ABC News Live",
    1184 => "ABC News Live",
    450 => "ACC Network",
    547 => "ACC Network",
    789 => "ACC Network",
    133 => "AccuWeather",
    790 => "AccuWeather",
    337448 => "AccuWeather",
    10208 => "Adult Swim",
    134 => "Adult Swim",
    10690 => "Adult Swim",
    135 => "AMC",
    798 => "AMC",
    337468 => "AMC",
    136 => "AMC+",
    337466 => "AMC+",
    10692 => "AMC+",
    138 => "Animal Planet",
    804 => "Animal Planet",
    147 => "BBC America",
    813 => "BBC America",
    337492 => "BBC America",
    336851 => "BBC World News",
    10133 => "BBC World News",
    12126 => "BBC World News",
    814 => "BBC World News",
    337496 => "BBC World News",
    12131 => "BBC World News",
    12127 => "BBC World News",
    15295 => "BBC World News",
    14552 => "BBC World News",
    11998 => "BBC World News",
    12125 => "BBC World News",
    11999 => "BBC World News",
    149 => "BET",
    10136 => "BET",
    820 => "BET",
    7138 => "BET",
    150 => "BET Her",
    345722 => "BET Her",
    818 => "BET Her",
    460 => "Big Ten Network",
    822 => "Big Ten Network",
    823 => "Big Ten Network",
    824 => "Big Ten Network",
    833 => "Big Ten Network",
    336884 => "Bloomberg Television",
    10160 => "Bloomberg Television",
    827 => "Bloomberg Television",
    65 => "Bloomberg Television",
    70 => "Bloomberg Television",
    337507 => "Bloomberg Television",
    1198 => "Bloomberg Television",
    10694 => "Bloomberg Television",
    406 => "Boomerang",
    829 => "Boomerang",
    10358 => "Boomerang",
    158 => "Bravo",
    10695 => "Bravo",
    832 => "Bravo",
    336852 => "C-SPAN",
    867 => "C-SPAN",
    336853 => "C-SPAN2",
    866 => "C-SPAN2",
    336854 => "C-SPAN3",
    407 => "Cartoon Network",
    408 => "Cartoon Network",
    836 => "Cartoon Network",
    10360 => "Cartoon Network",
    10696 => "Cartoon Network",
    4115 => "CBS Michigan: CBS 5 Saginaw",
    4116 => "CBS Michigan: CBS 6 Lansing",
    4118 => "CBS Michigan: CBS 62 Detroit",
    337523 => "CBS Michigan: CBS Grand Rapids",
    336857 => "CBS News",
    337527 => "CBS News",
    10697 => "CBS News",
    337532 => "CBS News Detroit",
    461 => "CBS Sports Network",
    837 => "CBS Sports Network",
    161 => "Charge!",
    336864 => "Cheddar",
    336865 => "Cheddar",
    838 => "Cheddar",
    1205 => "Cheddar",
    553 => "Cinemax",
    552 => "Cinemax",
    843 => "Cinemax",
    337554 => "Cinemax",
    166 => "CMT",
    849 => "CMT",
    167 => "CMT Music",
    10147 => "CNBC",
    336868 => "CNBC",
    850 => "CNBC",
    336869 => "CNBC World",
    10148 => "CNN",
    336866 => "CNN",
    336867 => "CNN",
    852 => "CNN",
    66 => "CNN",
    851 => "CNN International",
    169 => "Comedy Central",
    170 => "Comedy Central",
    855 => "Comedy Central",
    172 => "Comedy.tv",
    857 => "Comedy.tv",
    173 => "Comet",
    174 => "Cooking Channel",
    859 => "Cooking Channel",
    10157 => "Cooking Channel",
    863 => "Cozi TV",
    10156 => "Crime+Investigation",
    177 => "Crime+Investigation",
    4750 => "CW 50 Detroit",
    202 => "Dabl",
    178 => "Destination America",
    876 => "Destination America",
    180 => "Discovery",
    181 => "Discovery",
    880 => "Discovery",
    1073 => "Discovery",
    179 => "Discovery Family",
    877 => "Discovery Family",
    183 => "Discovery Life",
    878 => "Discovery Life",
    184 => "Discovery Science",
    185 => "Discovery Turbo",
    879 => "Discovery Turbo",
    10212 => "Discovery Velocity",
    410 => "Disney Channel",
    10356 => "Disney Channel",
    881 => "Disney Channel",
    10709 => "Disney Channel",
    10359 => "Disney Channel Junior",
    10710 => "Disney Channel Junior",
    882 => "Disney Channel Junior",
    414 => "Disney XD",
    10357 => "Disney XD",
    10711 => "Disney XD",
    883 => "Disney XD",
    189 => "E!",
    10216 => "E!",
    891 => "E!",
    474 => "ESPN",
    543 => "ESPN",
    906 => "ESPN",
    337614 => "ESPN",
    541 => "ESPN",
    542 => "ESPN",
    476 => "ESPN News",
    539 => "ESPN News",
    904 => "ESPN News",
    477 => "ESPN U",
    540 => "ESPN U",
    905 => "ESPN U",
    475 => "ESPN2",
    544 => "ESPN2",
    545 => "ESPN2",
    902 => "ESPN2",
    321 => "FanDuel TV",
    479 => "FanDuel TV",
    337627 => "FanDuel TV",
    912 => "FanDuel TV",
    322 => "FanDuel TV Extra",
    486 => "FanDuel TV Extra",
    337628 => "FanDuel TV Extra",
    205 => "Food Network",
    915 => "Food Network",
    4341 => "FOX 17 Grand Rapids",
    337641 => "FOX 2 Detroit (PRIME)",
    4340 => "FOX 2 Detroit (US)",
    336879 => "FOX Business",
    916 => "FOX Business",
    4343 => "FOX Flint",
    337661 => "FOX Grand Rapids",
    4342 => "FOX Lansing",
    336883 => "FOX News",
    10175 => "FOX News",
    917 => "FOX News",
    207 => "FOX Soul",
    337666 => "FOX Soul",
    1241 => "FOX Soul",
    918 => "FOX Soul",
    208 => "FOX Weather",
    337668 => "FOX Weather",
    922 => "FOX Weather",
    1244 => "FOX Weather",
    209 => "Freeform",
    923 => "Freeform",
    482 => "FS1",
    919 => "FS1",
    483 => "FS2",
    920 => "FS2",
    496 => "Fubo Sports 2",
    494 => "Fubo Sports Network",
    495 => "Fubo Sports Network",
    10718 => "Fubo Sports Network",
    10719 => "Fubo Sports Network 2",
    213 => "FX",
    928 => "FX",
    214 => "FXM",
    926 => "FXM",
    216 => "FXX",
    927 => "FXX",
    218 => "FYI",
    929 => "FYI",
    10222 => "FYI",
    930 => "Galavision",
    224 => "Game Show Network",
    10190 => "Game Show Network",
    931 => "Game Show Network",
    498 => "Golf Channel",
    513 => "Golf Channel",
    934 => "Golf Channel",
    223 => "Great American Family",
    222 => "Great American Family",
    937 => "Great American Family",
    226 => "Hallmark Channel",
    942 => "Hallmark Channel",
    225 => "Hallmark Family",
    337688 => "Hallmark Movies & More",
    227 => "Hallmark Mystery",
    943 => "Hallmark Mystery",
    562 => "HBO 2",
    337691 => "HBO 2",
    560 => "HBO/Max",
    337698 => "HBO/Max",
    946 => "HBO/Max",
    10223 => "HGTV",
    231 => "HGTV",
    953 => "HGTV",
    10732 => "HGTV",
    954 => "History & Warfare",
    233 => "HLN",
    10226 => "HLN",
    956 => "HLN",
    239 => "IFC",
    10227 => "IFC",
    337719 => "IFC",
    963 => "IFC",
    968 => "Investigation Discovery (ID)",
    246 => "Justice Central",
    973 => "Justice Central",
    248 => "Lifetime",
    975 => "Lifetime",
    1000 => "Lifetime Movies",
    337758 => "Lifetime Movies",
    337798 => "Lifetime Movies",
    336886 => "LiveNOW from FOX",
    1264 => "LiveNOW from FOX",
    200 => "LMN",
    249 => "LMN",
    978 => "LMN",
    143 => "Magnolia Network",
    986 => "Magnolia Network",
    569 => "MeTV",
    572 => "MeTV",
    571 => "MeTV",
    991 => "MeTV",
    570 => "MeTV Plus",
    421 => "MeTV Toons",
    990 => "MeTV Toons",
    256 => "MGM+",
    557 => "MGM+",
    337780 => "MGM+",
    257 => "Military History",
    499 => "MLB Network",
    994 => "MLB Network",
    995 => "MLB Network",
    10742 => "MLB Network",
    258 => "MotorTrend",
    337797 => "MotorTrend",
    336887 => "MS NOW",
    1007 => "MS NOW",
    260 => "MTV",
    15553 => "MTV",
    1010 => "MTV",
    15545 => "MTV",
    15552 => "MTV",
    261 => "MTV Classic",
    15550 => "MTV Classic",
    15551 => "MTV Classic",
    262 => "MTV Live",
    15555 => "MTV Live",
    15556 => "MTV Live",
    15557 => "MTV Music",
    12256 => "MTV Xmas",
    259 => "MTV2",
    1008 => "MTV2",
    272 => "National Geographic",
    1014 => "National Geographic",
    273 => "National Geographic Wild",
    1013 => "National Geographic Wild",
    337816 => "NBA TV",
    337817 => "NBA TV",
    15287 => "NBA TV",
    1650 => "NBA TV",
    1016 => "NBA TV",
    4573 => "NBC Detroit",
    4575 => "NBC Grand Rapids",
    1017 => "NBC News NOW",
    12154 => "NBC News NOW",
    14502 => "NBC News NOW",
    337837 => "NBC News NOW",
    1279 => "NBC News NOW",
    336899 => "Newsmax",
    1026 => "Newsmax",
    336901 => "NewsNation",
    1027 => "NewsNation",
    345727 => "NFL Channel",
    1281 => "NFL Channel",
    14504 => "NFL Network",
    1032 => "NFL Network",
    340141 => "NFL Network",
    340144 => "NFL Network",
    14201 => "NFL Network",
    340080 => "NFL Network Grand Rapids",
    340073 => "NFL Network Lansing",
    340143 => "NFL Red Zone",
    1033 => "NFL Red Zone",
    340142 => "NFL Red Zone",
    337844 => "NHL Channel",
    339946 => "NHL Network",
    1282 => "NHL Network",
    339947 => "NHL Network",
    1034 => "NHL Network",
    418 => "Nick Jr.",
    1036 => "Nick Jr.",
    416 => "Nickelodeon",
    1035 => "Nickelodeon",
    10355 => "Nickelodeon",
    419 => "Nicktoons",
    1037 => "Nicktoons",
    277 => "OWN",
    1041 => "OWN",
    278 => "Oxygen",
    1043 => "Oxygen",
    10235 => "Paramount Network",
    280 => "Paramount Network",
    1046 => "Paramount Network",
    1044 => "Paramount Network",
    281 => "PBS",
    343134 => "PBS Detroit",
    420 => "PBS Kids",
    1047 => "PBS Kids",
    283 => "Pop (Pop TV)",
    1051 => "Pop (Pop TV)",
    285 => "QVC",
    1060 => "QVC",
    71 => "QVC",
    337886 => "QVC",
    514 => "SEC Network",
    546 => "SEC Network",
    1076 => "SEC Network",
    588 => "Showtime",
    587 => "Showtime",
    337920 => "Showtime",
    590 => "Showtime 2",
    1079 => "Showtime 2",
    295 => "Smithsonian Channel",
    1085 => "Smithsonian Channel",
    599 => "Start TV",
    1096 => "Start TV",
    600 => "Starz",
    602 => "Starz",
    10260 => "Starz",
    1099 => "Starz",
    608 => "Starz Comedy",
    1098 => "Starz Comedy",
    607 => "Starz Family",
    298 => "Sundance TV",
    1113 => "Sundance TV",
    337936 => "Sundance TV",
    299 => "SYFY",
    526 => "T2 Live Tennis",
    1126 => "T2 Live Tennis",
    337957 => "T2 Live Tennis",
    301 => "Tastemade",
    1119 => "Tastemade",
    1121 => "Tastemade",
    337944 => "Tastemade",
    337946 => "Tastemade",
    302 => "TBS",
    1123 => "TBS",
    305 => "TCM",
    1124 => "TCM",
    307 => "TeenNick",
    1125 => "TeenNick",
    4810 => "Telemundo",
    4811 => "Telemundo",
    524 => "Tennis Channel",
    525 => "Tennis Channel",
    1127 => "Tennis Channel",
    230 => "The History Channel",
    955 => "The History Channel",
    337703 => "The History Channel",
    308 => "The Weather Channel",
    1142 => "The Weather Channel",
    10185 => "The Weather Channel",
    1131 => "TheGrio-TV",
    1299 => "TheGrio-TV",
    10237 => "TLC",
    310 => "TLC",
    1145 => "TLC",
    312 => "TNT",
    313 => "TNT",
    314 => "TNT",
    315 => "TNT",
    1148 => "TNT",
    1150 => "Torque by History",
    317 => "Travel Channel",
    1151 => "Travel Channel",
    30 => "Travel Channel",
    1153 => "True Crime Network",
    319 => "TruTV",
    1155 => "TruTV",
    1156 => "TUDN",
    323 => "TV Land",
    1157 => "TV Land",
    1159 => "UniMas",
    1161 => "Univision",
    327 => "USA Network",
    326 => "USA Network",
    1164 => "USA Network",
    15604 => "VEVO 2K",
    338037 => "VEVO 70s",
    15601 => "VEVO 70s",
    15602 => "VEVO 80s",
    338038 => "VEVO 80s",
    15603 => "VEVO 90s",
    338039 => "VEVO Country",
    15605 => "VEVO Country",
    338040 => "VEVO Hip-Hop",
    15606 => "VEVO Hip-Hop",
    15607 => "VEVO Latino",
    338041 => "VEVO R&B",
    15609 => "VEVO Reggaeton & Trap",
    329 => "VH1",
    1165 => "VH1",
    15610 => "VH1",
    144 => "Vice",
    330 => "Vice",
    1166 => "Vice",
    338043 => "Vice",
    1315 => "Vice",
    331 => "WE tv",
    1168 => "WE tv",
];

$settings = app(App\Settings\GeneralSettings::class);
$settings->enable_provider_request_delay = true;
$settings->save();
echo "provider throttling: on\n";

$ffprobe = function (string $url, int $timeout = 15): bool {
    usleep(500000); // 500ms between probes
    $p = new \Symfony\Component\Process\Process([
        'ffprobe', '-v', 'quiet', '-analyzeduration', '1000000', '-probesize', '1000000', '-i', $url,
    ]);
    $p->setTimeout($timeout);
    try {
        $p->run();

        return $p->getExitCode() === 0;
    } catch (\Throwable) {
        return false;
    }
};

$live = 0;
$dead = 0;
$catchupWorking = 0;
$catchupFailed = [];

// Pass 1: live/dead on the main stream + stream stats.
foreach ($channels as $id => $target) {
    $channel = App\Models\Channel::find($id);
    if (! $channel) {
        echo "MISSING #$id\n";
        continue;
    }

    $url = $channel->url_custom ?: $channel->url;
    $isLive = $url !== null && $ffprobe($url);
    $channel->update(['last_scrubbed_at' => now(), 'last_scrubber_live' => $isLive]);
    $isLive ? $live++ : $dead++;
    echo ($isLive ? 'LIVE  ' : 'DEAD  ')."[$channel->name]\n";

    $channel->ensureStreamStats();
}

// Pass 2: catchup check (utc = 2h ago, lutc = now) on catchup-enabled channels.
foreach ($channels as $id => $target) {
    $channel = App\Models\Channel::find($id);
    if (! $channel || (string) $channel->catchup !== '1') {
        continue;
    }

    $url = $channel->url_custom ?: $channel->url;
    if (! $url) {
        continue;
    }

    $utc = time() - 7200;
    $lutc = time();
    $catchupUrl = $url.(str_contains($url, '?') ? '&' : '?')."utc={$utc}&lutc={$lutc}";

    if ($ffprobe($catchupUrl, 20)) {
        $catchupWorking++;
        echo 'catchup OK:  ['.$channel->name.']'."\n";
    } else {
        $catchupFailed[] = $channel->name;
        // Failed catchups STAY ENABLED by default. To disable them, uncomment the line below.
        // $channel->update(['catchup' => '0']);
        echo 'catchup FAIL: ['.$channel->name."] -> left enabled\n";
    }
}

echo 'DONE. live: '.$live.' | dead: '.$dead.' | catchup ok: '.$catchupWorking.' | catchup failed: '.count($catchupFailed)."\n";

// Pass 3: build the sorted CSV.
$rate = function ($stats) {
    foreach ((array) $stats as $entry) {
        $s = $entry['stream'] ?? $entry;
        if (($s['codec_type'] ?? null) === 'video' && ! empty($s['avg_frame_rate']) && $s['avg_frame_rate'] !== '0/0') {
            if (preg_match('~^(\d+)/(\d+)$~', $s['avg_frame_rate'], $m) && $m[2] > 0) {
                return round($m[1] / $m[2], 2);
            }
        }
    }

    return '';
};
$rank = fn (string $name) => str_starts_with($name, 'US|') ? 0 : (str_starts_with($name, 'CA|') ? 1 : (str_starts_with($name, 'UK') ? 2 : 3));

$out = fopen('/tmp/channel-probe-results.csv', 'w');
fputcsv($out, ['target_channel', 'channel_id', 'channel_name', 'group_name', 'url', 'enabled', 'probe_result', 'catchup', 'resolution', 'video_codec', 'frame_rate', 'audio_codec', 'hdr', 'last_scrubbed_at'], escape: '\\');

$models = App\Models\Channel::whereIn('id', array_keys($channels))->get()
    ->sortBy(fn ($c) => strtolower($channels[$c->id]).'|'.strtolower($c->name), SORT_NATURAL | SORT_FLAG_CASE);
$rows = [];
foreach ($models as $c) {
    $st = App\Services\StreamStatsService::normalize($c->stream_stats ?? []);
    $rows[] = [$channels[$c->id], $c->id, $c->name, $c->group, $c->url_custom ?? $c->url, $c->enabled ? 'yes' : 'no', $c->last_scrubber_live ? 'LIVE' : 'DEAD', ((string) $c->catchup === '1' ? 'yes' : 'no'), $st['resolution'] ?? '', $st['video_codec'] ?? '', $rate($c->stream_stats ?? []), $st['audio_codec'] ?? '', $st['hdr'] ?? ''];
}
usort($rows, function ($a, $b) use ($rank) {
    if (($cmp = strcasecmp($a[0], $b[0])) !== 0) {
        return $cmp;
    }
    if (($cmp = ($a[6] === 'LIVE' ? 0 : 1) <=> ($b[6] === 'LIVE' ? 0 : 1)) !== 0) {
        return $cmp;
    }
    if (($cmp = ($a[7] === 'yes' ? 0 : 1) <=> ($b[7] === 'yes' ? 0 : 1)) !== 0) {
        return $cmp;
    }
    $pa = preg_match('/^(\d+)x(\d+)$/', $a[8], $ma) ? ((int) $ma[1]) * ((int) $ma[2]) : -1;
    $pb = preg_match('/^(\d+)x(\d+)$/', $b[8], $mb) ? ((int) $mb[1]) * ((int) $mb[2]) : -1;
    if (($cmp = $pb <=> $pa) !== 0) {
        return $cmp;
    }
    if (($cmp = ((float) ($b[10] ?: 0)) <=> ((float) ($a[10] ?: 0))) !== 0) {
        return $cmp;
    }
    if (($cmp = $rank($a[2]) <=> $rank($b[2])) !== 0) {
        return $cmp;
    }

    return strcasecmp($a[2], $b[2]);
});
foreach ($rows as $row) {
    fputcsv($out, $row, escape: '\\');
}
echo 'CSV written: '.count($rows)." rows\n";

// Flip provider throttling back off.
$settings->enable_provider_request_delay = false;
$settings->save();
echo "provider throttling: off\n";

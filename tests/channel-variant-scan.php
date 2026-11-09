<?php

/**
 * Channel variant scan: discover channels in the playlist matching the curated
 * target list (136 original targets + per-station/spinoff categories), apply all
 * exclusions and the schedule-match pruning, and write the id => target map
 * that tests/channel-probe.php consumes.
 *
 * Usage:
 *   docker exec -i m3u-editor-dev sh -c 'cat > /tmp/variant-scan.php' < tests/channel-variant-scan.php
 *   docker exec m3u-editor-dev php artisan tinker //tmp/variant-scan.php
 *
 * Output: prints the $channels = [ id => "target", ... ] block to stdout.
 * Copy it into tests/channel-probe.php (replace the existing $channels map)
 * to probe the discovered set.
 *
 * History: this logic discovered the original 460-channel list. The match
 * percentages behind the country pruning were researched online 2026-11-09
 * (UK/CA/NZ/AU/MU schedules vs US counterparts); the user kept everything
 * >= 70% and the borderline channels.
 */

// ── Target aliases ──────────────────────────────────────────────────────────
// canonical target => match patterns (normalized: lowercase, non-alnum -> space).
// Patterns are token-boundary-matched after prefix/decoration stripping.
$targets = [
    'A&E' => ['a e', 'a e network'],
    'ABC Localish' => ['abc localish', 'localish'],
    'ABC News Live' => ['abc news live'],
    'ACC Network' => ['acc network', 'accn', 'acc network extra'],
    'AccuWeather' => ['accuweather', 'accuweather now'],
    'Adult Swim' => ['adult swim'],
    'AMC+' => ['amc plus', 'amc +'],
    'AMC' => ['amc'],
    'Animal Planet' => ['animal planet'],
    'BBC America' => ['bbc america', 'bbca'],
    'BBC World News' => ['bbc world news', 'bbc news', 'bbc world'],
    'BET+' => [],
    'BET Her' => [],
    'BET' => ['bet'],
    'Big Ten Network' => ['big ten network', 'btn', 'b1g network', 'b1g'],
    'Bloomberg Television' => ['bloomberg television', 'bloomberg tv', 'bloomberg'],
    'Boomerang' => ['boomerang'],
    'Bravo' => ['bravo'],
    'Cartoon Network' => ['cartoon network'],
    'CBS News' => ['cbs news'],
    'CBS News Detroit' => [],
    'CBS Michigan: CBS 5 Saginaw' => [],
    'CBS Michigan: CBS 6 Lansing' => [],
    'CBS Michigan: CBS 62 Detroit' => [],
    'CBS Michigan: CBS Grand Rapids' => [],
    'CBS Sports Network' => ['cbs sports network', 'cbssn'],
    'Charge!' => ['charge'],
    'Cheddar' => ['cheddar'],
    'Cinemax' => ['cinemax'],
    'CMT' => ['cmt', 'country music television'],
    'CMT Music' => [],
    'CNBC' => ['cnbc'],
    'CNBC World' => ['cnbc world'],
    'CNN' => ['cnn'],
    'CNN International' => ['cnn international', 'cnni'],
    'Comedy Central' => ['comedy central'],
    'Comedy.tv' => ['comedy tv'],
    'Comet' => ['comet'],
    'Cooking Channel' => ['cooking channel', 'cooking'],
    'Cozi TV' => ['cozi tv'],
    'Crime+Investigation' => ['crime investigation', 'crime and investigation', 'c i'],
    'C-SPAN' => ['c span', 'cspan'],
    'C-SPAN2' => ['c span 2', 'cspan 2', 'cspan2'],
    'C-SPAN3' => ['c span 3', 'cspan 3', 'cspan3'],
    'Dabl' => ['dabl'],
    'Destination America' => ['destination america', 'planet green', 'discovery home'],
    'Discovery' => ['discovery', 'discovery channel', 'discovery science', 'science'],
    'Discovery Turbo' => ['discovery turbo', 'dtx'],
    'Discovery Family' => [],
    'Discovery History' => [],
    'Discovery Life' => [],
    'Discovery Science' => [],
    'Discovery Velocity' => [],
    'Disney Channel' => ['disney channel'],
    'Disney Channel Junior' => ['disney junior', 'playhouse disney', 'disney channel junior'],
    'Disney XD' => ['disney xd', 'toon disney'],
    'E!' => ['e entertainment television', 'e entertainment', 'e keeping up', 'e'],
    'ESPN' => ['espn'],
    'ESPN2' => ['espn2', 'espn 2'],
    'ESPN News' => ['espn news', 'espnews'],
    'ESPN U' => ['espnu', 'espn u'],
    'FanDuel TV' => ['fanduel tv', 'fanduel', 'tvg', 'fanduel racing'],
    'FanDuel TV Extra' => [],
    'Food Network' => ['food network'],
    'FOX (Michigan)' => ['fox 2 detroit', 'fox detroit news', 'fox 66 flint', 'fox 47 lansing', 'fox 17 grand rapids', 'fox grand rapids news'],
    'FOX Business' => ['fox business', 'foxbusiness', 'fbn'],
    'FOX News' => ['fox news', 'fnc'],
    'FOX Soul' => ['fox soul'],
    'FOX Weather' => ['fox weather'],
    'FOX 2 Detroit (US)' => [],
    'FOX 2 Detroit (PRIME)' => [],
    'FOX 17 Grand Rapids' => [],
    'FOX Grand Rapids' => [],
    'FOX Lansing' => [],
    'FOX Flint' => [],
    'Freeform' => ['freeform', 'abc family', 'fox family', 'the family channel', 'cbn family'],
    'FS1' => ['fs1', 'fox sports 1'],
    'FS2' => ['fs2', 'fox sports 2'],
    'Fubo Sports Network' => ['fubo sports network', 'fubo sports'],
    'Fubo Sports Network 2' => [],
    'Fubo Sports 2' => [],
    'FX' => ['fx'],
    'FXM' => ['fxm', 'fx movie channel', 'fox movie channel'],
    'FXX' => ['fxx', 'fox soccer'],
    'FYI' => ['fyi', 'bio', 'biography channel'],
    'Galavision' => ['galavision'],
    'Game Show Network' => ['game show network', 'gsn'],
    'Golf Channel' => ['golf channel', 'golf'],
    'Great American Family' => ['great american family', 'gac family', 'great american country', 'gac'],
    'Hallmark Channel' => ['hallmark channel', 'hallmark'],
    'Hallmark Mystery' => ['hallmark mystery', 'hallmark movies mysteries', 'hallmark movies and mysteries', 'hallmark movie channel'],
    'Hallmark Movies & More' => [],
    'Hallmark Family' => [],
    'HBO/Max' => ['hbo max', 'hbo', 'max', 'home box office'],
    'HBO 2' => [],
    'HGTV' => ['hgtv', 'home garden television', 'home and garden television'],
    'History & Warfare' => [],
    'History 2' => [],
    'The History Channel' => ['history channel', 'history'],
    'HLN' => ['hln', 'headline news', 'cnn headline news'],
    'IFC' => ['ifc', 'independent film channel'],
    'Investigation Discovery (ID)' => ['investigation discovery', 'id channel', 'discovery times'],
    'Justice Central' => ['justice central'],
    'Lifetime' => ['lifetime'],
    'Lifetime Movies' => ['lifetime movies'],
    'LiveNOW from FOX' => ['livenow from fox', 'fox livenow', 'livenow'],
    'LMN' => ['lmn', 'lifetime movie network'],
    'Magnolia Network' => ['magnolia network', 'diy network', 'magnolia'],
    'MeTV' => ['metv'],
    'MeTV Toons' => [],
    'MeTV Plus' => [],
    'MGM+' => ['mgm'],
    'MLB Network' => ['mlb network', 'mlbn'],
    'MotorTrend' => ['motortrend', 'motor trend'],
    'MS NOW' => ['ms now', 'msnow', 'msnbc'],
    'MTV' => ['mtv'],
    'MTV2' => ['mtv2', 'mtv 2'],
    'MTV Classic' => [],
    'MTV Live' => [],
    'MTV Music' => [],
    'MTV Xmas' => [],
    'National Geographic' => ['national geographic', 'nat geo', 'natgeo', 'ngc'],
    'National Geographic Wild' => ['national geographic wild', 'nat geo wild', 'natgeo wild', 'ngw'],
    'NBA TV' => ['nba tv', 'nba'],
    'NBC News NOW' => ['nbc news now'],
    'NBC Detroit' => [],
    'NBC Grand Rapids' => [],
    'Newsmax' => ['newsmax', 'news max'],
    'NewsNation' => ['newsnation', 'news nation'],
    'NFL Channel' => [],
    'NFL Network' => ['nfl network', 'nfln', 'nfl'],
    'NFL Network Lansing' => [],
    'NFL Network Grand Rapids' => [],
    'NFL Red Zone' => ['nfl redzone', 'nfl red zone', 'redzone', 'red zone'],
    'NHL Channel' => [],
    'NHL Network' => ['nhl network', 'nhln', 'nhl'],
    'Nick Jr.' => ['nick jr', 'nick junior', 'noggin'],
    'Nickelodeon' => ['nickelodeon', 'nick'],
    'Nicktoons' => ['nicktoons', 'nick toons'],
    'OWN' => ['own', 'oprah winfrey network'],
    'Oxygen' => ['oxygen', 'oxygen true crime'],
    'Paramount Network' => ['paramount network', 'paramount', 'spike'],
    'PBS' => ['pbs'],
    'PBS America' => [],
    'PBS Detroit' => [],
    'PBS Kids' => ['pbs kids', 'ptv'],
    'Pop (Pop TV)' => ['pop tv', 'pop'],
    'QVC' => ['qvc'],
    'Science' => [],
    'SEC Network' => ['sec network', 'secn'],
    'Showtime' => ['showtime', 'sho'],
    'Showtime 2' => [],
    'Smithsonian Channel' => ['smithsonian'],
    'Start TV' => ['start tv', 'starttv'],
    'Starz' => ['starz', 'encore'],
    'Starz 2' => [],
    'Starz Comedy' => [],
    'Starz Family' => [],
    'Sundance TV' => ['sundance tv', 'sundancetv', 'sundance channel', 'sundance'],
    'SYFY' => ['syfy', 'sci fi channel', 'sci fi'],
    'T2 Live Tennis' => ['tennis channel plus 2', 'tennis channel 2', 'tennis channel t2'],
    'Tastemade' => ['tastemade'],
    'TBS' => ['tbs'],
    'TCM' => ['tcm', 'turner classic movies'],
    'TeenNick' => ['teennick', 'teen nick'],
    'Telemundo' => ['telemundo'],
    'Tennis Channel' => ['tennis channel'],
    'TheGrio-TV' => ['thegrio tv', 'thegrio', 'the grio', 'grio tv', 'legacy tv'],
    'The CW (Michigan)' => ['cw 50 detroit'],
    'CW 50 Detroit' => [],
    'The Weather Channel' => ['weather channel', 'twc'],
    'TLC' => ['tlc', 'the learning channel'],
    'TNT' => ['tnt'],
    'Torque by History' => [],
    'Travel Channel' => ['travel channel', 'travel'],
    'True Crime Network' => ['true crime network', 'true crime', 'tcn', 'justice network'],
    'TruTV' => ['trutv', 'court tv'],
    'TUDN' => ['tudn', 'univision deportes network'],
    'TV Land' => ['tv land', 'tvland'],
    'UniMas' => ['unimas', 'telefutura'],
    'Univision' => ['univision'],
    'USA Network' => ['usa network', 'usa'],
    'VEVO 2K' => [],
    'VEVO 70s' => [],
    'VEVO 80s' => [],
    'VEVO 90s' => [],
    'VEVO Country' => [],
    'VEVO Hip-Hop' => [],
    'VEVO Latino' => [],
    'VEVO R&B' => [],
    'VEVO Reggaeton & Trap' => [],
    'VEVO' => ['vevo'],
    'VH1' => ['vh1', 'vh 1'],
    'Vice' => ['vice', 'viceland'],
    'WE tv' => ['we tv', 'wetv', 'we'],
    'World at War' => ['world at war'],
    'FOX (Michigan)' => ['fox 17 grand rapids'],
    'CBS (Michigan)' => ['cbs 62 detroit', 'cbs detroit news', 'cbs 5 saginaw', 'cbs 6 lansing', 'cbs grand rapids'],
    'NBC (Michigan)' => ['nbc 4 detroit', 'nbc 8 grand rapids'],
    'ABC (Michigan)' => ['abc 7 detroit', 'abc detroit news', 'abc 12 flint', 'abc 53 lansing', 'abc 13 grand rapids'],
    'PBS (Michigan)' => ['pbs detroit'],
    'ABC Detroit News' => [],
    'ABC 7 Detroit' => [],
    'ABC 12 Flint' => [],
    'ABC 13 Grand Rapids' => [],
    'ABC 53 Lansing' => [],
];

// Exclusions (user decisions 2026-11-08/09): channels that must never appear.
$excludedNames = [
    'TUBI| A&E CRIME 360 ᴿᴬᵂ', 'PRIME| A&E CRIME 360 ᴿᴬᵂ', 'PRIME| CHAOS ON CAM BY A&E ᴿᴬᵂ',
    'PRIME| CRIME CULTS KILLERS BY A&E ᴿᴬᵂ', 'PRIME| THE FIRST 48 BY A&E ᴿᴬᵂ',
    'Adult Swim Yule Log (aka The Fireplace) (2022)', "SC - Adult Swim's The Elephant (2025)",
    'TV| STORIES BY AMC ᴿᴬᵂ', 'PRIME| AMC THRILLERS ᴿᴬᵂ', 'PRIME| FV AMC EN ESPAÑOL US ᴿᴬᵂ',
    'US| BET GOSPEL', 'US| BET JAMS HD', 'US| BET SOUL',
    'EN - Le Pari, The Bet (1997) (FRENCH ENG-SUB) DIDIER BOURDON', 'EN - BET Awards (2024)',
    'EN - The Bet (2020)', 'EN - The Bet  (2020)', "EN - Nike's Big Bet  (2021)",
    "SC - Nike's Big Bet  (2021)", 'PRIME| BET X TYLER PERRY COMEDY ᴿᴬᵂ', 'PRIME| BET X TYLER PERRY DRAMA ᴿᴬᵂ',
    'Adult Swim Yule Log (aka The Fireplace) (2022)', "SC - Adult Swim's The Elephant (2025)",
    'US| NFL NETWORK (N551) LANSING', 'US| NFL NETWORK (N563) GRAND RAPIDS', // kept in the 2026-11-09 run; see Michigan rules
];

$decor = collect(['hd', 'fhd', 'uhd', 'qhd', 'sd', '4k', '8k', 'hevc', 'raw', 'vip', 'backup', '60fps', '50fps', '30fps', 'hifi', 'west', 'east', 'lower']);

function normName(string $raw): string
{
    $s = preg_replace('/^[^|]*\|\s*/', '', $raw);
    $s = str_replace('#', ' ', $s);

    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower($s))));
}

function stripDecor(string $s, $decor): string
{
    return collect(explode(' ', $s))->reject(fn ($t) => $decor->contains($t))->implode(' ');
}

$matches = collect($targets)->mapWithKeys(fn ($p, $k) => [$k => collect()]);

// Order targets so longer/more-specific patterns match first.
$ordered = collect($targets)
    ->sortByDesc(fn ($pats, $name) => $pats === [] ? -1 : max(array_map('strlen', $pats)) * 100 + str_word_count(implode(' ', $pats)))
    ->keys();

$all = DB::table('channels')->select('id', 'name', 'playlist_id')->orderBy('id')->get();
foreach ($all as $ch) {
    $base = normName($ch->name);
    if ($base === '') {
        continue;
    }
    $stripped = stripDecor($base, $decor);

    // Pass 1: exact alias equality (protects long aliases from short-alias theft).
    $hit = null;
    foreach ($ordered as $t) {
        if (in_array($base, $targets[$t], true) || in_array($stripped, $targets[$t], true)) {
            $hit = $t;
            break;
        }
    }

    // Pass 2: token-boundary containment. Aliases <=2 chars are exact-only.
    if ($hit === null) {
        foreach ($ordered as $t) {
            foreach ($targets[$t] as $p) {
                if (strlen($p) <= 2) {
                    continue;
                }
                $re = '/(?<![a-z0-9])'.preg_quote($p, '/').'(?![a-z0-9])/';
                if (preg_match($re, $stripped) || preg_match($re, $base)) {
                    $hit = $t;
                    break 2;
                }
            }
        }
    }

    if ($hit === null) {
        continue;
    }

    // ── Obviously-unrelated sweep (accumulated user decisions 2026-11-09) ──
    $lowerName = strtolower($ch->name);
    if (preg_match('/^(en|sc|nf|no) - /', $lowerName)                          // VOD movies
        || preg_match('#^24/7 #', $lowerName)                                  // 24/7 show binges
        || preg_match('/^(ended \| next \| live \| - no event)/', $lowerName)  // event replays/upcoming
        || str_contains($lowerName, 'ppv')
        || str_contains($lowerName, '8k exclusive')
        || str_contains($lowerName, '(espn+')
        || str_contains($lowerName, '(tsn+')
        || str_contains($lowerName, '(btn+')
        || str_contains($lowerName, '(stan ')
        || str_contains($lowerName, '(flsp')
        || preg_match('/ncaaf \d+|nhl \d+:|:nba \d+/i', $ch->name)
        || str_contains($lowerName, 'music choice')
        || str_contains($lowerName, 'stingray')
        || str_contains($lowerName, 'mc-pop')
        || in_array($ch->name, [
            '## MAX ESPN ᴴᴰ/ᴿᴬᵂ ⁶⁰ᶠᵖˢ ##',
            'TUBI| DEAL OR NO DEAL USA ᴿᴬᵂ',
            'TUBI| FEAR FACTOR USA ᴿᴬᵂ',
            'US| DRUNK HISTORY FHD',
            'EN| NETFLIX HISTORY',
            'US| NATIONAL TREASURE EDGE OF HISTORY ᴿᴬᵂ',
            'US| MIAMI VICE FHD',
        ], true)) {
        continue;
    }

    // AMC+ category: rows with the '+' brand go to AMC+.
    if ($hit === 'AMC' && preg_match('/AMC\s?\+/i', $ch->name)) {
        $hit = 'AMC+';
    }

    // ── Exclusions (accumulated user decisions 2026-11-08/09) ──
    if (in_array($ch->name, $excludedNames, true)) {
        continue;
    }
    if (str_contains($ch->name, '#####')) {
        continue; // divider channels
    }
    if (str_contains($ch->name, 'NFL TEAMS|')) {
        continue; // NFL team event channels
    }
    if (str_contains(strtolower($ch->name), 'btn+')) {
        continue;
    }
    if (str_contains(strtolower($ch->name), 'bloomberg originals') || str_contains(strtolower($ch->name), 'bloomberg quicktake')) {
        continue;
    }
    if (preg_match('/CBS NEWS (BAY AREA|BOSTON|CHICAGO|COLORADO|LOS ANGELES|MINNESOTA|NEW YORK|PHILADELPHIA|PITTSBURGH|TEXAS)/i', $ch->name)) {
        continue; // CBS News city locals except Detroit
    }
    if (preg_match('/CINEMAX (ACTION|MOREMAX|CLASSICS|HITS)/i', $ch->name)
        || preg_match('/CNN (HEADLINES|ORIGINALS)/i', $ch->name)
        || preg_match('/DISCOVERY\+/i', $ch->name)
        || preg_match('/ESPN PLAY EVENTS/i', $ch->name)
        || preg_match('/^(NL|CL)\| ESPN/i', $ch->name)
        || preg_match('/TNT SPORTS ESPN/i', $ch->name)
        || preg_match('/^ESPN PLUS \d+/i', $ch->name)
        || preg_match('/ESPN\+ \d+/i', $ch->name)
        || preg_match('/KAYO - ESPN/i', $ch->name)
        || preg_match('/\(AU\) ESPN PLAY/i', $ch->name)
        || preg_match('/E! KEEPING UP/i', $ch->name)
        || preg_match('/FOX SOCCER PLUS/i', $ch->name)
        || preg_match('/SKY SPORTS? GOLF|SUPERSPORTS GOLF|MOVISTAR GOLF|V SPORT GOLF|SPORT KLUB GOLF/i', $ch->name)
        || preg_match('/IFC FILMS PICKS|SLIGHTLY OFF IFC|LIFETIME REAL WOMEN|DANCE MOMS/i', $ch->name)
        || preg_match('/MGM\+ (DRIVE|HITS|MARQUEE)|MGM CELEBRATES|MGM PRESENTS/i', $ch->name)
        || preg_match('/ATN MTV|MTV URHEILU|MTV BASE|MTV CLUB|MTV 90S|MTV NORGE|MTV HITS|VIDEO MUSIC AWARDS/i', $ch->name)
        || preg_match('/NAT GEO SHARKS/i', $ch->name)
        || preg_match('/NBA FAST CHANNEL|NBA REPLAY|SKY SPORT NBA|DAZN NBA|:NBA\s*\d|NBA\s*\d+\s*:/i', $ch->name)
        || ($hit === 'NFL Network' && preg_match('/NFL NETWORK \(N\d+\)/i', $ch->name) && ! preg_match('/LANSING|GRAND RAPIDS|DETROIT/i', $ch->name))
        || preg_match('/DAZN NFL|LIVE NFL PLAYOFFS|NFL REPLAY/i', $ch->name)
        || preg_match('/NHL REPLAY/i', $ch->name)
        || preg_match('/PARAMOUNT\+\s*\d|:PARAMOUNT\+/i', $ch->name)
        || preg_match('/PARAMOUNT\+ ORIGINAL/i', $ch->name)
        || ($hit === 'PBS' && preg_match('/^TV\| [A-Z ,\.\']+ (W[A-Z]{2,4}|K[A-Z]{2,4}) PBS \d+/i', $ch->name) && ! str_contains($ch->name, 'WTVS'))
        || ($hit === 'PBS' && preg_match('/^US\| PBS /i', $ch->name) && ! str_contains($ch->name, 'DETROIT') && ! str_contains($ch->name, 'PBS HD'))
        || ($hit === 'PBS' && preg_match('/PBS \d+ \(VIRTUAL\)/i', $ch->name) && ! str_contains($ch->name, 'DETROIT'))
        || preg_match('/PBS (ANTIQUES ROADSHOW|DOCUMENTARIES|DRAMA|FOOD|GENEALOGY|NATURE)|PTV (GLOBAL|SPORTS)|PBS KIDS \(WTCI\)/i', $ch->name)
        || preg_match('/UK\| POP (& COUNTRY|HITS|LATINO)|SKY SPORT POP/i', $ch->name)
        || str_contains($ch->name, 'VEVO POP')
        || preg_match('/QVC (BEAUTY|EXTRA|STYLE|2)/i', $ch->name)
        || preg_match('/MYSTERY SCIENCE|SCIENCE IS AMAZING/i', $ch->name)
        || preg_match('/SHOWTIME (BET|EXTREME|FAMILY ?ZONE|NEXT|SHOWCASE|WOMEN)|24\/7 SHOWTIME/i', $ch->name)
        || $ch->id === 12511 // EN| AMC +
        || $ch->id === 7409 // US| AMC +
        || str_contains($ch->name, '24/7 MOVIES & SERIES')
        || str_contains($ch->name, 'BRAVO VAULT')
        || preg_match('/TORQUE BY HISTORY|HISTORY HUNTERS|HISTORY & UNDISCOVERED|HISTORY UNTOLD|MODERN INNOVATIONS BY HISTORY|TRUE HISTORY/i', $ch->name)
        || preg_match('/PBS ENCORE|SKY CINEMA SCI-?FI|NETFLIX SCI-?FI|UK-NOWTV\| SKY SCI-?FI|UK\| SKY SCI-?FI/i', $ch->name)
        || preg_match('/TENNIS CHANNEL PLUS [39]/i', $ch->name)
        || preg_match('/TNT (SPORT|ULTIMATE 4K|BOX OFFICE)/i', $ch->name)
        || preg_match('/NOTICIAS TELEMUNDO|TELEMUNDO (ACCIÓN|AL DIA)|TELEMUNDO NOTICIAS (CALIFORNIA|FLORIDA|NORESTE|TEXAS)/i', $ch->name)
        || preg_match('/TASTEMADE TRAVEL|TRAVEL \+ ADVENTURE|TRAVEL MAN 48|TRAVEL AND ESCAPE|PBS TRAVEL|TRAVEL AND ADVENTURE/i', $ch->name)
        || preg_match('/OXYGEN TRUE CRIME ARCHIVES|FILMRISE TRUE CRIME|TRUE CRIME XTRA|TRUE CRIME & SCANDALS/i', $ch->name)
        || preg_match('/COURT TV|ZONA TUDN|NOTICIAS UNIVISION/i', $ch->name)
        || preg_match('/24\/7 MASTERCHEF USA|^UK\| 5 USA|THE EAGLES - EARLYBIRD|WC - ANGLETERRE|FEAR FACTOR USA|TEAM USA|MIAMI VICE|SBS VICELAND|VICE NEWS/i', $ch->name)
        || preg_match('/ALL REALITY WE ?TV|ALL WEDDINGS WE ?TV|GROWING UP HIP HOP WE ?TV|LOVE AFTER LOCKUP WE ?TV/i', $ch->name)) {
        continue;
    }

    // West feeds diverted (except UniMas: its only row is UNIMAS WEST).
    if (str_contains(strtolower($ch->name), 'west') && $hit !== 'UniMas') {
        continue;
    }

    // Schedule-match pruning (researched online 2026-11-09, user: keep >=70% + borderlines).
    $groupName = '';
    $g = DB::table('groups')->where('id', $ch->group_id)->value('name');
    $groupName = $g ?? '';
    if ((str_starts_with($groupName, 'UK') || str_starts_with($groupName, 'D+|'))
        && ! preg_match('/BBC NEWS|BBC WORLD NEWS|NBC NEWS NOW|NFL|MTV XMAS/i', $ch->name)) {
        continue;
    }
    if (str_starts_with($groupName, 'CA|') && ! preg_match('/A&E|BET\b|BOOMERANG|CNBC|COOKING CHANNEL|E! |GAME SHOW|FYI|HLN|PARAMOUNT|THE WEATHER CHANNEL|TLC\b|IFC\b|NICKELODEON|DISNEY|AMC\+|CRIME & INVESTIGATION|BBC WORLD NEWS|CBS NEWS|CNN|FOX NEWS|MS NOW|FUBO|MLB|BLOOMBERG|ADULT SWIM|BRAVO|CARTOON NETWORK|STARZ 1|HGTV/i', $ch->name)) {
        continue;
    }
    if (str_starts_with($groupName, 'NZ|') && str_contains($ch->name, 'CNN')) {
        continue;
    }
    if (str_starts_with($groupName, 'AU|')) {
        continue;
    }
    if ($groupName === 'WORLD LIVE SPORTS') {
        continue;
    }
    if (str_starts_with($groupName, 'MU|') && ! str_contains($ch->name, 'MTV CLASSIC US')) {
        continue; // user restored these separately; scan keeps them out unless re-decided
    }
    if ($groupName === '4K UHD 3840P' && str_contains($ch->name, 'ESPN UHD')) {
        continue;
    }
    if ($hit === 'Telemundo' && preg_match('/TELEMUNDO( \d+)? \([A-Z]{3,4}(-DT[0-9]?)?(-[LC]D)?\)?/i', $ch->name) && ! str_contains($ch->name, '(EAST)') && ! preg_match('/MICHIGAN|DETROIT|LANSING|GRAND RAPIDS|FLINT/i', $ch->name)) {
        continue;
    }
    if ($hit === 'CBS News' && preg_match('/CBS NEWS (BAY AREA|BOSTON|CHICAGO|COLORADO|LOS ANGELES|MINNESOTA|NEW YORK|PHILADELPHIA|PITTSBURGH|TEXAS)/i', $ch->name)) {
        continue;
    }

    $matches[$hit]->push(['id' => $ch->id, 'name' => $ch->name]);
}

// Emit the id => target map for channel-probe.php.
echo "\$channels = [\n";
foreach (collect($targets)->keys()->sort(SORT_NATURAL | SORT_FLAG_CASE) as $t) {
    foreach ($matches[$t] as $row) {
        echo '    '.$row['id'].' => '.var_export($t, true).",\n";
    }
}
echo "];\n";

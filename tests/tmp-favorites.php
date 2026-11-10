<?php

// Create "Favorites" group: copies of the best channel for each requested target,
// each carrying its failover chain (failover channels NOT copied into the group).

// Source channel ids = primary of each target in channel-probe-results.csv order.
$sourceIdsByTarget = [
    'FOX 2 Detroit (US)' => 'fox 2 wjbk',
    'NBC Detroit' => 'nbc wdiv',
    'ABC 7 Detroit' => 'abc wxyz',
    'CBS Michigan: CBS 62 Detroit' => 'cbs wwj',
    'Hallmark Channel' => null,
    'Hallmark Mystery' => null,
    'Hallmark Movies & More' => null,
    'Hallmark Family' => null,
    'Lifetime' => null,
    'Lifetime Movies' => null,
    'LMN' => null,
    'Discovery Turbo' => null,
    'MotorTrend' => null,
    'Discovery Velocity' => null,
];

// Resolve each target's primary from the existing failover table (created from the CSV).
$primaries = [];
foreach (array_keys($sourceIdsByTarget) as $target) {
    // Find the primary by locating the target's top channel in the sorted results:
    // primaries are the channels that OWN failover rows, or (single-channel targets)
    // the only channel in the target. Use the CSV instead for reliability.
    $primaries[$target] = null;
}

// Read the CSV (priority-sorted) for the top row of each target.
$lines = file('/tmp/probe-results.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$top = [];
foreach ($lines as $line) {
    if (str_starts_with($line, 'target_channel') || str_starts_with($line, '=') || str_starts_with($line, '(') || trim($line) === '' || ! str_contains($line, ',')) {
        continue;
    }
    $cols = str_getcsv($line, escape: '\\');
    if (! isset($cols[1]) || ! ctype_digit($cols[1])) {
        continue;
    }
    if (! isset($top[$cols[0]])) {
        $top[$cols[0]] = (int) $cols[1];
    }
}

foreach (array_keys($sourceIdsByTarget) as $target) {
    if (! isset($top[$target])) {
        echo "NO CHANNELS for target [$target]\n";
        continue;
    }
    $primaries[$target] = $top[$target];
}

auth()->login(App\Models\User::find(1));

$group = App\Models\Group::create([
    'name' => 'Favorites',
    'name_internal' => 'Favorites',
    'playlist_id' => 1,
    'type' => 'live',
    'user_id' => 1,
    'custom' => true,
    'sort_order' => 3, // after Detroit (2); shift everything >= 3 down by 1
]);
App\Models\Group::where('type', 'live')->where('sort_order', '>=', 3)->where('id', '!=', $group->id)->increment('sort_order');

$sort = 1;
foreach ($primaries as $target => $sourceId) {
    $source = App\Models\Channel::find($sourceId);
    if (! $source) {
        echo "MISSING source #$sourceId for $target\n";
        continue;
    }

    $copy = $source->replicate();
    $copy->group = 'Favorites';
    $copy->group_id = $group->id;
    $copy->is_custom = true;
    $copy->source_id = null;
    $copy->sort = $sort;
    $copy->save();
    $sort++;

    // Mirror the source's failover chain onto the copy (failover channels stay
    // as-is; they are NOT copied into this group).
    foreach ($source->failoverChannels()->get() as $i => $failover) {
        App\Models\ChannelFailover::create([
            'user_id' => 1,
            'channel_id' => $copy->id,
            'channel_failover_id' => $failover->id,
            'sort' => $i + 1,
        ]);
    }

    echo "#$copy->id [$copy->name] (from #$sourceId, ".App\Models\ChannelFailover::where('channel_id', $copy->id)->count()." failovers)\n";
}

echo 'DONE. group id: '.$group->id.' | channels: '.($sort - 1)."\n";

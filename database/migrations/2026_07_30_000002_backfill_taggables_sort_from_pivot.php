<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Channel;

return new class extends Migration
{
    public function up(): void
    {
        // Join through custom_playlists to bridge integer FK → UUID string.
        // channel_custom_playlist.custom_playlist_id is an int FK, while
        // taggables.type stores the UUID string from custom_playlists.uuid.
        $pivotRows = DB::table('channel_custom_playlist')
            ->join('custom_playlists', 'custom_playlists.id', '=', 'channel_custom_playlist.custom_playlist_id')
            ->select(
                'channel_custom_playlist.channel_id',
                'custom_playlists.uuid',
                'channel_custom_playlist.sort'
            )
            ->get();

        foreach ($pivotRows as $pivot) {
            DB::table('taggables')
                ->where('taggable_id', $pivot->channel_id)
                ->where('taggable_type', Channel::class)
                ->where('type', $pivot->uuid)  // Match UUID string, NOT integer ID
                ->update(['sort' => $pivot->sort]);
        }
    }

    public function down(): void
    {
        DB::table('taggables')
            ->whereNotNull('sort')
            ->update(['sort' => null]);
    }
};

<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Services\PlaylistService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class AddItemsToCustomPlaylistChunk implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of the Channel or Series records in this chunk
     * @param  int|null  $tagId  Custom group/category tag applied to every item in the
     *                           chunk (null: add to the playlist without tagging). The tag
     *                           must already exist; the parent job pre-creates it because
     *                           Tag::findOrCreate is not race-safe across parallel chunks.
     * @param  string  $mode  'original' replaces each item's playlist-type tags (mirroring
     *                         the source playlist); 'select'/'create' append the tag on
     *                         top of existing ones, preserving user-assigned groups and
     *                         their per-group taggables.sort values.
     */
    public function __construct(
        public int $customPlaylistId,
        public array $itemIds,
        public ?int $tagId = null,
        public string $type = 'channel',
        public string $mode = 'select',
    ) {}

    /**
     * Execute the job.
     *
     * Every statement is idempotent and conflict-safe (insertOrIgnore, set-based
     * deletes on disjoint item IDs), so chunks of the same batch can run in
     * parallel across queue workers.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $playlist = CustomPlaylist::find($this->customPlaylistId);
        if (! $playlist) {
            return;
        }

        $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, $this->type);

        DB::table($meta['pivotTable'])->insertOrIgnore(
            array_map(fn (int $id): array => [
                $meta['pivotForeignKey'] => $this->customPlaylistId,
                $meta['pivotRelatedKey'] => $id,
            ], $this->itemIds)
        );

        $tag = $this->tagId !== null ? Tag::find($this->tagId) : null;
        if ($tag) {
            if ($this->mode === 'original') {
                // Original mode mirrors the source playlist — replace tags so an item
                // that moved groups shows only its new group.
                $playlistTagIds = $playlist->{$meta['tagFunction']}()->pluck('tags.id')->all();

                PlaylistService::retagItems($meta, $playlistTagIds, $tag, $this->itemIds);
            } else {
                // Select/create mode: append the tag on top of existing ones (preserving
                // user-assigned groups). insertOrIgnore skips rows that already exist,
                // so an item already carrying this tag keeps its taggables.sort value.
                DB::table('taggables')->insertOrIgnore(array_map(fn (int $id): array => [
                    'tag_id' => $tag->id,
                    'taggable_id' => $id,
                    'taggable_type' => $meta['itemModel'],
                ], $this->itemIds));
            }
        }
    }
}

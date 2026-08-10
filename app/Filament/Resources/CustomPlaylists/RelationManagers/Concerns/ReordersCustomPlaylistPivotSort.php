<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers\Concerns;

use App\Models\Channel;
use App\Services\EpgCacheService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait ReordersCustomPlaylistPivotSort
{
    /**
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        // Check if we're on a group tab — if so, do per-group reorder on taggables.sort
        $activeTagId = method_exists($this, 'getActiveGroupTagId') ? $this->getActiveGroupTagId() : null;
        if ($activeTagId) {
            $this->getTable()->callBeforeReordering($order);
            \DB::transaction(function () use ($order, $activeTagId): void {
                // Collect existing sort values for this group's channels, sorted ascending.
                // This mirrors how getCustomPlaylistPivotSortSlots() works for the "All" tab —
                // we keep the existing sort "slots" and just reassign which channel gets which value.
                $existingSorts = \DB::table('taggables')
                    ->where('tag_id', $activeTagId)
                    ->where('taggable_type', Channel::class)
                    ->whereIn('taggable_id', array_values($order))
                    ->pluck('sort')
                    ->sort()
                    ->values()
                    ->toArray();

                // Fill any NULL slots with sequential values after the max
                $maxSort = collect($existingSorts)->filter(fn ($v) => $v !== null)->max() ?? 0;
                $nextNew = $maxSort + 1;
                $existingSorts = array_map(function ($v) use (&$nextNew) {
                    if ($v === null) {
                        return $nextNew++;
                    }

                    return $v;
                }, $existingSorts);

                foreach (array_values($order) as $index => $recordKey) {
                    \DB::table('taggables')
                        ->where('tag_id', $activeTagId)
                        ->where('taggable_id', $recordKey)
                        ->where('taggable_type', Channel::class)
                        ->update(['sort' => $existingSorts[$index] ?? ($nextNew++)]);
                }
            });
            // Clear EPG cache after reorder
            EpgCacheService::clearForCustomPlaylistId($this->getOwnerRecord()->id);
            $this->getTable()->callAfterReordering($order);

            return;
        }

        $orderColumn = (string) str($this->getTable()->getReorderColumn())->afterLast('.');
        $relationship = $this->getTable()->getRelationship();

        if (
            ($relationship instanceof BelongsToMany) &&
            in_array($orderColumn, $relationship->getPivotColumns(), true) &&
            $this->isCustomPlaylistPivotSortReorder($relationship, $orderColumn)
        ) {
            $this->getTable()->callBeforeReordering($order);

            DB::transaction(function () use ($order, $orderColumn, $relationship): void {
                $slots = $this->getCustomPlaylistPivotSortSlots($order, $orderColumn, $relationship);

                foreach (array_values($order) as $index => $recordKey) {
                    $this->getTableRecord($recordKey)
                        ->getRelationValue($relationship->getPivotAccessor())
                        ->update([
                            $orderColumn => $slots->get($index, $index + 1),
                        ]);
                }
            });

            $this->getTable()->callAfterReordering($order);

            return;
        }

        $this->filamentReorderTable($order, $draggedRecordKey);
    }

    private function isCustomPlaylistPivotSortReorder(BelongsToMany $relationship, string $orderColumn): bool
    {
        return $orderColumn === 'sort'
            && in_array($relationship->getTable(), ['channel_custom_playlist', 'series_custom_playlist'], true);
    }

    /**
     * @param  array<int|string>  $order
     * @return Collection<int, float|int>
     */
    private function getCustomPlaylistPivotSortSlots(array $order, string $orderColumn, BelongsToMany $relationship): Collection
    {
        $recordKeys = array_values($order);
        $related = $relationship->getRelated();
        $relatedTable = $related->getTable();
        $keyName = $related->getKeyName();

        $records = $relationship
            ->whereIn("{$relatedTable}.{$keyName}", $recordKeys)
            ->get(["{$relatedTable}.{$keyName}", "{$relatedTable}.sort"])
            ->keyBy(fn ($record): string => (string) $record->getKey());

        $slots = collect($recordKeys)
            ->map(function ($recordKey) use ($records, $orderColumn): mixed {
                $record = $records->get((string) $recordKey);

                return $record?->pivot?->{$orderColumn} ?? $record?->sort;
            })
            ->filter(fn ($slot): bool => $slot !== null && $slot !== '')
            ->map(fn ($slot): float => (float) $slot)
            ->sort()
            ->values();

        if ($slots->isEmpty()) {
            return collect(range(1, count($recordKeys)));
        }

        $nextSlot = (float) $slots->last();
        while ($slots->count() < count($recordKeys)) {
            $nextSlot++;
            $slots->push($nextSlot);
        }

        return $slots;
    }
}

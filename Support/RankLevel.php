<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use App\Models\Rank;

class RankLevel
{
    public static function of(?Rank $rank): int
    {
        if ($rank === null) {
            return 0;
        }
        return self::levels()[$rank->id] ?? 0;
    }

    /**
     * @return array<int,int> rank id => 0-based level, ranks ordered by hours then id
     */
    public static function levels(): array
    {
        $levels = [];
        $ids = Rank::withTrashed()->orderBy('hours')->orderBy('id')->pluck('id');
        foreach ($ids as $level => $id) {
            $levels[$id] = $level;
        }
        return $levels;
    }
}

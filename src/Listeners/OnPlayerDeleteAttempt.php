<?php

namespace LittleSkin\PremiumVerification\Listeners;

use Blessing\Filter;
use Blessing\Rejection;
use LittleSkin\PremiumVerification\Models\Premium;

class OnPlayerDeleteAttempt {
    /** @var Filter */
    protected $filter;

    public function __construct(Filter $filter)
    {
        $this->filter = $filter;
    }

    public function handle() {
        $this->filter->add('can_delete_player', function($can, $player) {
            if(Premium::where('pid', $player->pid)->count()) {
                return new Rejection(trans("LittleSkin\PremiumVerification::premium.player-cannot-be-deleted"));
            }
            return $can;
        });
    }
}

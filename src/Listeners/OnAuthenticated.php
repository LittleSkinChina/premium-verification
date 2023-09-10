<?php

namespace LittleSkin\PremiumVerification\Listeners;

use App\Models\Player;
// use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use LittleSkin\PremiumVerification\Models\MicrosoftOIDCConnection as Connection;
use LittleSkin\PremiumVerification\Models\Premium;

class OnAuthenticated {
    protected $connection;
    protected $filter;
    protected $route;
    protected $premium;

    public function __construct(Filter $filter, Request $request) {
        $this->filter = $filter;
        $this->route = $request->path();
    }

    public function handle($event) {
        if($this->premium = Premium::where('uid', $event->user->uid)->first()) {
            View::composer('LittleSkin\PremiumVerification::premium', function ($view) {
                $player = Player::where('pid', $this->premium->pid)->first();

                $view->with('premium', [
                    'uuid' => preg_replace('/(\w{8})(\w{4})(\w{4})(\w{4})(\w{12})/', '$1-$2-$3-$4-$5', $this->premium->uuid),
                    'pid' => $this->premium->pid . ($player ? ' (' . $player->name . ')' : trans('LittleSkin\PremiumVerification::premium.deleted')),
                    'date' => $this->premium->created_at,
                ]);
            });
        } else {
            View::composer('LittleSkin\PremiumVerification::premium', function ($view) {
                $view->with('score', option('premium_verification_score_award', 0));
            });
        }

        switch($this->route) {
            case 'user/player':
                $this->filter->add('grid:user.player', function ($grid) {
                    array_splice($grid['widgets'][0][0], 1, 0, 'LittleSkin\PremiumVerification::premium');
                    return $grid;
                });

                // Hook::addScriptFileToPage(plugin('premium-verification')->assets('update-profile.js'), ['user/player']);

                break;
            case 'user/profile':
                if($this->connection = Connection::where('uid', $event->user->uid)->first()) {
                    View::composer('LittleSkin\PremiumVerification::microsoftoidc', function ($view) {
                        $view->with('microsoftoidc', [
                            'premium' => $this->premium ? true : false,
                            'date' => $this->connection->created_at,
                        ]);
                    });
                }
                $this->filter->add('grid:user.profile', function ($grid) {
                    array_splice($grid['widgets'][0][1], 2, 0, 'LittleSkin\PremiumVerification::microsoftoidc');
                    return $grid;
                });

                break;
        }
    }
}

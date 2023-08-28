<?php

namespace LittleSkin\PremiumVerification\Listeners;

use LittleSkin\PremiumVerification\Models\MicrosoftOIDCConnection as Connection;
use LittleSkin\PremiumVerification\Models\Premium;

class OnUserDeleting {
    public function handle($event) {
        $uid = $event->uid;
        if($premium = Premium::where('uid', $uid)->first()) {
            $premium->delete();
        }
        if($connection = Connection::where('uid', $uid)->first()) {
            $connection->delete();
        }
    }
}

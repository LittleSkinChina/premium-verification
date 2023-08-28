<?php

namespace LittleSkin\PremiumVerification;

use Option;

class Configuration
{
    public function render()
    {
        $form = Option::form(
            'premium_verification',
            trans('LittleSkin\PremiumVerification::config.score_config'),
            function ($form) {
                $form->text(
                    'premium_verification_score_award',
                    trans('LittleSkin\PremiumVerification::config.score_award')
                )->placeholder(trans('LittleSkin\PremiumVerification::config.default'))
                    ->description(trans('LittleSkin\PremiumVerification::config.description'));
            })->handle();

        return view('LittleSkin\PremiumVerification::config', compact('form'));
    }
}

<?php

namespace LittleSkin\PremiumVerification\Providers;

use SocialiteProviders\Manager\SocialiteWasCalled;

class ExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled)
    {
        $socialiteWasCalled->extendSocialite('microsoftoidc', __NAMESPACE__.'\MicrosoftOIDCProvider');
        $socialiteWasCalled->extendSocialite('premium', __NAMESPACE__.'\PremiumProvider');
    }
}

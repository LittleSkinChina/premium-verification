<?php

use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

return function (Dispatcher $events, Filter $filter, Request $request) {

    $config = [
        'client_id' => env('MICROSOFT_CLIENT_KEY'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => 'consumers',
    ];

    config(['services.microsoftoidc' => $config + [
        'redirect' => env('MICROSOFT_LOGIN_REDIRECT_URI'),
    ]]);

    config(['services.premium' => $config + [
        'redirect' => env('MICROSOFT_PREMIUM_REDIRECT_URI'),
    ]]);

    if ($request->is('auth/login') || $request->is('auth/register')) {
        $filter->add('oauth_providers', function (Collection $providers) {
            $providers->put('microsoftoidc', [
                'icon' => 'microsoft',
                'displayName' => 'Microsoft',
            ]);

            return $providers;
        });
    }

    $events->listen(
        'SocialiteProviders\Manager\SocialiteWasCalled',
        'LittleSkin\PremiumVerification\Providers\ExtendSocialite@handle'
    );

    $events->listen(
        Illuminate\Auth\Events\Authenticated::class,
        'LittleSkin\PremiumVerification\Listeners\OnAuthenticated@handle'
    );

    $events->listen(
        'user.deleting',
        'LittleSkin\PremiumVerification\Listeners\OnUserDeleting@handle'
    );

    Hook::addRoute(function () {
        Route::prefix('user/premium')
            ->middleware(['web', 'auth'])
            ->namespace('LittleSkin\PremiumVerification\Controllers')
            ->group(function () {
                Route::get('verify', 'VerificationController@verify');
                Route::get('callback', 'VerificationController@callback');
                Route::post('update', 'VerificationController@update');
            });

        Route::prefix('microsoftoidc')
            ->middleware(['web'])
            ->namespace('LittleSkin\PremiumVerification\Controllers')
            ->group(function () {
                Route::get('connect', 'MicrosoftOIDCConnectController@redirect');
                Route::get('callback', 'MicrosoftOIDCConnectController@callback');
                Route::get('disconnect', 'MicrosoftOIDCConnectController@disconnect');
        });
    });
};

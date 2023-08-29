<?php

namespace LittleSkin\PremiumVerification\Controllers;
use App;
use App\Models\User;
use Auth;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use LittleSkin\PremiumVerification\Models\MicrosoftOIDCConnection as Connection;
use LittleSkin\PremiumVerification\Models\Premium;
use Str;

class MicrosoftOIDCConnectController extends Controller {

    public function redirect() {
        $user = auth()->user();
        if(Connection::where('uid', $user->uid)->first()) {
            abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.already-connected'));
        }
        return Socialite::driver('microsoftoidc')->redirect();
    }

    public function callback(Dispatcher $dispatcher, Request $request) {
        if($request->has('error')) {
            $error = $request->get('error');
            if($error == 'access_denied') {
                abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.authorize-cancelled'));
            } else {
                abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.error').
                    'error: ' . $error . ', error_description: ' . $request->get('error_description'));
            }
        }

        /** @var App\Models\User $user */
        $user = auth()->user();
        $remoteUser = Socialite::driver('microsoftoidc')->user();
        $connection = Connection::where('oid', $remoteUser->id)->first();

        if($user) {
            if($connection && $connection->uid != $user->uid) {
                abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.connected-by-other'));
            }
            if(Connection::where('uid', $user->uid)->first()) {
                abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.already-connected'));
            }
            Connection::create([
                'uid' => $user->uid,
                'oid' => $remoteUser->id,
            ]);
            return redirect('/user');
        } elseif ($connection) {
            $user = User::where('uid', $connection->uid)->first();

            $dispatcher->dispatch('auth.login.ready', [$user]);
            Auth::login($user);
            $dispatcher->dispatch('auth.login.succeeded', [$user]);

            return redirect('/user');
        } else {
            $user = User::where('email', $remoteUser->email)->first();
            if($user && !Connection::where('uid', $user->uid)->first()) {
                $code = Str::random(8);
                Cache::put('microsoftoidc-inherit-' . $code, [
                    'uid' => $user->uid,
                    'oid' => $remoteUser->id,
                ], 300);
                session()->put('microsoftoidc-inherit', $code);
                return view('LittleSkin\PremiumVerification::inherit')->with('email', $remoteUser->email);
            }
            session()->put('msg', trans('LittleSkin\PremiumVerification::microsoftoidc.not-found'));
            return redirect('/auth/login');
        }
    }

    public function disconnect() {
        $user = auth()->user();
        if(Premium::where('uid', $user->uid)->first()) {
            abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.cannot-disconnect'));
        }
        if($connection = Connection::where('uid', $user->uid)->first()) {
            $connection->delete();
            return redirect('/user/profile');
        } else {
            abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.not-connected'));
        }
    }

    public function inherit() {
        $code = session()->get('microsoftoidc-inherit');
        $cache = Cache::get('microsoftoidc-inherit-' . $code);
        if($cache && !Connection::where('oid', $cache['oid'])->first()) {
            $user = User::where('uid', $cache['uid'])->first();
            if($user) {
                Connection::create([
                    'uid' => $cache['uid'],
                    'oid' => $cache['oid'],
                ]);
                Auth::login($user);
                Cache::delete('microsoftoidc-inherit-' . $code);
                return redirect('/user');
            }
        }
        Cache::delete('microsoftoidc-inherit-' . $code);
        session()->put('msg', trans('auth.check.anonymous'));
        return redirect('/auth/login');
    }
}

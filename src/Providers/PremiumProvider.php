<?php

namespace LittleSkin\PremiumVerification\Providers;

use Auth;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LittleSkin\PremiumVerification\Providers\MicrosoftOIDCProvider as BaseProvider;
use LittleSkin\PremiumVerification\Models\MicrosoftOIDCConnection as Connection;

class PremiumProvider extends BaseProvider {
    public const IDENTIFIER = 'premium';

    public const MOJANG_PUBLIC_KEY = <<<EOD
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAtz7jy4jRH3psj5AbVS6W
NHjniqlr/f5JDly2M8OKGK81nPEq765tJuSILOWrC3KQRvHJIhf84+ekMGH7iGlO
4DPGDVb6hBGoMMBhCq2jkBjuJ7fVi3oOxy5EsA/IQqa69e55ugM+GJKUndLyHeNn
X6RzRzDT4tX/i68WJikwL8rR8Jq49aVJlIEFT6F+1rDQdU2qcpfT04CBYLM5gMxE
fWRl6u1PNQixz8vSOv8pA6hB2DU8Y08VvbK7X2ls+BiS3wqqj3nyVWqoxrwVKiXR
kIqIyIAedYDFSaIq5vbmnVtIonWQPeug4/0spLQoWnTUpXRZe2/+uAKN1RY9mmaB
pRFV/Osz3PDOoICGb5AZ0asLFf/qEvGJ+di6Ltt8/aaoBuVw+7fnTw2BhkhSq1S/
va6LxHZGXE9wsLj4CN8mZXHfwVD9QG0VNQTUgEGZ4ngf7+0u30p7mPt5sYy3H+Fm
sWXqFZn55pecmrgNLqtETPWMNpWc2fJu/qqnxE9o2tBGy/MqJiw3iLYxf7U+4le4
jM49AUKrO16bD1rdFwyVuNaTefObKjEMTX9gyVUF6o7oDEItp5NHxFm3CqnQRmch
HsMs+NxEnN4E9a8PDB23b4yjKOQ9VHDxBxuaZJU60GBCIOF9tslb7OAkheSJx5Xy
EYblHbogFGPRFU++NrSQRX0CAwEAAQ==
-----END PUBLIC KEY-----
EOD;

    protected $scopes = ['openid', 'email', 'profile', 'XboxLive.signin'];

    protected $key;

    public function user() {
        /* @var \Auth::User() $user */
        $user = auth()->user();
        parent::user();
        $connection = Connection::where('uid', $user->uid)->first();

        if($connection && $connection->oid != $this->user->id) {
            abort(403, trans('LittleSkin\PremiumVerification::premium.wrong-msa'));
        }

        if(Connection::where('oid', $this->user->id)->where('uid', '!=', $user->uid)->first()) {
            abort(403, trans('LittleSkin\PremiumVerification::microsoftoidc.connected-by-other'));
        }

        // Authenticate with Xbox Live
        $response = Http::post('https://user.auth.xboxlive.com/user/authenticate', [
            'Properties' => [
                'AuthMethod' => 'RPS',
                'SiteName' => 'user.auth.xboxlive.com',
                'RpsTicket' => 'd='.$this->user->token,
            ],
            'RelyingParty' => 'http://auth.xboxlive.com',
            'TokenType' => 'JWT',
        ])->json();

        $xbl_token = $response['Token'];
        $user_hash = $response['DisplayClaims']['xui'][0]['uhs'];

        // Authenticate with Xbox Security Token Service (XSTS)
        $response = Http::post('https://xsts.auth.xboxlive.com/xsts/authorize', [
            'Properties' => [
                'SandboxId' => 'RETAIL',
                'UserTokens' => [$xbl_token],
            ],
            'RelyingParty' => 'rp://api.minecraftservices.com/',
            'TokenType' => 'JWT',
        ])->json();

        if(Arr::exists($response, 'XErr')) { // https://wiki.vg/Microsoft_Authentication_Scheme#Obtain_XSTS_token_for_Minecraft
            switch ($response['XErr']) {
                case 2148916233: // The account doesn't have an Xbox account.
                    // This shouldn't happen with accounts that have purchased Minecraft with a Microsoft account,
                    // as they would've already gone through that Xbox signup process.
                    abort(403, trans('LittleSkin\PremiumVerification::premium.not-purchased'));
                case 2148916235: // The account is from a country where Xbox Live is not available/banned
                    abort(403, trans('LittleSkin\PremiumVerification::premium.error.xbl-unavailable'));
                case 2148916236: // The account needs adult verification on Xbox page. (South Korea)
                    abort(403, trans('LittleSkin\PremiumVerification::premium.error.adult-verification'));
                case 2148916237: // The account needs adult verification on Xbox page. (South Korea)
                    abort(403, trans('LittleSkin\PremiumVerification::premium.error.adult-verification'));
                case 2148916238: // The account is a child (under 18) and cannot proceed unless the account is added to a Family by an adult.
                    abort(403, trans('LittleSkin\PremiumVerification::premium.error.child-account'));
                default:
                    abort(500, trans('LittleSkin\PremiumVerification::premium.error.xsts-unauthorized') .
                    'XErr: ' . $response['XErr'] . ', Message: ' . $response['Message']
                );
            }
        }

        $xsts_token = $response['Token'];

        // Authenticate with Minecraft
        $response = Http::post('https://api.minecraftservices.com/authentication/login_with_xbox', [
            'identityToken' => 'XBL3.0 x='.$user_hash.';'.$xsts_token,
        ])->json();

        $accessToken = $response['access_token'];

        $this->key = new Key(self::MOJANG_PUBLIC_KEY, 'RS256');

        // Check Game Ownership
        $response = Http::withToken($accessToken)->get('https://api.minecraftservices.com/entitlements/mcstore')->json();

        try {
            if(!count($response['items'])) {
                abort(403, trans('LittleSkin\PremiumVerification::premium.not-purchased'));
            }

            JWT::$leeway = 30;

            $items = array_column($response['items'], 'name');
            $entitlements = array_column(JWT::decode($response['signature'], $this->key)->entitlements, 'name');

            if(!(count($items) == count($entitlements) && array_diff($items, $entitlements) === array_diff($entitlements, $items))) {
                abort(403, trans('LittleSkin\PremiumVerification::general.error.invalid-jwt'));
            }

            foreach($response['items'] as $item) {
                if(JWT::decode($item['signature'], $this->key)->name != $item['name']) {
                    abort(403, trans('LittleSkin\PremiumVerification::general.error.invalid-jwt'));
                }
            }

            // Xbox Game Pass 用户也是正版用户
            $haystack = ['game_minecraft', 'product_minecraft', 'product_game_pass_pc', 'product_game_pass_ultimate'];
            if(!count(array_intersect($haystack, $items))) {
                abort(403, trans('LittleSkin\PremiumVerification::premium.not-purchased'));
            }
        } catch (BeforeValidException | ExpiredException | SignatureInvalidException) {
            abort(403, trans('LittleSkin\PremiumVerification::general.error.invalid-jwt'));
        } catch (\Exception) {
            abort(500, trans('LittleSkin\PremiumVerification::general.error.jwt-exception'));
        }

        // Get Minecraft Profile
        $response = Http::withToken($accessToken)->get('https://api.minecraftservices.com/minecraft/profile')->json();

        if(Arr::exists($response, 'error')) {
            if($response['errorType'] == 'NOT_FOUND') {
                abort(404, trans('LittleSkin\PremiumVerification::premium.profile-not-found'));
            } else {
                abort(500, trans('LittleSkin\PremiumVerification::premium.error.other') . $response['errorMessage']);
            }
        }

        $this->user->user['uuid'] = $response['id'];
        $this->user->user['player'] = $response['name'];
        return $this->user;
    }
}

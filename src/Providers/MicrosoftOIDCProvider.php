<?php

namespace LittleSkin\PremiumVerification\Providers;

use Illuminate\Support\Arr;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

use Illuminate\Support\Facades\Cache;

class MicrosoftOIDCProvider extends AbstractProvider {

    public const IDENTIFIER = 'MICROSOFTOIDC';

    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    /**
     * 返回缓存的 JWKs 数组，如果没有缓存就请求然后缓存
     *
     * Firebase\JWT\CachedKeySet 要一个 PSR-6 兼容的缓存池实例，
     * 但是 Laravel 的 Cache Facade 不是 PSR-6 兼容的，
     * 所以这里自己实现了一个缓存 JWKs 的方法。
     *
     * @return array
     */
    protected function getJwks() {
        return Cache::get('microsoftoidc-jwks', function () {
            $url = 'https://login.microsoftonline.com/consumers/discovery/v2.0/keys';
            $jwks = json_decode(file_get_contents($url), true);
            Cache::put('microsoftoidc-jwks', $jwks, 86400);
            return $jwks;
        });
    }

    /**
     * Get the authentication URL for the provider.
     *
     * You must use the consumers AAD tenant to sign in with the XboxLive.signin scope.
     * Using an Azure AD tenant ID or the common scope will just give errors.
     * This also means you cannot sign in with users that are in the AAD tenant,
     * only with consumer Microsoft accounts.
     * https://wiki.vg/Microsoft_Authentication_Scheme
     */
    protected function getAuthUrl($state) {
        return $this->buildAuthUrlFromBase('https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize', $state);
    }

    protected function getTokenUrl() {
        return 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';
    }

    /**
     * 通过 id_token 获取用户信息
     *
     * Microsoft Identity Platform 限制一个 accessToken 只能对应获取一个资源，
     * 所以 XboxLive.signin 和 User.Read 不能一起请求，这样要拿 OpenID 就会变得很麻烦。
     * 但是走 OpenID Connect 的话，返回的 id_token 里就会包含用户信息，
     * 就不需要 User.Read 这个 scope 了，
     * 所以这里直接用了 id_token 里的用户信息。
     *
     */
    protected function getUserByToken($id_token) {
        JWT::$leeway = 30;

        try {
            $jwks = JWK::parseKeySet(self::getJwks(), 'RS256');
        } catch (\Exception) {
            abort(500, trans('LittleSkin\PremiumVerification::general.error.jwk-exception'));
        }

        try {
            $decoded = JWT::decode($id_token, $jwks);
        } catch (BeforeValidException | ExpiredException | SignatureInvalidException $e) {
            abort(403, trans('LittleSkin\PremiumVerification::general.error.invalid-jwt'));
        } catch (\Exception) {
            abort(500, trans('LittleSkin\PremiumVerification::general.error.jwt-exception'));
        }


        $user = [
            'openid' => $decoded->oid, // 这个 openid 是 OpenID Connect 的 oid，和 Graph API 拿到的 id 不一样
            'email' => $decoded->email,
            'nickname' => $decoded->name,
        ];

        return $user;
    }

    protected function mapUserToObject(array $user) {
        return (new User())->setRaw($user)->map([
            'id' => $user['openid'],
            'nickname' => $user['nickname'],
            'email' => $user['email']
        ]);
    }

    public function user() {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            abort(403, trans('LittleSkin\PremiumVerification::general.error.invalid-state'));
        }

        $response = $this->getAccessTokenResponse($this->getCode());
        $this->credentialsResponseBody = $response;

        $this->user = $this->mapUserToObject($this->getUserByToken(
            $this->parseIdToken($response)
        ));

        if ($this->user instanceof User) {
            $this->user->setAccessTokenResponseBody($this->credentialsResponseBody);
        }

        return $this->user->setToken(
            $this->parseAccessToken($response)
        )->setExpiresIn(
            $this->parseExpiresIn($response)
        )->setApprovedScopes(
            explode($this->scopeSeparator, Arr::get($response, 'scope', ''))
        );
    }

    /**
     * 从 /token 的响应里解析 id_token
     *
     * 其实请求用户授权（/authorize）的时候的 response_type 里没有 id_token 或者 token，
     * 但即使这样，只要 scope 里包含 openid，换取 access_token 的时候的响应里也还是会返回 id_token。
     * Microsoft Identity Platform 的文档里没有提到这种情况，不保证以后会不会出问题
     */
    protected function parseIdToken($body) {
        return Arr::get($body, 'id_token');
    }

    protected function getTokenFields($code)
    {
        return array_merge(parent::getTokenFields($code), [
            'grant_type' => 'authorization_code',
            'scope'      => parent::formatScopes(parent::getScopes(), $this->scopeSeparator),
        ]);
    }

}



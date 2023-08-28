<?php

namespace LittleSkin\PremiumVerification\Controllers;

use App\Models\Player;
use App\Services\Hook;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use LittleSkin\PremiumVerification\Models\Premium;
use LittleSkin\PremiumVerification\Models\MicrosoftOIDCConnection as Connection;

class VerificationController extends Controller {

    public function getLatestName($uuid) {
        $response = Http::get('https://sessionserver.mojang.com/session/minecraft/profile/' . $uuid)->json();
        return $response['name'];
    }

    public function verify() {
        $user = auth()->user();
        if(Premium::where('uid', $user->uid)->first()) {
            abort(403, trans('LittleSkin\PremiumVerification::premium.already-verified'));
        }
        return Socialite::driver('premium')->redirect();
    }

    public function callback() {
        /** @var User */
        $user = auth()->user();
        $premiumUser = Socialite::driver('premium')->user();

        $uid = $user->uid;
        $name = $premiumUser->user['player'];
        $uuid = $premiumUser->user['uuid'];

        if(Premium::where('uid', $uid)->first()) {
            abort(403, trans('LittleSkin\PremiumVerification::premium.already-verified'));
        }

        if(Premium::where('uuid', $uuid)->first()) {
            abort(403, trans('LittleSkin\PremiumVerification::premium.verified-by-other'));
        }

        $result = self::resolveNameConflict($name, $uid, $uuid);

        Premium::create([
            'uid' => $uid,
            'pid' => $result ? $result :
                Player::create([
                    'uid' => $uid,
                    'name' => $name,
                ])->pid,
            'uuid' => $uuid,
        ]);

        if(!Connection::where('uid', $uid)->first()) {
            Connection::create([
                'uid' => $uid,
                'oid' => $premiumUser->id,
            ]);
        }

        $user->score += option('premium_verification_score_award', 0);
        $user->save();

        return redirect('/user/player');
    }

    /**
     * 递归检查角色名冲突，并发送更名通知
     *
     * @param string $name 角色名
     * @param int $uid 当前用户 UID
     */
    protected function resolveNameConflict(string $name, int $uid) {
        if($player = Player::where('name', $name)->first()) { // 有重名角色
            $previousName = $player->name;
            if($player->uid == $uid) { // 重名角色属主是用户自己
                if(!Premium::where('uid', $uid)->first()) { // 用户没有做过正版验证
                    return $player->pid;
                } else { // 用户做过正版验证，说明是用户自己的别的角色占用了正版角色名
                    $player->name = $previousName . '_' . $player->pid; // 给占用角色名的角色改名
                    $player->save();
                    return false;
                }
            }
            if($premium = Premium::where('pid', $player->pid)->first()) { // 重名角色是正版角色
                $latestName = self::getLatestName($premium->uuid); // 查询最新角色名
                self::resolveNameConflict($latestName, $player->uid); // 递归检查
                $player->name = $latestName;
                $player->save();
            } else { // 重名角色是离线角色
                $player->name = $previousName . '_' . $player->pid;
                $player->save();
            }
            $owner = $player->user;
            Hook::sendNotification(
                [$owner],
                trans('LittleSkin\PremiumVerification::premium.notification.title', [], $owner->locale),
                trans('LittleSkin\PremiumVerification::premium.notification.content', [
                    'nickname' => $owner->nickname,
                    'prev_name' => $previousName,
                    'curr_name' => $player->name,
                ], $owner->locale)
            );
            // TODO: 发送改名通知邮件
        }
        return false;
    }

    /**
     * 更新角色名
     *
     * 没考虑通过 Xbox Game Pass 获得的正版 Minecraft 的用户的 XGP 过期的情况，
     * 可能出现用户 XGP 过期后查询不到最新正版角色名的情况，因为此时玩家正版 Profile 已被删除
     */
    public function update() {
        $user = auth()->user();
        $premium = Premium::where('uid', $user->uid)->first();

        if(!$premium) {
            abort(403, trans('LittleSkin\PremiumVerification::premium.not-verified'));
        }

        $player = Player::where('pid', $premium->pid)->first();
        $latestName = self::getLatestName($premium->uuid);

        if($player) { // 角色没删，只是改名了
            if($player->name == $latestName) { // 角色名没变，不用更新
                // return json('角色名无需更新', 0);
                return redirect('/user/player');
            }
            self::resolveNameConflict($latestName, $user->uid);
            $player->name = $latestName;
            $player->save();
        } else { // 角色已经删了，重建一个
            self::resolveNameConflict($latestName, $user->uid);
            $player = Player::create([
                'uid' => $user->uid,
                'name' => $latestName,
            ]);
            $premium->pid = $player->pid;
            $premium->save();
        }
        // return json('已更新角色名', 0);
        return redirect('/user/player');
    }
}



<?php

namespace Biz\Coupon\State;

use Biz\System\Service\LogService;
use Biz\User\Service\UserService;

class UsingCoupon extends Coupon implements CouponInterface
{
    public function using()
    {
        throw new \Exception('Can not using coupon which status is using!');
    }

    public function used($params)
    {
        $coupon = $this->getCouponService()->updateCoupon(
            $this->coupon['id'],
            array(
                'status' => 'used',
                'targetType' => $params['targetType'],
                'targetId' => $params['targetId'],
                'orderTime' => time(),
                'userId' => $params['userId'],
                'orderId' => $params['orderId'],
            )
        );
        $this->updateBachCouponUsedNum($coupon['batchId']);

        $card = $this->getCardService()->getCardByCardIdAndCardType($coupon['id'], 'coupon');

        if (!empty($card)) {
            $this->getCardService()->updateCardByCardIdAndCardType($coupon['id'], 'coupon', array(
                'status' => 'used',
                'useTime' => $coupon['orderTime'],
            ));
        }

        $user = $this->getUserService()->getUser($coupon['userId']);

        $this->getLogService()->info(
            'coupon',
            'use',
            "用户{$user['nickname']}(#{$user['id']})使用了优惠券 {$coupon['code']}",
            $coupon
        );
    }

    public function cancelUsing()
    {
        $this->getCouponService()->updateCoupon($this->coupon['id'], array(
            'status' => 'receive',
        ));
    }

    private function updateBachCouponUsedNum($batchId)
    {
        if (0 == $batchId) {
            return;
        }
        $couponApp = $this->getAppService()->getAppByCode('Coupon');
        if (!empty($couponApp)) {
            $usedCouponsCount = $this->getCouponService()->searchCouponsCount(array(
                'status' => 'used',
                'batchId' => $batchId,
            ));
            $usedCouponCount = $this->getCouponBatchService()->updateBatch($batchId, array('usedNum' => $usedCouponsCount));
        }
    }

    /**
     * @return LogService
     */
    private function getLogService()
    {
        return $this->biz->service('System:LogService');
    }

    /**
     * @return UserService
     */
    private function getUserService()
    {
        return $this->biz->service('User:UserService');
    }

    /**
     * @return AppService
     */
    protected function getAppService()
    {
        return $this->biz->service('CloudPlatform:AppService');
    }

    /**
     * @return CouponBatchService
     */
    private function getCouponBatchService()
    {
        return $this->biz->service('CouponPlugin:Coupon:CouponBatchService');
    }
}
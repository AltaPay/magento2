<?php
/**
 * Altapay Module for Magento 2.x.
 *
 * Copyright © 2020 Altapay. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Altapay\Model\Handler;

use SDM\Altapay\Helper\Data;
use SDM\Altapay\Helper\Config as storeConfig;

/**
 * Class DiscountHandler
 * Handle discounts related calculations.
 */
class DiscountHandler
{

    /**
     * @var Helper Data
     */
    private $helper;
    /**
     * @var Helper Config
     */
    private $storeConfig;

    /**
     * Gateway constructor.
     *
     * @param Data        $helper
     * @param storeConfig $storeConfig
     */
    public function __construct(
        Data $helper,
        storeConfig $storeConfig
    ) {
        $this->helper      = $helper;
        $this->storeConfig = $storeConfig;
    }

    /**
     * @param float|null $discountAmount
     * @param float      $productOriginalPrice
     * @param float|null $quantity
     *
     * @return float|int
     */
    public function getItemDiscount($discountAmount, $productOriginalPrice, $quantity)
    {
        if ($discountAmount > 0) {
            $discountPercent = ($discountAmount * 100) / ($productOriginalPrice * $quantity);
        } else {
            $discountPercent = 0;
        }

        return $discountPercent;
    }

    /**
     * Get the applied discount information.
     *
     * @param $item
     *
     * @return mixed
     */
    public function getAppliedDiscounts($item)
    {
        $appliedRule = $item->getAppliedRuleIds();
        $parentItem  = $item->getParentItem();
        // in case of bundle products get the discount information from the parent item
        if ($parentItem) {
            $parentItemType = $parentItem->getProductType();
            if ($parentItemType == "bundle") {
                $appliedRule = $parentItem->getAppliedRuleIds();
            }
        }

        return $appliedRule;
    }

    /**
     * Get discount amount if not applied to all items.
     *
     * @param $discountOnAllItems
     * @param $discount
     *
     * @return int|string
     */
    public function orderLineDiscount($discountOnAllItems, $discount)
    {
        if ($discountOnAllItems) {
            $discount = 0;
        }

        return number_format($discount, 2, '.', '');
    }

    /**
     * Get discount applied to shipping.
     *
     * @param $appliedRule
     *
     * @return array
     */
    public function getShippingDiscounts($appliedRule)
    {
        $shippingDiscounts = [];
        if (!empty($appliedRule)) {
            $appliedRuleArr = explode(",", $appliedRule);
            foreach ($appliedRuleArr as $ruleId) {
                //get rule discount information
                $couponCodeData = $this->storeConfig->getRuleInformationByID($ruleId);
                //check if coupon applied to shipping
                if ($couponCodeData['apply_to_shipping']) {
                    if (!in_array($ruleId, $shippingDiscounts)) {
                        $shippingDiscounts[] = $ruleId;
                    }
                }
            }
        }

        return $shippingDiscounts;
    }

    /**
     * Calculate catalog discount.
     *
     * @param $originalPrice
     * @param $discountedPrice
     *
     * @return float|int
     */
    public function catalogDiscount($originalPrice, $discountedPrice)
    {
        $discountAmount = (($originalPrice - $discountedPrice) / $originalPrice) * 100;

        return number_format($discountAmount, 2, '.', '');
    }

    /**
     * @param $originalPrice
     * @param $discountedPrice
     * @param $discountAmount
     * @param $quantity
     * @param $discountOnAllItems
     *
     * @return array
     */
    public function getItemDiscountInformation(
        $originalPrice,
        $discountedPrice,
        $discountAmount,
        $quantity,
        $discountOnAllItems
    ) {
        $discount = ['discount' => 0, 'catalogDiscount' => false];
        if (!empty($discountAmount)) {
            $discountAmount = ($discountAmount * 100) / ($originalPrice * $quantity);
        } elseif ($originalPrice > 0 && $originalPrice > $discountedPrice) {
            $discount['catalog'] = true;
            $discountAmount      = $this->catalogDiscount($originalPrice, $discountedPrice);
        }
        $discount['discount'] = $this->orderLineDiscount($discountOnAllItems, $discountAmount);

        return $discount;
    }

    /**
     * Check whether all items have discount.
     *
     * @param $orderItems
     *
     * @return bool
     */
    public function allItemsHaveDiscount($orderItems)
    {
        $discountOnAllItems = true;
        foreach ($orderItems as $item) {
            $appliedRule = $item->getAppliedRuleIds();
            $productType = $item->getProductType();
            if (!empty($appliedRule)) {
                $appliedRuleArr = explode(",", $appliedRule);
                foreach ($appliedRuleArr as $ruleId) {
                    $coupon = $this->storeConfig->getRuleInformationByID($ruleId);
                    if (!$coupon['apply_to_shipping'] && $productType != 'virtual' && $productType != 'downloadable') {
                        $discountOnAllItems = false;
                    }
                }
            } else {
                $discountOnAllItems = false;
            }
        }

        return $discountOnAllItems;
    }
}

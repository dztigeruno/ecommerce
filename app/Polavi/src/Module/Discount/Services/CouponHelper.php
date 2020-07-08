<?php
/**
 * Copyright © Nguyen Huu The <the.nguyen@polavi.com>.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Polavi\Module\Discount\Services;


use function Polavi\_mysql;
use function Polavi\dispatch_event;
use function Polavi\get_config;
use Polavi\Module\Checkout\Services\Cart\Cart;

class CouponHelper
{
    /**@var Cart $cart*/
    protected $cart;

    /**@var callable[] */
    private $validators = [];

    /**@var callable[] */
    private $discountCalculators = [];

    protected $discounts = [];

    protected $coupon;

    public function __construct(Cart $cart)
    {
        $this->cart = $cart;
        $this->defaultValidator();
        $this->defaultDiscountCalculator();
    }

    protected function getCartTotalBeforeDiscount(Cart $cart) {
        $total = 0;
        foreach ($cart->getItems() as $item)
            $total += $item->getData('final_price') * $item->getData('qty');

        return $total;
    }

    protected function defaultDiscountCalculator()
    {
        $this->addDiscountCalculator('percentage_discount_to_entire_order', function(array $coupon, Cart $cart) {
            $discountPercent = floatval($coupon['discount_amount']) > 100 ? 100 : floatval($coupon['discount_amount']);
            $cartDiscountAmount = ($discountPercent * $this->getCartTotalBeforeDiscount($cart)) / 100;

            switch (get_config('sale_discount_calculation_rounding', 0)) {
                case 1:
                    $cartDiscountAmount = ceil($cartDiscountAmount);
                    break;
                case -1:
                    $cartDiscountAmount = floor($cartDiscountAmount);
                    break;
            }
            $i = 0;
            $distributedAmount = 0;
            $items = $cart->getItems();
            foreach ($items as $item) {
                $i ++;
                if($i == count($items)) {
                    $sharedDiscount = $cartDiscountAmount - $distributedAmount;
                } else {
                    $rowTotal = $item->getData('final_price') * $item->getData('qty');
                    $sharedDiscount = round($rowTotal * $cartDiscountAmount / $this->getCartTotalBeforeDiscount($cart), 0);
                }
                if(!isset($this->discounts[$item->getData('cart_item_id')]) or $this->discounts[$item->getData('cart_item_id')] != $sharedDiscount) {
                    $this->discounts[$item->getData('cart_item_id')] = $sharedDiscount;
                    $item->setData('discount_amount', $sharedDiscount);
                }

                $distributedAmount += $sharedDiscount;
            }
        });

        $this->addDiscountCalculator('fixed_discount_to_entire_order', function(array $coupon, Cart $cart) {
            $cartDiscountAmount = floatval($coupon['discount_amount']);
            $cartDiscountAmount = $this->getCartTotalBeforeDiscount($cart) > $cartDiscountAmount ? $cartDiscountAmount : $this->getCartTotalBeforeDiscount($cart);
            switch (get_config('sale_discount_calculation_rounding', 0)) {
                case 1:
                    $cartDiscountAmount = ceil($cartDiscountAmount);
                    break;
                case -1:
                    $cartDiscountAmount = floor($cartDiscountAmount);
                    break;
            }
            $i = 0;
            $distributedAmount = 0;
            $items = $cart->getItems();
            foreach ($items as $item) {
                $i ++;
                if($i == count($items)) {
                    $sharedDiscount = $cartDiscountAmount - $distributedAmount;
                } else {
                    $rowTotal = $item->getData('final_price') * $item->getData('qty');
                    $sharedDiscount = round($rowTotal * $cartDiscountAmount / $this->getCartTotalBeforeDiscount($cart), 0);
                }
                if(!isset($this->discounts[$item->getData('cart_item_id')]) or $this->discounts[$item->getData('cart_item_id')] != $sharedDiscount) {
                    $this->discounts[$item->getData('cart_item_id')] = $sharedDiscount;
                    $item->setData('discount_amount', $sharedDiscount);
                }

                $distributedAmount += $sharedDiscount;
            }
        });

        $this->addDiscountCalculator('fixed_discount_to_specific_products', function(array $coupon, Cart $cart) {
            $discountAmount = floatval($coupon['discount_amount']);
            $items = $cart->getItems();
            $targetProducts = trim($coupon['target_products']);
            $targetProducts = empty($targetProducts) ? [] : json_decode($targetProducts, true);
            if (JSON_ERROR_NONE !== json_last_error())
                return;

            $discountAbleItems = [];
            foreach ($targetProducts as $condition) {

            }
            foreach ($items as $item) {
                $productId = $item->getData('product_id');
                $sku = $item->getData('product_sku');
                if(in_array($sku, $targetProducts)) {
                    $discountAmount = $item->getData('final_price') * $item->getData('qty') > $discountAmount ? $discountAmount : $item->getData('final_price') * $item->getData('qty');
                    switch (get_config('sale_discount_calculation_rounding', 0)) {
                        case 1:
                            $discountAmount = ceil($discountAmount);
                            break;
                        case -1:
                            $discountAmount = floor($discountAmount);
                            break;
                    }
                } else {
                    $discountAmount = 0;
                }
                if(!isset($this->discounts[$item->getData('cart_item_id')]) or $this->discounts[$item->getData('cart_item_id')] != $discountAmount) {
                    $this->discounts[$item->getData('cart_item_id')] = $discountAmount;
                    $item->setData('discount_amount', $discountAmount);
                }
            }
        });

        $this->addDiscountCalculator('percentage_discount_to_specific_products', function(array $coupon, Cart $cart) {
            $discountPercent = floatval($coupon['discount_amount']) > 100 ? 100 : floatval($coupon['discount_amount']);
            $items = $cart->getItems();
            $targetProducts = trim($coupon['target_products']);
            $targetProducts = empty($targetProducts) ? [] : explode(',', $targetProducts);
            foreach ($items as $item) {
                $sku = $item->getData('product_sku');
                if(in_array($sku, $targetProducts)) {
                    $discountAmount = $item->getData('final_price') * $item->getData('qty') * $discountPercent / 100;
                    switch (get_config('sale_discount_calculation_rounding', 0)) {
                        case 1:
                            $discountAmount = ceil($discountAmount);
                            break;
                        case -1:
                            $discountAmount = floor($discountAmount);
                            break;
                    }
                } else {
                    $discountAmount = 0;
                }
                if(!isset($this->discounts[$item->getData('cart_item_id')]) or $this->discounts[$item->getData('cart_item_id')] != $discountAmount) {
                    $this->discounts[$item->getData('cart_item_id')] = $discountAmount;
                    $item->setData('discount_amount', $discountAmount);
                }
            }
        });

        $this->addDiscountCalculator('buy_x_get_y', function(array $coupon, Cart $cart) {
            $discountPercent = floatval($coupon['discount_amount']) > 100 ? 100 : floatval($coupon['discount_amount']);
            $configs = json_decode($coupon['buyx_gety'], true);
            if (JSON_ERROR_NONE !== json_last_error())
                return;
            $items = $cart->getItems();
            foreach ($configs as $row) {
                $sku = $row['sku'] ?? null;
                $buyQty = isset($row['buy_qty']) ? (int) $row['buy_qty'] : null;
                $getQty = isset($row['get_qty']) ? (int) $row['get_qty'] : null;
                $maxY = isset($row['max_y']) ? (int) $row['max_y'] : 1000000;
                $discount = isset($row['discount']) ? floatval($row['discount']) : 0;

                if(!$sku || !$buyQty || !$getQty || $buyQty == null || $getQty == null || $discount < 0 || $discount > 100)
                    return;

                foreach ($items as $item) {
                    if($item->getData("product_sku") == trim($sku) && $item->getData("qty") >= $buyQty + $getQty) {
                        $discountPerUnit = $discount * $item->getData("final_price") / 100;
                        $discountAbleUnits = floor($item->getData("qty") / $buyQty) * $getQty;
                        if($discountAbleUnits < $maxY)
                            $discountAmount = $discountAbleUnits * $discountPerUnit;
                        else
                            $discountAmount = $discountPerUnit * $maxY;

                        if(!isset($this->discounts[$item->getData('cart_item_id')]) or $this->discounts[$item->getData('cart_item_id')] != $discountAmount) {
                            $this->discounts[$item->getData('cart_item_id')] = $discountAmount;
                            $item->setData('discount_amount', $discountAmount);
                        }
                    }
                }
            }
        });
    }

    public function addDiscountCalculator($discountType, callable $callable)
    {
        $this->discountCalculators[$discountType] = $callable;

        return $this;
    }

    public function removeDiscountCalculator($discountType)
    {
        unset($this->discountCalculators[$discountType]);

        return $this;
    }

    protected function parseValue($value) {
        if(is_int($value) or is_float($value))
            return $value;
        $value = trim($value);
        if(!$value)
            return null;
        $arr = str_getcsv($value);
        if(count($arr) == 1)
            return $arr[0];
        return $arr;
    }

    protected function defaultValidator()
    {
        $this->addValidator('general', function($coupon) {
            if($coupon['status'] == '0')
                return false;
            if(floatval($coupon['discount_amount']) <= 0 && $coupon['discount_type'] !== "buy_x_get_y")
                return false;
            if (
                ($coupon['start_date'] and $coupon['start_date'] > date("Y-m-d H:i:s")) ||
                ($coupon['end_date'] and $coupon['end_date'] < date("Y-m-d H:i:s"))
            )
                return false;

            return true;
        })->addValidator('timeUsed', function($coupon, Cart $cart) {
            if(
                $coupon['max_uses_time_per_coupon']
                and (int)$coupon['used_time'] >= (int)$coupon['max_uses_time_per_coupon']
            )
                return false;
            if(
                isset($coupon['max_uses_time_per_customer'])
                and $coupon['max_uses_time_per_customer']
            ) {
                $customerId = $cart->getData('customer_id');
                if($customerId) {
                    $flag = _mysql()->getTable('customer_coupon_use')
                        ->where('customer_id', '=', $customerId)
                        ->andWhere('coupon', '=', $coupon['coupon'])
                        ->andWhere('used_time', '>=', (int)$coupon['max_uses_time_per_customer'])
                        ->fetchOneAssoc();
                    if($flag)
                        return false;
                }
            }

            return true;
        })->addValidator('customerGroup', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            if(
                !empty($conditions['customer_group'])
                and !in_array(0, $conditions['customer_group'])
            ) {
                $customerGroupId = $cart->getData('customer_group_id');
                if(!in_array($customerGroupId, $conditions['customer_group']))
                    return false;
            }

            return true;
        })->addValidator('subTotal', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            $minimumSubTotal = isset($conditions['order_total']) ? floatval($conditions['order_total']) : null;
            if($minimumSubTotal and floatval($this->getCartTotalBeforeDiscount($cart)) < $minimumSubTotal)
                return false;

            return true;
        })->addValidator('minimumQty', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            $minimumQty = isset($conditions['order_qty']) ? (int)$conditions['order_qty'] : null;
            if($minimumQty and $cart->getData('total_qty') < $minimumQty)
                return false;

            return true;
        })->addValidator('requiredProductByCategory', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            if(!isset($conditions['required_product']))
                return true;
            $satisfied = true;
            foreach ($conditions['required_product'] as $condition) {
                if($condition['key'] == 'category') {
                    $satisfied = false;
                    $conn = _mysql();
                    $value = $this->parseValue($condition['value']);
                    $requiredQty = (int) $condition['qty'];
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                $check = $conn->getTable('product_category')
                                    ->where('product_id', '=', $item->getData('product_id'))
                                    ->andWhere('category_id', $condition['operator'], $value)
                                    ->fetchOneAssoc();
                                if($check)
                                    $satisfied = true;
                            }
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                $check = $conn->getTable('product_category')
                                    ->where('product_id', '=', $item->getData('product_id'))
                                    ->andWhere('category_id', $condition['operator'], [$value])
                                    ->fetchOneAssoc();
                                if($check)
                                    $satisfied = true;
                            }
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                $check = $conn->getTable('product_category')
                                    ->where('product_id', '=', $item->getData('product_id'))
                                    ->andWhere('category_id', $condition['operator'], $value)
                                    ->fetchOneAssoc();
                                if($check)
                                    $satisfied = true;
                            }
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('requiredProductByAttributeGroup', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            if(!isset($conditions['required_product']))
                return true;
            $satisfied = true;
            foreach ($conditions['required_product'] as $condition) {
                if($condition['key'] == 'attribute_group') {
                    $satisfied = false;
                    $conn = _mysql();
                    $value = $this->parseValue($condition['value']);
                    $requiredQty = (int) $condition['qty'];
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                $check = $conn->getTable('product')
                                    ->where('product_id', '=', $item->getData('product_id'))
                                    ->andWhere('group_id', $condition['operator'], $value)
                                    ->fetchOneAssoc();
                                if($check)
                                    $satisfied = true;
                            }
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                $check = $conn->getTable('product')
                                    ->where('product_id', '=', $item->getData('product_id'))
                                    ->andWhere('group_id', $condition['operator'], [$value])
                                    ->fetchOneAssoc();
                                if($check)
                                    $satisfied = true;
                            }
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                $check = $conn->getTable('product')
                                    ->where('product_id', '=', $item->getData('product_id'))
                                    ->andWhere('group_id', $condition['operator'], $value)
                                    ->fetchOneAssoc();
                                if($check)
                                    $satisfied = true;
                            }
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('requiredProductByPrice', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            if(!isset($conditions['required_product']))
                return true;
            $satisfied = true;
            foreach ($conditions['required_product'] as $condition) {
                if($condition['key'] == 'price') {
                    $satisfied = false;
                    $value = $this->parseValue($condition['value']);
                    $requiredQty = (int) $condition['qty'];
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;
                                if($condition['operator'] == "IN") {
                                    if(in_array($item->getData('final_price'), $value))
                                        $satisfied = true;
                                } else {
                                    if(!in_array($item->getData('final_price'), $value))
                                        $satisfied = true;
                                }
                            }
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                if($condition['operator'] == "IN") {
                                    if($item->getData('final_price') == $value)
                                        $satisfied = true;
                                } else {
                                    if($item->getData('final_price') != $value)
                                        $satisfied = true;
                                }
                            }
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                switch($condition['operator'])
                                {
                                    case "=":
                                        if($item->getData('final_price') == $value)
                                            $satisfied = true;
                                        break;
                                    case "<":
                                        if($item->getData('final_price') < $value)
                                            $satisfied = true;
                                        break;
                                    case "<=":
                                        if($item->getData('final_price') <= $value)
                                            $satisfied = true;
                                        break;
                                    case ">":
                                        if($item->getData('final_price') > $value)
                                            $satisfied = true;
                                        break;
                                    case ">=":
                                        if($item->getData('final_price') >= $value)
                                            $satisfied = true;
                                        break;
                                    case "<>":
                                        if($item->getData('final_price') != $value)
                                            $satisfied = true;
                                        break;
                                }
                            }
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('requiredProductBySKU', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['condition'], true);
            if(!isset($conditions['required_product']))
                return true;
            $satisfied = true;
            foreach ($conditions['required_product'] as $condition) {
                if($condition['key'] == 'sku') {
                    $satisfied = false;
                    $value = $this->parseValue($condition['value']);
                    $requiredQty = (int) $condition['qty'];
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;
                                if($condition['operator'] == "IN") {
                                    if(in_array($item->getData('product_sku'), $value))
                                        $satisfied = true;
                                } else {
                                    if(!in_array($item->getData('product_sku'), $value))
                                        $satisfied = true;
                                }
                            }
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                if($condition['operator'] == "IN") {
                                    if($item->getData('product_sku') == $value)
                                        $satisfied = true;
                                } else {
                                    if($item->getData('product_sku') != $value)
                                        $satisfied = true;
                                }
                            }
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;

                                switch($condition['operator'])
                                {
                                    case "=":
                                        if($item->getData('product_sku') == $value)
                                            $satisfied = true;
                                        break;
                                    case "<":
                                        if($item->getData('product_sku') < $value)
                                            $satisfied = true;
                                        break;
                                    case "<=":
                                        if($item->getData('product_sku') <= $value)
                                            $satisfied = true;
                                        break;
                                    case ">":
                                        if($item->getData('product_sku') > $value)
                                            $satisfied = true;
                                        break;
                                    case ">=":
                                        if($item->getData('product_sku') >= $value)
                                            $satisfied = true;
                                        break;
                                    case "<>":
                                        if($item->getData('product_sku') != $value)
                                            $satisfied = true;
                                        break;
                                }
                            }
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('targetProductByCategory', function($coupon, Cart $cart) {
            if(!in_array($coupon['discount_type'], ["fixed_discount_to_specific_products", "percentage_discount_to_specific_products"]))
                return true;

            $targetProducts = trim($coupon['target_products']);
            $targetProducts = empty($targetProducts) ? [] : json_decode($targetProducts, true);
            if (JSON_ERROR_NONE !== json_last_error())
                return;

            $satisfied = true;
            foreach ($targetProducts as $condition) {
                if($condition['key'] == 'category') {
                    $satisfied = false;
                    $conn = _mysql();
                    $value = $this->parseValue($condition['value']);
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            $pIDs = [];
                            foreach ($items as $item) {
                                $pIDs[] = $item->getData("product_id");
                            }
                            $check = $conn->getTable('product_category')
                                ->where('product_id', 'IN', $pIDs)
                                ->andWhere('category_id', $condition['operator'], $value)
                                ->fetchOneAssoc();
                            if($check)
                                $satisfied = true;
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            $pIDs = [];
                            foreach ($items as $item) {
                                $pIDs[] = $item->getData("product_id");
                            }
                            $check = $conn->getTable('product_category')
                                ->where('product_id', 'IN', $pIDs)
                                ->andWhere('category_id', $condition['operator'], [$value])
                                ->fetchOneAssoc();
                            if($check)
                                $satisfied = true;
                        } else {
                            $items = $cart->getItems();
                            $pIDs = [];
                            foreach ($items as $item) {
                                $pIDs[] = $item->getData("product_id");
                            }
                            $check = $conn->getTable('product_category')
                                ->where('product_id', 'IN', $pIDs)
                                ->andWhere('category_id', $condition['operator'], $value)
                                ->fetchOneAssoc();
                            if($check)
                                $satisfied = true;
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('targetProductByAttributeGroup', function($coupon, Cart $cart) {
            if(!in_array($coupon['discount_type'], ["fixed_discount_to_specific_products", "percentage_discount_to_specific_products"]))
                return true;

            $targetProducts = trim($coupon['target_products']);
            $targetProducts = empty($targetProducts) ? [] : json_decode($targetProducts, true);
            if (JSON_ERROR_NONE !== json_last_error())
                return;

            $satisfied = true;
            foreach ($targetProducts as $condition) {
                if($condition['key'] == 'attribute_group') {
                    $satisfied = false;
                    $conn = _mysql();
                    $value = $this->parseValue($condition['value']);
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            $pIDs = [];
                            foreach ($items as $item) {
                                $pIDs[] = $item->getData("product_id");
                            }
                            $check = $conn->getTable('product')
                                ->where('product_id', 'IN', $pIDs)
                                ->andWhere('group_id', $condition['operator'], $value)
                                ->fetchOneAssoc();
                            if($check)
                                $satisfied = true;
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            $pIDs = [];
                            foreach ($items as $item) {
                                $pIDs[] = $item->getData("product_id");
                            }
                            $check = $conn->getTable('product')
                                ->where('product_id', 'IN', $pIDs)
                                ->andWhere('group_id', $condition['operator'], [$value])
                                ->fetchOneAssoc();
                            if($check)
                                $satisfied = true;
                        } else {
                            $items = $cart->getItems();
                            $pIDs = [];
                            foreach ($items as $item) {
                                $pIDs[] = $item->getData("product_id");
                            }
                            $check = $conn->getTable('product')
                                ->where('product_id', 'IN', $pIDs)
                                ->andWhere('group_id', $condition['operator'], $value)
                                ->fetchOneAssoc();
                            if($check)
                                $satisfied = true;
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('targetProductByPrice', function($coupon, Cart $cart) {
            if(!in_array($coupon['discount_type'], ["fixed_discount_to_specific_products", "percentage_discount_to_specific_products"]))
                return true;

            $targetProducts = trim($coupon['target_products']);
            $targetProducts = empty($targetProducts) ? [] : json_decode($targetProducts, true);
            if (JSON_ERROR_NONE !== json_last_error())
                return;
            $satisfied = true;
            foreach ($targetProducts as $condition) {
                if($condition['key'] == 'price') {
                    $satisfied = false;
                    $value = $this->parseValue($condition['value']);
                    $requiredQty = (int) $condition['qty'];
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($item->getData('qty') < $requiredQty)
                                    continue;
                                if($condition['operator'] == "IN") {
                                    if(in_array($item->getData('final_price'), $value)) {
                                        $satisfied = true;
                                        break;
                                    }
                                } else {
                                    if(!in_array($item->getData('final_price'), $value)) {
                                        $satisfied = true;
                                        break;
                                    }
                                }
                            }
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($condition['operator'] == "IN") {
                                    if($item->getData('final_price') == $value) {
                                        $satisfied = true;
                                        break;
                                    }
                                } else {
                                    if($item->getData('final_price') != $value) {
                                        $satisfied = true;
                                        break;
                                    }
                                }
                            }
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                switch($condition['operator'])
                                {
                                    case "=":
                                        if($item->getData('final_price') == $value)
                                            $satisfied = true;
                                        break;
                                    case "<":
                                        if($item->getData('final_price') < $value)
                                            $satisfied = true;
                                        break;
                                    case "<=":
                                        if($item->getData('final_price') <= $value)
                                            $satisfied = true;
                                        break;
                                    case ">":
                                        if($item->getData('final_price') > $value)
                                            $satisfied = true;
                                        break;
                                    case ">=":
                                        if($item->getData('final_price') >= $value)
                                            $satisfied = true;
                                        break;
                                    case "<>":
                                        if($item->getData('final_price') != $value)
                                            $satisfied = true;
                                        break;
                                }
                            }
                        }
                    }
                }
            }

            return $satisfied;
        })->addValidator('targetProductBySKU', function($coupon, Cart $cart) {
            if(!in_array($coupon['discount_type'], ["fixed_discount_to_specific_products", "percentage_discount_to_specific_products"]))
                return true;

            $targetProducts = trim($coupon['target_products']);
            $targetProducts = empty($targetProducts) ? [] : json_decode($targetProducts, true);
            if (JSON_ERROR_NONE !== json_last_error())
                return;
            $satisfied = true;
            foreach ($targetProducts as $condition) {
                if($condition['key'] == 'sku') {
                    $satisfied = false;
                    $value = $this->parseValue($condition['value']);
                    $requiredQty = (int) $condition['qty'];
                    if(is_array($value)) {
                        if($condition['operator'] != "IN" and $condition['operator'] != "NOT IN") {
                            return false;
                            break;
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($condition['operator'] == "IN") {
                                    if(in_array($item->getData('product_sku'), $value)) {
                                        $satisfied = true;
                                        break;
                                    }
                                } else {
                                    if(!in_array($item->getData('product_sku'), $value)) {
                                        $satisfied = true;
                                        break;
                                    }
                                }
                            }
                        }
                    } else {
                        if($condition['operator'] == "IN" or $condition['operator'] == "NOT IN") {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                if($condition['operator'] == "IN") {
                                    if($item->getData('product_sku') == $value) {
                                        $satisfied = true;
                                        break;
                                    }
                                } else {
                                    if($item->getData('product_sku') != $value) {
                                        $satisfied = true;
                                        break;
                                    }
                                }
                            }
                        } else {
                            $items = $cart->getItems();
                            foreach ($items as $item) {
                                switch($condition['operator'])
                                {
                                    case "=":
                                        if($item->getData('product_sku') == $value)
                                            $satisfied = true;
                                        break;
                                    case "<":
                                        if($item->getData('product_sku') < $value)
                                            $satisfied = true;
                                        break;
                                    case "<=":
                                        if($item->getData('product_sku') <= $value)
                                            $satisfied = true;
                                        break;
                                    case ">":
                                        if($item->getData('product_sku') > $value)
                                            $satisfied = true;
                                        break;
                                    case ">=":
                                        if($item->getData('product_sku') >= $value)
                                            $satisfied = true;
                                        break;
                                    case "<>":
                                        if($item->getData('product_sku') != $value)
                                            $satisfied = true;
                                        break;
                                }
                            }
                        }
                    }
                }
            }

            return $satisfied;
        });

        // Customer condition validators
        $this->addValidator('customerGroup', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['user_condition'], true);
            if(!isset($conditions['group']) || $conditions['group'] == 999)
                return true;
            if($cart->getData('customer_group_id') != $conditions['group'])
                return false;
            return true;
        });

        $this->addValidator('customerEmail', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['user_condition'], true);
            if(!isset($conditions['email']) || trim((string)($conditions['email'])) == '')
                return true;
            $emails = str_getcsv($conditions['email']);
            if(!in_array($cart->getData('customer_email'),  $emails) || $cart->getData('customer_id') == null)
                return false;
            return true;
        });

        $this->addValidator('customerPurchasedAmount', function($coupon, Cart $cart) {
            $conditions = json_decode($coupon['user_condition'], true);
            if(!isset($conditions['purchased']) || trim((string)($conditions['purchased'])) == '')
                return true;
            $amount = floatval($conditions['purchased']);
            $conn = _mysql();
            $total = $conn->getTable('order')
                ->addFieldToSelect('SUM(grand_total)', 'total')
                ->where('customer_id','=', $cart->getData('customer_id'))
                ->andWhere('payment_status', '=', 'paid')
                ->fetchOneAssoc();
            if($total['total'] < $amount)
                return false;
            return true;
        });
        dispatch_event('register_coupon_validator', [$this]);
    }

    public function addValidator($id, callable $callable)
    {
        $this->validators[$id] = $callable;

        return $this;
    }

    public function removeValidator($id)
    {
        unset($this->validators[$id]);

        return $this;
    }

    public function applyCoupon($coupon, Cart $cart)
    {
        $flag = true;
        if($coupon == null)
            $flag = false;

        if($flag == true) {
            $conn = _mysql();
            $_coupon = $conn->getTable('coupon')->loadByField('coupon', $coupon);
            if($_coupon == false)
                $flag = false;

            if($flag == true)
                foreach ($this->validators as $key=>$validator) {
                    if(!$validator($_coupon, $cart)) {
                        $flag = false;
                        break;
                    }
                }
        }

        if($flag == false) {
            $this->coupon = null;
            $this->discounts = [];
            $items= $cart->getItems();
            foreach ($items as $item)
                $item->setData('discount_amount', 0);

            return null;
        } else {
            $this->coupon = $_coupon;
            $this->calculateDiscount($coupon, $cart);
        }

        return $coupon;
    }

    protected function calculateDiscount($coupon, Cart $cart)
    {
        $_coupon = _mysql()->getTable('coupon')->loadByField('coupon', $coupon);
        if(isset($this->discountCalculators[$_coupon['discount_type']]))
            $this->discountCalculators[$_coupon['discount_type']]($_coupon, $cart);

        return $this;
    }

    /**
     * @return array|null
     */
    public function getCoupon()
    {
        return $this->coupon;
    }
}
<?php 
declare(strict_types=1);

namespace CustomStylesdrop\Cart\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use CustomStylesdrop\Cart\Checkout\Error\CustomCartBlockedError;

class CustomCartValidator implements CartValidatorInterface
{
    public function validate(Cart $cart, ErrorCollection $errorCollection, SalesChannelContext $salesChannelContext): void
    {
        $productQuantities = [];
        $restrictedQuantities = [];
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            
            $productId = $lineItem->getPayload()['parentId'] ?? $lineItem->getReferencedId();
            if (!isset($productQuantities[$productId])) {
                $productQuantities[$productId] = 0;
                $restrictedQuantities[$productId] = $lineItem->getPayload()['customFields']['custom_styledrop_product_group_purchase_quantity'] ?? 0;
            }
            
            $productQuantities[$productId] += $lineItem->getQuantity();  
        }
        if (count($productQuantities) > 0) {
            foreach($productQuantities as $key=>$productQuantity) {
                $restrictedQuantity = $restrictedQuantities[$key] ?? 0;
                if($restrictedQuantity > 0) {
                    // print_r($restrictedQuantities);
                    // print_r($productQuantities);
                    // exit;
                    if ($productQuantity < $restrictedQuantity) {
                        foreach ($cart->getLineItems() as $lineItem) {
                            $productId = $lineItem->getPayload()['parentId'] ?? $lineItem->getReferencedId();
                            if ($productId == $key) {
                                $cart->getLineItems()->removeElement($lineItem);
                                $errorCollection->add(new CustomCartBlockedError($productId));
                            }
                        }
                    }
                }
            } 
        }
        
    }
}
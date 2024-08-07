<?php

declare(strict_types=1);

namespace CustomStylesdrop\Cart\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CrossVariantPriceProcessor implements CartProcessorInterface
{
    /**
     * @var QuantityPriceCalculator
     */
    private $calculator;

    /**
     * @var SystemConfigService
     */
    private $configService;

    /**
     * @var SalesChannelRepository
     */
    private $productRepository;

    /**
     * @var string
     */
    private $configNamespace;

    private $disabled = false;

    public function __construct(
        QuantityPriceCalculator $calculator,
        SystemConfigService $configService,
        SalesChannelRepository $productRepository,
        string $configNamespace
    ) {
        $this->calculator = $calculator;
        $this->configService = $configService;
        $this->productRepository = $productRepository;
        $this->configNamespace = $configNamespace;
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        if ($this->isAdminRequest($context) || $this->disabled) {
            $this->disabled = true;
            return;
        }

        $config = $this->configService->get($this->configNamespace, $context->getSalesChannelId());
        $lineItems = $toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        foreach ($lineItems->getElements() as $subject) {
            /** @var SalesChannelProductEntity $product */
            $product = $this->getProductCached($subject, $data, $context);

            if (!$product) {
                continue;
            }

            if (!$this->checkIsAllowed($subject, $product, $context->getSalesChannel()->getId())) {
                continue;
            }

            if (!$product->getCalculatedPrices() || !$product->getCalculatedPrices()->count()) {
                continue;
            }

            $subjectHash = !empty($config['groupByPrice'])
                ? $this->buildPriceCollectionHash($product->getCalculatedPrices())
                : null;

            $totalQuantity = $subject->getQuantity();
            $siblings = [];

            // find siblings and add their quantities together
            foreach ($lineItems->getElements() as $lineItem) {
                if ($lineItem->getId() === $subject->getId()) {
                    continue;
                }

                /** @var SalesChannelProductEntity $siblingProduct */
                $siblingProduct = $this->getProductCached($lineItem, $data, $context);

                if (!$siblingProduct) {
                    continue;
                }

                if ($product->getParentId() !== $siblingProduct->getParentId()) {
                    continue;
                }

                if (!$this->checkIsAllowed($lineItem, $siblingProduct, $context->getSalesChannel()->getId())) {
                    continue;
                }

                if (!empty($config['groupByPrice'])) {
                    $siblingHash = $this->buildPriceCollectionHash($siblingProduct->getCalculatedPrices());

                    if ($siblingHash !== $subjectHash) {
                        continue;
                    }
                }

                $totalQuantity += $lineItem->getQuantity();
                $siblings[] = $lineItem;
            }

            // recalculate price based on new quantity
            // if ($totalQuantity !== $subject->getQuantity()) {
            $newPrice = $this->getPriceByQuantity($product->getCalculatedPrices(), $totalQuantity);

            if (!$newPrice) {
                continue;
            }

            $definition = new QuantityPriceDefinition(
                $newPrice->getUnitPrice(),
                $newPrice->getTaxRules(),
                $subject->getQuantity()
            );

            $designPriceData = $subject->getExtension('productDesignPrice');

            if ($designPriceData) {
                $prices = $product->getCalculatedPrices()->getElements();
                $minQuantity = $product->getMinPurchase();
                foreach ($prices as $index => $calculatedPrice) {
                    $maxQuantity = $index < count($prices) - 1 ? $calculatedPrice->getQuantity() : null;
                    if ($totalQuantity >= $minQuantity && ($maxQuantity === null || $totalQuantity <= $maxQuantity)) {
                        $percentage = (($prices[0]->getUnitPrice() - $calculatedPrice->getUnitPrice()) / $prices[0]->getUnitPrice()) * 100;
                        if ($percentage > 0) {
                            $designPrice = $designPriceData['price'] - ($designPriceData['price'] * $percentage) / 100;
                            $designPriceData['price'] = $designPrice;
                        }
                    }
                    $minQuantity = $maxQuantity + 1;
                }
            }

            if ($designPriceData) {
                $definition = $this->increasePriceInDefinition($designPriceData['price'], $definition);
            }

            $newPrice = $this->calculator->calculate($definition, $context);

            $originalPrice = $subject->getPrice();
            $subject->setPrice($newPrice);

            foreach ($siblings as $lineItem) {
                $lineItem->addExtension('MaxiaCrossVariantDiscount', new ArrayEntity([
                    'totalQuantity' => $totalQuantity,
                    'originalPrice' => $originalPrice
                ]));
            }
            // }
        }
    }

    /**
     * Returns the product entity by line item.
     *
     * @param LineItem $lineItem
     * @param CartDataCollection $data
     * @param SalesChannelContext $context
     * @return mixed|null
     */
    protected function getProductCached(LineItem $lineItem, CartDataCollection $data, SalesChannelContext $context): ?SalesChannelProductEntity
    {
        // differentiate by currently active rules
        $key = 'product-' . $lineItem->getReferencedId() . '-' . $this->getContextHash($context);

        if (!$data->has($key)) {
            /** @var ProductEntity $baseProduct */
            $baseProduct = $data->get('product-' . $lineItem->getReferencedId());
            $criteria = new Criteria();

            if ($baseProduct) {
                $criteria->setIds([$baseProduct->getId()]);
            } else if ($lineItem->getPayload() && isset($lineItem->getPayload()['productNumber'])) {
                $criteria->addFilter(new EqualsFilter('productNumber', $lineItem->getPayload()['productNumber']));
            }

            $products = $this->productRepository->search($criteria, $context);

            if ($products->count()) {
                $data->set($key, $products->first());
            } else {
                $data->set($key, null);
            }
        }

        return $data->get($key);
    }

    protected function getContextHash(SalesChannelContext $context)
    {
        return md5(json_encode([
            $context->getSalesChannelId(),
            $context->getDomainId(),
            $context->getVersionId(),
            $context->getCurrencyId(),
            $context->getRuleIds(),
            $context->getTaxState()
        ]));
    }

    /**
     * @param PriceCollection $prices
     * @param $quantity
     * @return CalculatedPrice|null
     */
    protected function getPriceByQuantity(PriceCollection $prices, $quantity)
    {
        $newPrice = $prices->first();
        $prices = $prices->getElements();

        foreach ($prices as $index => $price) {
            $min = $index === 0 ? 1 : ($prices[$index - 1]->getQuantity() + 1);
            $max = $index === (count($prices) - 1) ? null : $price->getQuantity();

            /** @var CalculatedPrice $price */
            if ($quantity >= $min && ($max === null || $quantity <= $max)) {
                $newPrice = $price;
                break;
            }
        }

        return $newPrice;
    }

    /**
     * @param PriceCollection $prices
     * @return string
     */
    protected function buildPriceCollectionHash(PriceCollection $prices)
    {
        $data = '';

        foreach ($prices->getElements() as $price) {
            $data .= $price->getQuantity() . '-' . $price->getUnitPrice() . '-' . $price->getCalculatedTaxes()->getAmount() . ";";
        }

        return md5($data);
    }

    /**
     * @param LineItem $lineItem
     * @param ProductEntity $product
     * @param null $salesChannelId
     * @return bool
     */
    protected function checkIsAllowed(LineItem $lineItem, ProductEntity $product, $salesChannelId = null)
    {
        if (!$product->getParentId()) {
            return false;
        }

        if ($lineItem->hasExtension('FreeProduct')) {
            if ($lineItem->getExtension('FreeProduct')->getVars()['isFreeProduct']) {
                return false;
            }
        }

        $config = $this->configService->get($this->configNamespace, $salesChannelId);

        if (!isset($config['blacklist'])) {
            $config['blacklist'] = '';
        }

        if (!isset($config['whitelist'])) {
            $config['whitelist'] = '';
        }

        $config['whitelist'] = trim($config['whitelist']);
        $config['blacklist'] = trim($config['blacklist']);

        $productNumber = $product->getProductNumber();

        // remove variant suffix from product number
        if (preg_match('/^(.*)(\.\d)$/', $productNumber, $matches)) {
            $productNumber = $matches[1];
        }

        if (
            $config['blacklist'] &&
            $this->checkWildcards(explode(",", $config['blacklist']), $productNumber)
        ) {
            return false;
        }

        if (
            $config['whitelist'] &&
            !$this->checkWildcards(explode(",", $config['whitelist']), $productNumber)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param array $patterns
     * @param $subject
     * @return bool
     */
    protected function checkWildcards(array $patterns, $subject)
    {
        foreach ($patterns as $pattern) {
            if ($this->checkWildcard($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $pattern
     * @param $subject
     * @return bool
     */
    protected function checkWildcard($pattern, $subject)
    {
        $pattern = trim($pattern);

        if ($pattern === '*' || empty($pattern)) {
            return true;
        }

        $pattern = str_replace(".", "\\.", $pattern);
        $pattern = str_replace('*', '.*', trim($pattern));

        return preg_match('#^' . $pattern . '#', $subject);
    }


    /**
     * Returns true if the plugin is called from admin context when editing prices in manual orders.
     *
     * @return bool
     */
    protected function isAdminRequest(SalesChannelContext $context)
    {
        if (
            $context && $context->getContext()
            && $context->getContext()->getSource() instanceof AdminApiSource
        ) {
            return true;
        }

        return false;
    }

    /**
     * Increases the price in a QuantityPriceDefinition by a given amount.
     *
     * @param float $amount The amount to increase the price by.
     * @param QuantityPriceDefinition $definition The QuantityPriceDefinition to modify.
     * @return QuantityPriceDefinition The modified QuantityPriceDefinition.
     */
    private function increasePriceInDefinition(float $amount, QuantityPriceDefinition $definition): QuantityPriceDefinition
    {
        $rawDefinition = json_decode(json_encode($definition), true);
        $rawDefinition['price'] = $rawDefinition['price'] + $amount;

        return QuantityPriceDefinition::fromArray($rawDefinition);
    }
}

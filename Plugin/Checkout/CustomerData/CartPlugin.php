<?php

namespace Mediarox\InstantEstimatedShipping\Plugin\Checkout\CustomerData;

use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Helper\Data;
use Magento\Checkout\Model\Session;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Mediarox\InstantEstimatedShipping\Model\Config;
use Mediarox\InstantEstimatedShipping\Model\ShippingEstimator;

class CartPlugin
{
    public function __construct(
        protected Session $checkoutSession,
        protected ShippingEstimator $shippingEstimator,
        protected Config $config,
        protected Data $checkoutHelper
    ) {
    }

    public function afterGetSectionData(Cart $subject, $result)
    {
        $quote = $this->checkoutSession->getQuote();
        $result['enable_shipping_estimate'] = (int)$this->config->getEnableMinicart();
        if ($result['enable_shipping_estimate'] && $quote->getId()) {
            $result = array_merge($this->getShippingTotals($quote), $result);
        }
        return $result;
    }

    private function getShippingTotals(CartInterface|Quote $quote)
    {
        $displayOption = $this->config->getShipping();
        $addressTotals = $this->shippingEstimator->estimateShipping($quote);
        $shippingExclTax = $this->checkoutHelper->formatPrice($addressTotals?->getData('shipping_amount'));
        $shippingInclTax = $this->checkoutHelper->formatPrice($addressTotals?->getData('shipping_incl_tax'));
        switch ($displayOption) {
            case \Magento\Tax\Model\Config::DISPLAY_TYPE_EXCLUDING_TAX:
                $result['shipping_excl_tax'] = $shippingExclTax;
                break;
            case \Magento\Tax\Model\Config::DISPLAY_TYPE_INCLUDING_TAX:
                $result['shipping_incl_tax'] = $shippingInclTax;
                break;
            default:
                $result['shipping_excl_tax'] = $shippingExclTax;
                $result['shipping_incl_tax'] = $shippingInclTax;
                break;
        }
        return $result;
    }
}

<?php

/**
 * @package   Mediarox_InstantEstimatedShipping
 * @copyright Copyright 2024 (c) mediarox UG (haftungsbeschraenkt)
 *            (http://www.mediarox.de)
 * @author    Marcus Bernt <mbernt@mediarox.de>
 */

declare(strict_types=1);

namespace Mediarox\InstantEstimatedShipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const XML_CONFIG_ENABLE = 'module_instant_estimated_shipping/general/enable';
    public const XML_CONFIG_USE_LOWEST = 'module_instant_estimated_shipping/general/use_lowest';
    public const XML_CONFIG_ENABLE_MINICART = 'module_instant_estimated_shipping/general/minicart/enable_minicart';
    public const XML_CONFIG_MINICART_SHIPPING = 'module_instant_estimated_shipping/general/minicart/shipping';

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager,
    ) {
    }

    public function getEnable(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_CONFIG_ENABLE,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }
    public function getEnableMinicart(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_CONFIG_ENABLE_MINICART,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }

    public function getUseLowest(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_CONFIG_USE_LOWEST,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }

    public function getShipping()
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_CONFIG_MINICART_SHIPPING,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }
}

<?php

/**
 * @package   Mediarox_InstantEstimatedShipping
 * @copyright Copyright 2022 (c) mediarox UG (haftungsbeschraenkt) (http://www.mediarox.de)
 * @author    Marcus Bernt <mbernt@mediarox.de>
 */

declare(strict_types=1);

namespace Mediarox\InstantEstimatedShipping\Plugin\Checkout\Model;

use Magento\Checkout\Model\DefaultConfigProvider;
use Magento\Checkout\Model\Session;
use Mediarox\InstantEstimatedShipping\Model\Config;
use Mediarox\InstantEstimatedShipping\Model\ShippingEstimator;

class DefaultConfigProviderPlugin
{

    public function __construct(
        protected Session $checkoutSession,
        protected Config $config,
        protected ShippingEstimator $shippingEstimator
    ) {
    }

    public function beforeGetConfig(DefaultConfigProvider $subject)
    {
        $quote = $this->checkoutSession->getQuote();
        if ($this->config->getEnable() && $quote->getId()) {
            $this->shippingEstimator->estimateShipping($quote);
        }
    }
}

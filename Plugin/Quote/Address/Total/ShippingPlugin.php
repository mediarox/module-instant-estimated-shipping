<?php
/**
 * Copyright 2022 (c) mediarox UG (haftungsbeschraenkt) (http://www.mediarox.de)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Mediarox\InstantEstimatedShipping\Plugin\Quote\Address\Total;

use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\Shipping;
use Magento\Quote\Model\ShippingMethodManagement;

class ShippingPlugin
{
    protected ScopeConfigInterface $storeConfig;
    protected ShippingMethodManagement $shippingMethodManagement;
    protected Data $directoryHelper;

    public function __construct(
        ScopeConfigInterface $storeConfig,
        ShippingMethodManagement $shippingMethodManagement,
        Data $directoryHelper
    ) {
        $this->storeConfig = $storeConfig;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->directoryHelper = $directoryHelper;
    }
    
    public function beforeCollect(
        Shipping $subject,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        $items = $shippingAssignment->getItems();
        if (!count($items)) {
            return null;
        }

        if (!$quote->getShippingAddress()->getCountryId()) {
            $quote->getShippingAddress()->setCountryId($this->directoryHelper->getDefaultCountry());
        }

        /** @var Quote\Address\Rate $bestRate */
        $bestRate = $quote->getShippingAddress()
            ->collectShippingRates()
            ->getShippingRatesCollection()
            ->setOrder('price', 'ASC')
            ->getFirstItem();
        if ($bestRate->getCode() && !$quote->getShippingAddress()->getShippingMethod()) {
            if ($quoteId = $quote->getId()) {
                try {
                    $this->shippingMethodManagement->set($quoteId, $bestRate->getCarrier(), $bestRate->getMethod());
                } catch (CouldNotSaveException | InputException | NoSuchEntityException | StateException $e) {
                    $quote->addErrorInfo(
                        'error',
                        self::class . '::' . __METHOD__,
                        $e->getCode(),
                        $e->getMessage()
                    );
                }
            } else {
                $quote->getShippingAddress()->setShippingMethod($bestRate->getCode());
            }
        }
        return [$quote, $shippingAssignment, $total];
    }
}

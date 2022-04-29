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
use Magento\Directory\Helper\Data;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ShippingMethodManagement;

class DefaultConfigProviderPlugin
{
    protected Session $checkoutSession;
    protected Quote\Address\FreeShippingInterface $freeShipping;
    protected ShippingMethodManagement $shippingMethodManagement;
    protected Data $directoryHelper;

    public function __construct(
        Session $checkoutSession,
        Quote\Address\FreeShippingInterface $freeShipping,
        ShippingMethodManagement $shippingMethodManagement,
        Data $directoryHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->freeShipping = $freeShipping;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->directoryHelper = $directoryHelper;
    }

    /**
     * Gets shipping assignments data like items weight, address weight, items quantity.
     *
     * @param  AddressInterface $address
     * @param  array            $items
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function getAssignmentWeightData(AddressInterface $address, array $items): array
    {
        $address->setWeight(0);
        $address->setFreeMethodWeight(0);
        $addressWeight = $address->getWeight();
        $freeMethodWeight = $address->getFreeMethodWeight();
        $addressFreeShipping = (bool)$address->getFreeShipping();
        $addressQty = 0;
        foreach ($items as $item) {
            /**
             * Skip if this item is virtual
             */
            if ($item->getProduct()->isVirtual()) {
                continue;
            }

            /**
             * Children weight we calculate for parent
             */
            if ($item->getParentItem()) {
                continue;
            }

            $itemQty = (float)$item->getQty();
            $itemWeight = (float)$item->getWeight();

            if ($item->getHasChildren() && $item->isShipSeparately()) {
                foreach ($item->getChildren() as $child) {
                    if ($child->getProduct()->isVirtual()) {
                        continue;
                    }
                    $addressQty += $child->getTotalQty();

                    if (!$item->getProduct()->getWeightType()) {
                        $itemWeight = (float)$child->getWeight();
                        $itemQty = (float)$child->getTotalQty();
                        $addressWeight += ($itemWeight * $itemQty);
                        $rowWeight = $this->getItemRowWeight(
                            $addressFreeShipping,
                            $itemWeight,
                            $itemQty,
                            $child->getFreeShipping()
                        );
                        $freeMethodWeight += $rowWeight;
                        $item->setRowWeight($rowWeight);
                    }
                }
                if ($item->getProduct()->getWeightType()) {
                    $addressWeight += ($itemWeight * $itemQty);
                    $rowWeight = $this->getItemRowWeight(
                        $addressFreeShipping,
                        $itemWeight,
                        $itemQty,
                        $item->getFreeShipping()
                    );
                    $freeMethodWeight += $rowWeight;
                    $item->setRowWeight($rowWeight);
                }
            } else {
                if (!$item->getProduct()->isVirtual()) {
                    $addressQty += $itemQty;
                }
                $addressWeight += ($itemWeight * $itemQty);
                $rowWeight = $this->getItemRowWeight(
                    $addressFreeShipping,
                    $itemWeight,
                    $itemQty,
                    $item->getFreeShipping()
                );
                $freeMethodWeight += $rowWeight;
                $item->setRowWeight($rowWeight);
            }
        }

        return [
            'addressQty'       => $addressQty,
            'addressWeight'    => $addressWeight,
            'freeMethodWeight' => $freeMethodWeight,
        ];
    }

    /**
     * Calculates item row weight.
     *
     * @param  bool  $addressFreeShipping
     * @param  float $itemWeight
     * @param  float $itemQty
     * @param  bool  $freeShipping
     * @return float
     */
    private function getItemRowWeight(
        bool $addressFreeShipping,
        float $itemWeight,
        float $itemQty,
        $freeShipping
    ): float {
        $rowWeight = $itemWeight * $itemQty;
        if ($addressFreeShipping || $freeShipping === true) {
            $rowWeight = 0;
        } elseif (is_numeric($freeShipping)) {
            $freeQty = $freeShipping;
            if ($itemQty > $freeQty) {
                $rowWeight = $itemWeight * ($itemQty - $freeQty);
            } else {
                $rowWeight = 0;
            }
        }
        return (float)$rowWeight;
    }

    private function setShippingAddressValues(Quote\Address $shippingAddress, Quote $quote)
    {
        $items = $shippingAddress->getAllItems();
        $data = $this->getAssignmentWeightData($shippingAddress, $items);

        $shippingAddress->setItemQty($data['addressQty']);
        $shippingAddress->setWeight($data['addressWeight']);
        $shippingAddress->setFreeMethodWeight($data['freeMethodWeight']);
        $shippingAddressFreeShipping = (bool)$shippingAddress->getFreeShipping();
        $isFreeShipping = $this->freeShipping->isFreeShipping($quote, $quote->getAllItems());
        $shippingAddress->setFreeShipping($isFreeShipping);
        if (!$shippingAddressFreeShipping && $isFreeShipping) {
            $data = $this->getAssignmentWeightData($shippingAddress, $items);
            $shippingAddress->setItemQty($data['addressQty']);
            $shippingAddress->setWeight($data['addressWeight']);
            $shippingAddress->setFreeMethodWeight($data['freeMethodWeight']);
        }
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
    }

    public function beforeGetConfig(DefaultConfigProvider $subject)
    {
        $quote = $this->checkoutSession->getQuote();
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress->getShippingMethod()) {
            return null;
        }

        if (!$shippingAddress->getCountryId()) {
            $shippingAddress->setCountryId($this->directoryHelper->getDefaultCountry());
        }

        $this->setShippingAddressValues($shippingAddress, $quote);
        $bestRate = null;
        $rates = $shippingAddress->getAllShippingRates();
        if ($rates) {
            // sort highest to lowest rate
            usort($rates, function ($rate1, $rate2) {
                return $rate2->getPrice() <=> $rate1->getPrice();
            });
            $bestRate = array_shift($rates);
        }
        if ($bestRate) {
            try {
                $this->shippingMethodManagement->set($quote->getId(), $bestRate->getCarrier(), $bestRate->getMethod());
            } catch (CouldNotSaveException | InputException | NoSuchEntityException | StateException $e) {
                $quote->addErrorInfo(
                    'error',
                    self::class . '::' . __METHOD__,
                    $e->getCode(),
                    $e->getMessage()
                );
            }
        }
        return null;
    }
}

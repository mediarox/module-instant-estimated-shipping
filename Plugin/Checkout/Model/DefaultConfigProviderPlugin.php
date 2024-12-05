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
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Directory\Helper\Data;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartExtensionFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ShippingAssignmentFactory;
use Magento\Quote\Model\ShippingFactory;
use Magento\Store\Model\StoreManagerInterface;
use Mediarox\InstantEstimatedShipping\Model\Config;

class DefaultConfigProviderPlugin
{
    protected mixed $addressMethods;

    public function __construct(
        protected Session $checkoutSession,
        protected Data $directoryHelper,
        protected StoreManagerInterface $storeManager,
        protected CartExtensionFactory $cartExtensionFactory,
        protected ShippingAssignmentFactory $assignmentFactory,
        protected ShippingFactory $shippingFactory,
        protected ShippingMethodManagementInterface $methodManagement,
        protected Config $config,
        protected Quote\TotalsCollector $totalsCollector
    ) {
    }

    public function beforeGetConfig(DefaultConfigProvider $subject)
    {
        $quote = $this->checkoutSession->getQuote();
        if ($this->config->getEnable() && $quote->getId()) {
            $shippingAddress = $this->getShippingAddress($quote);
            if (!$shippingAddress->getShippingMethod()) {
                $this->processShippingAssignment($quote, $shippingAddress);
            }
        }
    }

    private function getShippingAddress(Quote $quote)
    {
        if ($quote->getCustomerId() && $shippingAddress = $this->getCustomerShippingAddress($quote)) {
            return $shippingAddress;
        }

        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress->getId() && $shippingAddress->getCountryId()) {
            return $shippingAddress;
        }

        if (!$shippingAddress->getCountryId()) {
            $shippingAddress->setCountryId($this->directoryHelper->getDefaultCountry($this->storeManager->getStore()));
        }
        return $shippingAddress;
    }

    private function getCustomerShippingAddress(Quote $quote)
    {
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress->getId() && $shippingAddress->getCustomerAddressId()) {
            return $shippingAddress;
        }

        $customerAddress = $this->getCustomerAddress($quote->getCustomer());

        if (!$customerAddress) {
            return false;
        }

        $addressByCustomer = $quote->getShippingAddressByCustomerAddressId($customerAddress->getId());
        if ($addressByCustomer) {
            $quote->setShippingAddress($addressByCustomer);
            return $addressByCustomer;
        }

        $shippingAddress->importCustomerAddressData($customerAddress);

        return $shippingAddress;
    }

    private function getCustomerAddress(CustomerInterface $customer)
    {
        $customer->getDefaultShipping();
        $addresses = $customer->getAddresses();
        if (empty($addresses)) {
            return false;
        }

        foreach ($addresses as $address) {
            if ($address->isDefaultShipping()) {
                return $address;
            }
        }

        return reset($addresses);
    }

    public function processShippingAssignment(CartInterface $quote, AddressInterface $shippingAddress)
    {
        $shippingMethod = $this->getDefaultShippingMethod($shippingAddress);

        if ($shippingMethod) {
            $cartExtension = $quote->getExtensionAttributes();
            if ($cartExtension === null) {
                $cartExtension = $this->cartExtensionFactory->create();
            }

            $shippingAssignments = $cartExtension->getShippingAssignments();
            if (empty($shippingAssignments)) {
                $shippingAssignment = $this->assignmentFactory->create();
            } else {
                $shippingAssignment = $shippingAssignments[0];
            }

            $shipping = $shippingAssignment->getShipping();
            if ($shipping === null) {
                $shipping = $this->shippingFactory->create();
            }

            $carrierCode = $shippingMethod->getCarrierCode();
            $shippingAddress->setLimitCarrier($carrierCode);
            $methodCode = $shippingMethod->getMethodCode();
            $method = $carrierCode . '_' . $methodCode;
            $shippingAddress->setShippingMethod($method);
            $shipping->setAddress($shippingAddress);
            $shipping->setMethod($method);
            $shippingAssignment->setShipping($shipping);
            $shippingAssignment->setItems($quote->getItems());
            $cartExtension->setShippingAssignments([$shippingAssignment]);
            $quote->setExtensionAttributes($cartExtension);
            $this->totalsCollector->collectAddressTotals($quote, $shippingAddress);
        }
    }

    private function getDefaultShippingMethod(AddressInterface $shippingAddress)
    {
        $methods = $this->getShippingMethods($shippingAddress);
        foreach ($methods as $key => $method) {
            if (!$method->getAvailable()) {
                unset($methods[$key]);
            }
        }

        if (count($methods) === 1) {
            return reset($methods);
        }

        usort($methods, function ($method1, $method2) {
                if ($this->config->getUseLowest()) {
                    return $method1->getPriceInclTax() <=> $method2->getPriceInclTax();
                }
                return $method2->getPriceInclTax() <=> $method1->getPriceInclTax();
            }
        );
        return reset($methods);
    }

    private function getShippingMethods(AddressInterface $shippingAddress)
    {
        $addressId = $shippingAddress->getId();
        if (isset($this->addressMethods[$addressId])) {
            return $this->addressMethods[$addressId];
        }
        if ($customerAddrId = (int)$shippingAddress->getCustomerAddressId()) {
            $methods = $this->methodManagement->estimateByAddressId(
                $shippingAddress->getQuoteId(),
                $customerAddrId
            );
        } else {
            $methods = $this->methodManagement->estimateByExtendedAddress(
                $shippingAddress->getQuoteId(),
                $shippingAddress
            );
        }
        $this->addressMethods[$addressId] = $methods;

        return $this->addressMethods[$addressId];
    }
}

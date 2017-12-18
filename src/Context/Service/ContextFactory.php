<?php declare(strict_types=1);
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Context\Service;

use Doctrine\DBAL\Connection;
use Shopware\Api\Country\Repository\CountryRepository;
use Shopware\Api\Country\Repository\CountryStateRepository;
use Shopware\Api\Currency\Repository\CurrencyRepository;
use Shopware\Api\Currency\Struct\CurrencyBasicStruct;
use Shopware\Api\Customer\Repository\CustomerAddressRepository;
use Shopware\Api\Customer\Repository\CustomerGroupRepository;
use Shopware\Api\Customer\Repository\CustomerRepository;
use Shopware\Api\Customer\Struct\CustomerBasicStruct;
use Shopware\Api\Entity\Search\Criteria;
use Shopware\Api\Payment\Repository\PaymentMethodRepository;
use Shopware\Api\Payment\Struct\PaymentMethodBasicStruct;
use Shopware\Api\Shipping\Repository\ShippingMethodRepository;
use Shopware\Api\Shipping\Struct\ShippingMethodBasicStruct;
use Shopware\Api\Shop\Repository\ShopRepository;
use Shopware\Api\Shop\Struct\ShopDetailStruct;
use Shopware\Api\Tax\Repository\TaxRepository;
use Shopware\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Context\Struct\CheckoutScope;
use Shopware\Context\Struct\CustomerScope;
use Shopware\Context\Struct\ShopContext;
use Shopware\Context\Struct\ShopScope;
use Shopware\Context\Struct\TranslationContext;
use Shopware\Storefront\Context\StorefrontContextService;

class ContextFactory implements ContextFactoryInterface
{
    /**
     * @var ShopRepository
     */
    private $shopRepository;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var CustomerRepository
     */
    private $customerRepository;

    /**
     * @var CustomerGroupRepository
     */
    private $customerGroupRepository;

    /**
     * @var CountryRepository
     */
    private $countryRepository;

    /**
     * @var TaxRepository
     */
    private $taxRepository;

    /**
     * @var CustomerAddressRepository
     */
    private $addressRepository;

    /**
     * @var PaymentMethodRepository
     */
    private $paymentMethodRepository;

    /**
     * @var ShippingMethodRepository
     */
    private $shippingMethodRepository;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var CountryStateRepository
     */
    private $countryStateRepository;

    public function __construct(
        ShopRepository $shopRepository,
        CurrencyRepository $currencyRepository,
        CustomerRepository $customerRepository,
        CustomerGroupRepository $customerGroupRepository,
        CountryRepository $countryRepository,
        TaxRepository $taxRepository,
        CustomerAddressRepository $addressRepository,
        PaymentMethodRepository $paymentMethodRepository,
        ShippingMethodRepository $shippingMethodRepository,
        Connection $connection,
        CountryStateRepository $countryStateRepository
    ) {
        $this->shopRepository = $shopRepository;
        $this->currencyRepository = $currencyRepository;
        $this->customerRepository = $customerRepository;
        $this->customerGroupRepository = $customerGroupRepository;
        $this->countryRepository = $countryRepository;
        $this->taxRepository = $taxRepository;
        $this->addressRepository = $addressRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->connection = $connection;
        $this->countryStateRepository = $countryStateRepository;
    }

    public function create(
        ShopScope $shopScope,
        CustomerScope $customerScope,
        CheckoutScope $checkoutScope
    ): ShopContext {
        $translationContext = $this->getTranslationContext($shopScope->getShopUuid());

        //select shop with all fallbacks
        /** @var ShopDetailStruct $shop */
        $shop = $this->shopRepository->readDetail([$shopScope->getShopUuid()], $translationContext)
            ->get($shopScope->getShopUuid());

        if (!$shop) {
            throw new \RuntimeException(sprintf('Shop with id %s not found or not valid!', $shopScope->getShopUuid()));
        }

        //load active currency, fallback to shop currency
        $currency = $this->getCurrency($shop, $shopScope->getCurrencyUuid(), $translationContext);

        //fallback customer group is hard coded to 'EK'
        $customerGroups = $this->customerGroupRepository->readBasic(
            [StorefrontContextService::FALLBACK_CUSTOMER_GROUP],
            $translationContext
        );

        $fallbackGroup = $customerGroups->get(StorefrontContextService::FALLBACK_CUSTOMER_GROUP);
        $customerGroup = $shop->getCustomerGroup();

        $customer = null;

        if ($customerScope->getCustomerUuid() !== null) {
            //load logged in customer and set active addresses
            $customer = $this->loadCustomer($customerScope, $translationContext);

            $shippingLocation = ShippingLocation::createFromAddress($customer->getActiveShippingAddress());

            $customerGroup = $customer->getGroup();
        } else {
            //load not logged in customer with default shop configuration or with provided checkout scopes
            $shippingLocation = $this->loadShippingLocation($shop, $translationContext, $checkoutScope);
        }

        //customer group switched?
        if ($customerScope->getCustomerGroupUuid() !== null) {
            $customerGroup = $this->customerGroupRepository->readBasic([$customerScope->getCustomerGroupUuid()], $translationContext)
                ->get($customerScope->getCustomerGroupUuid());
        }

        //loads tax rules based on active customer group and delivery address
        //todo@dr load area based tax rules
        $criteria = new Criteria();
        $taxRules = $this->taxRepository->search($criteria, $translationContext);

        //detect active payment method, first check if checkout defined other payment method, otherwise validate if customer logged in, at least use shop default
        $payment = $this->getPaymentMethod($customer, $shop, $translationContext, $checkoutScope);

        //detect active delivery method, at first checkout scope, at least shop default method
        $delivery = $this->getShippingMethod($shop, $translationContext, $checkoutScope);

        $context = new ShopContext(
            $shop,
            $currency,
            $customerGroup,
            $fallbackGroup,
            $taxRules,
            $payment,
            $delivery,
            $shippingLocation,
            $customer
        );

        return $context;
    }

    private function getCurrency(ShopDetailStruct $shop, ?string $currencyUuid, TranslationContext $context): CurrencyBasicStruct
    {
        if ($currencyUuid === null) {
            return $shop->getCurrency();
        }

        $currency = $this->currencyRepository->readBasic([$currencyUuid], $context);

        if (!$currency->has($currencyUuid)) {
            return $shop->getCurrency();
        }

        return $currency->get($currencyUuid);
    }

    private function getPaymentMethod(?CustomerBasicStruct $customer, ShopDetailStruct $shop, TranslationContext $context, CheckoutScope $checkoutScope): PaymentMethodBasicStruct
    {
        //payment switched in checkout?
        if ($checkoutScope->getPaymentMethodUuid() !== null) {
            return $this->paymentMethodRepository->readBasic([$checkoutScope->getPaymentMethodUuid()], $context)
                ->get($checkoutScope->getPaymentMethodUuid());
        }

        //customer has a last payment method from previous order?
        if ($customer && $customer->getLastPaymentMethod()) {
            return $customer->getLastPaymentMethod();
        }

        //customer selected a default payment method in registration
        if ($customer && $customer->getDefaultPaymentMethod()) {
            return $customer->getDefaultPaymentMethod();
        }

        //at least use default payment method which defined for current shop
        return $shop->getPaymentMethod();
    }

    private function getShippingMethod(ShopDetailStruct $shop, TranslationContext $context, CheckoutScope $checkoutScope): ShippingMethodBasicStruct
    {
        if ($checkoutScope->getShippingMethodUuid() !== null) {
            return $this->shippingMethodRepository->readBasic([$checkoutScope->getShippingMethodUuid()], $context)
                ->get($checkoutScope->getShippingMethodUuid());
        }

        return $shop->getShippingMethod();
    }

    private function getTranslationContext(string $shopUuid): TranslationContext
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['uuid', 'is_default', 'fallback_translation_uuid']);
        $query->from('shop', 'shop');
        $query->where('shop.uuid = :uuid');
        $query->setParameter('uuid', $shopUuid);

        $data = $query->execute()->fetch(\PDO::FETCH_ASSOC);

        return new TranslationContext(
            $data['uuid'],
            (bool) $data['is_default'],
            $data['fallback_translation_uuid'] ?: null
        );
    }

    private function loadCustomer(CustomerScope $customerScope, TranslationContext $translationContext): ?CustomerBasicStruct
    {
        $customers = $this->customerRepository->readBasic([$customerScope->getCustomerUuid()], $translationContext);
        $customer = $customers->get($customerScope->getCustomerUuid());

        if (!$customer) {
            return $customer;
        }

        if ($customerScope->getBillingAddressUuid() === null && $customerScope->getShippingAddressUuid() === null) {
            return $customer;
        }

        $addresses = $this->addressRepository->readBasic(
            [$customerScope->getBillingAddressUuid(), $customerScope->getShippingAddressUuid()],
            $translationContext
        );

        //billing address changed within checkout?
        if ($customerScope->getBillingAddressUuid() !== null) {
            $customer->setActiveBillingAddress($addresses->get($customerScope->getBillingAddressUuid()));
        }

        //shipping address changed within checkout?
        if ($customerScope->getShippingAddressUuid() !== null) {
            $customer->setActiveShippingAddress($addresses->get($customerScope->getShippingAddressUuid()));
        }

        return $customer;
    }

    private function loadShippingLocation(
        ShopDetailStruct $shop,
        TranslationContext $translationContext,
        CheckoutScope $checkoutScope
    ): ShippingLocation {
        //allows to preview cart calculation for a specify state for not logged in customers
        if ($checkoutScope->getStateUuid() !== null) {
            $state = $this->countryStateRepository->readBasic([$checkoutScope->getStateUuid()], $translationContext)
                ->get($checkoutScope->getStateUuid());

            $country = $this->countryRepository->readBasic([$state->getCountryUuid()], $translationContext)
                ->get($state->getCountryUuid());

            return new ShippingLocation($country, $state, null);
        }

        //allows to preview cart calculation for a specify country for not logged in customers
        if ($checkoutScope->getCountryUuid() !== null) {
            $country = $this->countryRepository->readBasic([$checkoutScope->getCountryUuid()], $translationContext)
                ->get($checkoutScope->getCountryUuid());

            return ShippingLocation::createFromCountry($country);
        }

        return ShippingLocation::createFromCountry($shop->getCountry());
    }
}
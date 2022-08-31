<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * You can find more information about us on https://bitbag.io and write us
 * an email on hello@bitbag.io.
 */

declare(strict_types=1);

namespace BitBag\SyliusMultiVendorMarketplacePlugin\Fixture\Factory;

use BitBag\SyliusMultiVendorMarketplacePlugin\Entity\OrderInterface;
use BitBag\SyliusMultiVendorMarketplacePlugin\Entity\OrderItemInterface;
use BitBag\SyliusMultiVendorMarketplacePlugin\Entity\ProductInterface;
use BitBag\SyliusMultiVendorMarketplacePlugin\Factory\OrderFactoryInterface;
use BitBag\SyliusMultiVendorMarketplacePlugin\Factory\OrderItemFactoryInterface;
use BitBag\SyliusMultiVendorMarketplacePlugin\Processor\Order\SplitOrderByVendorProcessorInterface;
use BitBag\SyliusMultiVendorMarketplacePlugin\Repository\ProductRepositoryInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Bundle\CoreBundle\Fixture\Factory\AbstractExampleFactory;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Bundle\CoreBundle\Fixture\OptionsResolver\LazyOption;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Webmozart\Assert\Assert;

final class OrderExampleFactory extends AbstractExampleFactory implements ExampleFactoryInterface
{
    private OrderFactoryInterface $orderFactory;

    private OrderItemFactoryInterface $orderItemFactory;

    private OrderItemQuantityModifierInterface $orderItemQuantityModifier;

    private ObjectManager $orderManager;

    private RepositoryInterface $channelRepository;

    private RepositoryInterface $customerRepository;

    private ProductRepositoryInterface $productRepository;

    private RepositoryInterface $countryRepository;

    private PaymentMethodRepositoryInterface $paymentMethodRepository;

    private ShippingMethodRepositoryInterface $shippingMethodRepository;

    private FactoryInterface $addressFactory;

    private StateMachineFactoryInterface $stateMachineFactory;

    private SplitOrderByVendorProcessorInterface $splitOrderByVendorProcessor;

    private Generator $faker;

    private OptionsResolver $optionsResolver;

    public function __construct(
        OrderFactoryInterface $orderFactory,
        OrderItemFactoryInterface $orderItemFactory,
        OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        ObjectManager $orderManager,
        RepositoryInterface $channelRepository,
        RepositoryInterface $customerRepository,
        ProductRepositoryInterface $productRepository,
        RepositoryInterface $countryRepository,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        ShippingMethodRepositoryInterface $shippingMethodRepository,
        FactoryInterface $addressFactory,
        StateMachineFactoryInterface $stateMachineFactory,
        SplitOrderByVendorProcessorInterface $splitOrderByVendorProcessor
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->orderItemQuantityModifier = $orderItemQuantityModifier;
        $this->orderManager = $orderManager;
        $this->channelRepository = $channelRepository;
        $this->customerRepository = $customerRepository;
        $this->productRepository = $productRepository;
        $this->countryRepository = $countryRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->addressFactory = $addressFactory;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->splitOrderByVendorProcessor = $splitOrderByVendorProcessor;

        $this->optionsResolver = new OptionsResolver();
        $this->faker = Factory::create();
        $this->configureOptions($this->optionsResolver);
    }

    public function create(array $options = []): array
    {
        $options = $this->optionsResolver->resolve($options);

        $orders = $this->createOrder($options['channel'], $options['customer'], $options['country'], $options['complete_date']);
        foreach ($orders as $order) {
            $this->setOrderCompletedDate($order, $options['complete_date']);
            if ($options['fulfilled']) {
                $this->fulfillOrder($order);
            }
        }

        return $orders;
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('amount', 20)

            ->setDefault('channel', LazyOption::randomOne($this->channelRepository))
            ->setAllowedTypes('channel', ['null', 'string', ChannelInterface::class])
            ->setNormalizer('channel', LazyOption::getOneBy($this->channelRepository, 'code'))

            ->setDefault('customer', LazyOption::randomOne($this->customerRepository))
            ->setAllowedTypes('customer', ['null', 'string', CustomerInterface::class])
            ->setNormalizer('customer', LazyOption::getOneBy($this->customerRepository, 'email'))

            ->setDefault('country', LazyOption::randomOne($this->countryRepository))
            ->setAllowedTypes('country', ['null', 'string', CountryInterface::class])
            ->setNormalizer('country', LazyOption::findOneBy($this->countryRepository, 'code'))

            ->setDefault('complete_date', fn (Options $options): \DateTimeInterface => $this->faker->dateTimeBetween('-1 years', 'now'))
            ->setAllowedTypes('complete_date', ['null', \DateTime::class])

            ->setDefault('fulfilled', false)
            ->setAllowedTypes('fulfilled', ['bool'])
        ;
    }

    protected function createOrder(
        ChannelInterface $channel,
        CustomerInterface $customer,
        CountryInterface $country,
        \DateTimeInterface $createdAt
    ): array {
        $countryCode = $country->getCode();

        $currencyCode = $channel->getBaseCurrency()->getCode();
        $localeCode = $this->faker->randomElement($channel->getLocales()->toArray())->getCode();

        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $order->setCurrencyCode($currencyCode);
        $order->setLocaleCode($localeCode);

        $this->generateItems($order);

        $this->address($order, $countryCode);
        $this->selectShipping($order, $createdAt);
        $this->selectPayment($order, $createdAt);
        $orders = $this->completeCheckout($order);

        return $orders;
    }

    protected function generateItems(OrderInterface $order): void
    {
        $numberOfItems = random_int(1, 5);
        $channel = $order->getChannel();
        $products = $this->productRepository->findLatestByChannel($channel, $order->getLocaleCode(), 100);
        if (0 === count($products)) {
            throw new \InvalidArgumentException(sprintf(
                'You have no enabled products at the channel "%s", but they are required to create an orders for that channel',
                $channel->getCode(),
            ));
        }

        $generatedItems = [];

        for ($i = 0; $i < $numberOfItems; ++$i) {
            /** @var ProductInterface $product */
            $product = $this->faker->randomElement($products);
            $variant = $this->faker->randomElement($product->getVariants()->toArray());
            $variant->setCurrentLocale($order->getLocaleCode());

            if (array_key_exists($variant->getCode(), $generatedItems)) {
                /** @var OrderItemInterface $item */
                $item = $generatedItems[$variant->getCode()];
                $this->orderItemQuantityModifier->modify($item, $item->getQuantity() + random_int(1, 5));

                continue;
            }

            $item = $this->orderItemFactory->createNew();

            $item->setVariant($variant);
            $this->orderItemQuantityModifier->modify($item, random_int(1, 5));

            $generatedItems[$variant->getCode()] = $item;
            $order->addItem($item);
        }
    }

    protected function address(OrderInterface $order, string $countryCode): void
    {
        /** @var AddressInterface $address */
        $address = $this->addressFactory->createNew();
        $address->setFirstName($this->faker->firstName);
        $address->setLastName($this->faker->lastName);
        $address->setStreet($this->faker->streetAddress);
        $address->setCountryCode($countryCode);
        $address->setCity($this->faker->city);
        $address->setPostcode($this->faker->postcode);

        $order->setShippingAddress($address);
        $order->setBillingAddress(clone $address);

        $this->applyCheckoutStateTransition($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);
    }

    protected function selectShipping(OrderInterface $order, \DateTimeInterface $createdAt): void
    {
        if (OrderCheckoutStates::STATE_SHIPPING_SKIPPED === $order->getCheckoutState()) {
            return;
        }

        $channel = $order->getChannel();
        $shippingMethods = $this->shippingMethodRepository->findEnabledForChannel($channel);

        if (0 === count($shippingMethods)) {
            throw new \InvalidArgumentException(sprintf(
                'You have no shipping method available for the channel with code "%s", but they are required to proceed an order',
                $channel->getCode(),
            ));
        }

        $shippingMethod = $this->faker->randomElement($shippingMethods);

        /** @var ChannelInterface $channel */
        $channel = $order->getChannel();
        Assert::notNull($shippingMethod, $this->generateInvalidSkipMessage('shipping', $channel->getCode()));

        foreach ($order->getShipments() as $shipment) {
            $shipment->setMethod($shippingMethod);
            $shipment->setCreatedAt($createdAt);
        }

        $this->applyCheckoutStateTransition($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
    }

    protected function selectPayment(OrderInterface $order, \DateTimeInterface $createdAt): void
    {
        if (OrderCheckoutStates::STATE_PAYMENT_SKIPPED === $order->getCheckoutState()) {
            return;
        }

        $paymentMethod = $this
            ->faker
            ->randomElement($this->paymentMethodRepository->findEnabledForChannel($order->getChannel()))
        ;

        /** @var ChannelInterface $channel */
        $channel = $order->getChannel();
        Assert::notNull($paymentMethod, $this->generateInvalidSkipMessage('payment', $channel->getCode()));

        foreach ($order->getPayments() as $payment) {
            $payment->setMethod($paymentMethod);
            $payment->setCreatedAt($createdAt);
        }

        $this->applyCheckoutStateTransition($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
    }

    protected function completeCheckout(OrderInterface $order): array
    {
        if ($this->faker->boolean(25)) {
            $order->setNotes($this->faker->sentence);
        }
        $this->orderManager->persist($order);

        $orders = $this->splitOrderByVendorProcessor->process($order);

        foreach ($orders as $order) {
            $this->applyCheckoutStateTransition($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
            $this->orderManager->flush();
        }

        return $orders;
    }

    protected function applyCheckoutStateTransition(OrderInterface $order, string $transition): void
    {
        $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH)->apply($transition);
    }

    protected function generateInvalidSkipMessage(string $type, string $channelCode): string
    {
        return sprintf(
            "No enabled %s method was found for the channel '%s'. " .
            "Set 'skipping_%s_step_allowed' option to true for this channel if you want to skip %s method selection.",
            $type,
            $channelCode,
            $type,
            $type,
        );
    }

    protected function setOrderCompletedDate(OrderInterface $order, \DateTimeInterface $date): void
    {
        if (OrderCheckoutStates::STATE_COMPLETED === $order->getCheckoutState()) {
            $order->setCheckoutCompletedAt($date);
        }
    }

    protected function fulfillOrder(OrderInterface $order): void
    {
        $this->completePayments($order);
        $this->completeShipments($order);
    }

    protected function completePayments(OrderInterface $order): void
    {
        foreach ($order->getPayments() as $payment) {
            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_COMPLETE)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
            }
        }
    }

    protected function completeShipments(OrderInterface $order): void
    {
        foreach ($order->getShipments() as $shipment) {
            $stateMachine = $this->stateMachineFactory->get($shipment, ShipmentTransitions::GRAPH);
            if ($stateMachine->can(ShipmentTransitions::TRANSITION_SHIP)) {
                $stateMachine->apply(ShipmentTransitions::TRANSITION_SHIP);
            }
        }
    }
}

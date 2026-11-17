<?php declare(strict_types=1);

namespace Brunko\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Podla zvolenej platby:
 *  - dobierka (payment_cashpayment): pripocita poplatok (default 2,90 EUR s DPH) ako samostatnu polozku kosika,
 *  - platba vopred (payment_prepayment): odpocita zlavu (default 1 % z tovaru), zaokruhlenu nahor na desatiny eura.
 * Hodnoty sa menia v Rozsirenia > Moje rozsirenia > BrunkoCheckout > Konfigurovat.
 */
class PaymentAdjustmentProcessor implements CartProcessorInterface
{
    public const FEE_ID = 'brunko-cod-fee';
    public const DISCOUNT_ID = 'brunko-prepayment-discount';
    public const FEE_TYPE = 'brunko-payment-fee';
    // Vlastny typ (nie 'discount'), aby ho Shopware DiscountCartProcessor neprepocitaval a nepovazoval za odstranitelny.
    public const DISCOUNT_TYPE = 'brunko-payment-discount';
    public const COD = 'payment_cashpayment';
    public const PREPAYMENT = 'payment_prepayment';

    public function __construct(
        private readonly QuantityPriceCalculator $quantityCalculator,
        private readonly AbsolutePriceCalculator $absoluteCalculator,
        private readonly SystemConfigService $config,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // Najprv odstran stare polozky (kosik sa prepocitava pri kazdej zmene), potom pridaj podla aktualnej platby.
        $toCalculate->getLineItems()->remove(self::FEE_ID);
        $toCalculate->getLineItems()->remove(self::DISCOUNT_ID);

        $goods = $toCalculate->getLineItems()->filterGoods();
        if ($goods->count() === 0) {
            return;
        }

        $technicalName = $context->getPaymentMethod()->getTechnicalName();
        $scId = $context->getSalesChannelId();

        if ($technicalName === self::COD) {
            $fee = (float) ($this->config->get('BrunkoCheckout.config.codFee', $scId) ?? 2.9);
            $rate = (float) ($this->config->get('BrunkoCheckout.config.codFeeTaxRate', $scId) ?? 23.0);
            if ($fee <= 0) {
                return;
            }
            $definition = new QuantityPriceDefinition($fee, new TaxRuleCollection([new TaxRule($rate)]), 1);
            $item = new LineItem(self::FEE_ID, self::FEE_TYPE, null, 1);
            $item->setLabel('Poplatok za dobierku');
            $item->setStackable(false);
            $item->setRemovable(false);
            $item->setShippingCostAware(false);
            $item->setPriceDefinition($definition);
            $item->setPrice($this->quantityCalculator->calculate($definition, $context));
            $toCalculate->getLineItems()->add($item);

            return;
        }

        if ($technicalName === self::PREPAYMENT) {
            $percent = (float) ($this->config->get('BrunkoCheckout.config.prepaymentDiscountPercent', $scId) ?? 1.0);
            if ($percent <= 0) {
                return;
            }
            // Zlava sa zaokruhluje NAHOR na desatiny eura (0,51 -> 0,60), aby zakaznik dostal radsej o trosku viac a v kosiku neboli stotiny.
            $goodsTotal = $goods->getPrices()->sum()->getTotalPrice();
            $discount = ceil(round($goodsTotal * $percent / 100, 6) * 10 - 1e-9) / 10;
            if ($discount <= 0) {
                return;
            }
            $definition = new AbsolutePriceDefinition(-1 * $discount);
            $item = new LineItem(self::DISCOUNT_ID, self::DISCOUNT_TYPE, null, 1);
            $item->setLabel(sprintf('Zľava %s %% pri platbe vopred na účet', rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',')));
            $item->setStackable(false);
            $item->setRemovable(false);
            // Nesmie vstupit do vypoctu dodania, inak by dostala zalozny cas z dopravy (3-10 dni) a predlzila celu zasielku.
            $item->setShippingCostAware(false);
            $item->setPriceDefinition($definition);
            $item->setPrice($this->absoluteCalculator->calculate($definition->getPrice(), $goods->getPrices(), $context));
            $toCalculate->getLineItems()->add($item);
        }
    }
}

<?php declare(strict_types=1);

use Brunko\Theme\Subscriber\ProductListingSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ProductListingSubscriber::class)
        ->tag('kernel.event_subscriber');
};

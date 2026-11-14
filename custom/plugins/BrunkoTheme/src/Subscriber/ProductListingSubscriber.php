<?php declare(strict_types=1);

namespace Brunko\Theme\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads the product gallery (product.media) into category and search listings,
 * so the product card can show the second image on hover.
 */
class ProductListingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCriteriaEvent::class => 'addGallery',
            ProductSearchCriteriaEvent::class => 'addGallery',
        ];
    }

    public function addGallery(ProductListingCriteriaEvent $event): void
    {
        $criteria = $event->getCriteria();

        $criteria->addAssociation('media.media');
        $criteria->getAssociation('media')->addSorting(new FieldSorting('position'));
    }
}

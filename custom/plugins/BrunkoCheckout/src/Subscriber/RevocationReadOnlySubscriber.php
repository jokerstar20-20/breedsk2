<?php declare(strict_types=1);

namespace Brunko\Checkout\Subscriber;

use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Ziadosti o odstupenie sa v administracii nedaju upravovat (len prezerat a mazat); IBAN by sa inak ulozil necifrovany. */
class RevocationReadOnlySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'onPreWrite'];
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        if (!$event->getContext()->getSource() instanceof AdminApiSource) {
            return;
        }
        foreach ($event->getCommands() as $command) {
            if ($command instanceof UpdateCommand && $command->getEntityName() === 'custom_entity_odstupenie') {
                throw new \RuntimeException('Žiadosti o odstúpenie od zmluvy sa nedajú upravovať. Môžete ich len prezerať alebo zmazať.');
            }
        }
    }
}

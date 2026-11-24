<?php declare(strict_types=1);

namespace Brunko\Checkout\Subscriber;

use Brunko\Checkout\Service\IbanCipher;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Pri nacitani cez administraciu (prihlaseny sprava) sa IBAN zobrazi desifrovany; v databaze ostava sifrovany. */
class IbanDecryptSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly IbanCipher $cipher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['custom_entity_odstupenie.loaded' => 'onLoaded'];
    }

    public function onLoaded(EntityLoadedEvent $event): void
    {
        if (!$event->getContext()->getSource() instanceof AdminApiSource) {
            return;
        }
        foreach ($event->getEntities() as $entity) {
            $value = $entity->get('iban');
            if (\is_string($value) && $this->cipher->isEncrypted($value)) {
                $entity->assign(['iban' => $this->cipher->decrypt($value)]);
            }
        }
    }
}

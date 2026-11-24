<?php declare(strict_types=1);

namespace Brunko\Checkout\Subscriber;

use Brunko\Checkout\Service\IbanCipher;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\RevocationRequest\Event\RevocationRequestEvent;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Kazdu odoslanu ziadost o odstupenie od zmluvy ulozi do tabulky (app BrunkoOdstupenie),
 * aby bola viditelna v administracii (Objednavky > Odstupenie od zmluvy). E-maily sa posielaju bez zmeny.
 */
class RevocationStoreSubscriber implements EventSubscriberInterface
{
    private const ENTITY = 'custom_entity_odstupenie';

    public function __construct(
        private readonly DefinitionInstanceRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly IbanCipher $cipher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [RevocationRequestEvent::EVENT_NAME => ['store', 100]];
    }

    public function store(RevocationRequestEvent $event): void
    {
        try {
            if (!$this->registry->has(self::ENTITY)) {
                return;
            }
            $d = $event->getRevocationRequestFormData();
            $when = $d['submitTime'] ?? new \DateTimeImmutable();
            $this->registry->getRepository(self::ENTITY)->create([[
                'id' => Uuid::randomHex(),
                'meno' => (string) ($d['firstName'] ?? ''),
                'priezvisko' => (string) ($d['lastName'] ?? ''),
                'email' => (string) ($d['email'] ?? ''),
                'objednavka' => (string) ($d['contractNumber'] ?? '-'),
                'iban' => $this->cipher->encrypt((string) ($d['iban'] ?? '')),
                'poznamka' => (string) ($d['comment'] ?? ''),
                'odoslane' => $when instanceof \DateTimeInterface ? $when : new \DateTimeImmutable(),
            ]], $event->getContext());
        } catch (\Throwable $e) {
            // ulozenie nesmie zablokovat odoslanie e-mailu
            $this->logger->error('Brunko odstupenie: ulozenie zlyhalo: ' . $e->getMessage());
        }
    }
}

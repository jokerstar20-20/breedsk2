<?php declare(strict_types=1);

namespace Brunko\Checkout\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ContactForm\Event\ContactFormEvent;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Kazdu spravu z kontaktneho formulara ulozi do tabulky (app BrunkoKontakt), viditelnej v administracii. E-mail sa posiela bez zmeny. */
class ContactStoreSubscriber implements EventSubscriberInterface
{
    private const ENTITY = 'custom_entity_kontakt';

    public function __construct(
        private readonly DefinitionInstanceRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ContactFormEvent::EVENT_NAME => ['store', 100]];
    }

    public function store(ContactFormEvent $event): void
    {
        try {
            if (!$this->registry->has(self::ENTITY)) {
                return;
            }
            $d = $event->getContactFormData();
            $this->registry->getRepository(self::ENTITY)->create([[
                'id' => Uuid::randomHex(),
                'meno' => (string) ($d['firstName'] ?? ''),
                'priezvisko' => (string) ($d['lastName'] ?? ''),
                'email' => (string) ($d['email'] ?? ''),
                'telefon' => (string) ($d['phone'] ?? ''),
                'predmet' => (string) ($d['subject'] ?? '-'),
                'sprava' => (string) ($d['comment'] ?? ''),
                'odoslane' => new \DateTimeImmutable(),
                'vybavene' => false,
            ]], $event->getContext());
        } catch (\Throwable $e) {
            $this->logger->error('Brunko kontakt: ulozenie zlyhalo: ' . $e->getMessage());
        }
    }
}

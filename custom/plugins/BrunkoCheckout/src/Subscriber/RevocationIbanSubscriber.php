<?php declare(strict_types=1);

namespace Brunko\Checkout\Subscriber;

use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular odstupenia od zmluvy: povinny platny IBAN (kam vratime peniaze). */
class RevocationIbanSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'framework.validation.revocation_request_form.create' => 'addIban',
            'framework.validation.revocation_request_form.update' => 'addIban',
        ];
    }

    public function addIban(BuildValidationEvent $event): void
    {
        $event->getDefinition()->add('iban', new NotBlank(), new Iban());
    }
}

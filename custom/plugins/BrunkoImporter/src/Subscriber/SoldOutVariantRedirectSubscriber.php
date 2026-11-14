<?php declare(strict_types=1);

namespace Brunko\Importer\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Vypredany (neaktivny) variant uz nema stranku, jeho adresa by vratila 404.
 * Namiesto toho sa trvalo (301) presmeruje na dostupny variant toho isteho produktu
 * (prednostne na hlavny variant, ktory nastavuje importer).
 */
class SoldOutVariantRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface || $exception->getStatusCode() !== 404) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('GET')) {
            return;
        }

        $productId = $this->productIdFromRequest($request);
        if ($productId === null) {
            return;
        }

        $targetId = $this->findAvailableSibling($productId);
        if ($targetId === null) {
            return;
        }

        $path = $this->canonicalPath($targetId);
        if ($path === null) {
            return;
        }

        $base = (string) $request->attributes->get(RequestTransformer::SALES_CHANNEL_ABSOLUTE_BASE_URL, '')
            . (string) $request->attributes->get(RequestTransformer::SALES_CHANNEL_BASE_URL, '');
        if ($base === '') {
            $base = $request->getSchemeAndHttpHost();
        }

        $event->setResponse(new RedirectResponse(rtrim($base, '/') . '/' . ltrim($path, '/'), 301));
    }

    /** ID produktu z route (/detail/{productId}), alebo z SEO adresy, ak sa smerovanie k produktu nepodarilo. */
    private function productIdFromRequest(Request $request): ?string
    {
        if ($request->attributes->get('_route') === 'frontend.detail.page') {
            $id = $request->attributes->get('productId');

            return is_string($id) && Uuid::isValid($id) ? $id : null;
        }

        $path = ltrim($request->getPathInfo(), '/');
        if ($path === '') {
            return null;
        }
        $id = $this->db->fetchOne(
            "SELECT LOWER(HEX(foreign_key)) FROM seo_url WHERE route_name = 'frontend.detail.page' AND seo_path_info = ? LIMIT 1",
            [$path]
        );

        return $id ?: null;
    }

    /** Variant (ma parenta) -> dostupny surodenec: najprv hlavny variant z variantListingConfig, potom ktorykolvek dostupny. */
    private function findAvailableSibling(string $productId): ?string
    {
        $parentId = $this->db->fetchOne(
            'SELECT LOWER(HEX(parent_id)) FROM product WHERE id = UNHEX(?) AND version_id = UNHEX(?) AND parent_id IS NOT NULL',
            [$productId, Defaults::LIVE_VERSION]
        );
        if (!$parentId) {
            return null;
        }

        $main = $this->db->fetchOne(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(variant_listing_config, '$.mainVariantId')) FROM product WHERE id = UNHEX(?) AND version_id = UNHEX(?)",
            [$parentId, Defaults::LIVE_VERSION]
        );

        $candidates = $this->db->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM product
             WHERE parent_id = UNHEX(?) AND version_id = UNHEX(?) AND active = 1 AND available = 1 AND id <> UNHEX(?)
             ORDER BY product_number',
            [$parentId, Defaults::LIVE_VERSION, $productId]
        );
        if (!$candidates) {
            return null;
        }

        return is_string($main) && in_array($main, $candidates, true) ? $main : $candidates[0];
    }

    private function canonicalPath(string $productId): ?string
    {
        $path = $this->db->fetchOne(
            "SELECT seo_path_info FROM seo_url
             WHERE route_name = 'frontend.detail.page' AND foreign_key = UNHEX(?) AND is_canonical = 1 AND is_deleted = 0
             LIMIT 1",
            [$productId]
        );

        return $path ?: null;
    }
}

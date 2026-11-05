<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\I18n\Translator;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use App\Repository\PageRepository;
use App\Security\HtmlSanitizer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Content pages: About, Info, Support, Terms (templates/shop/pages). */
final class PageController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PaymentRegistry $payments,
        private readonly PageRepository $pages,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Translator $translator,
    ) {
    }

    /** /p/{slug}: a page written in Admin → Pages. */
    public function custom(ServerRequestInterface $request, string $slug): ResponseInterface
    {
        return $this->cms($request, $slug) ?? throw HttpException::notFound('Sorry, that page could not be found.');
    }

    /** The page's own text from Admin → Pages when there is one (published, in this language or all), else null. */
    private function cms(ServerRequestInterface $request, string $slug): ?ResponseInterface
    {
        try {
            $page = $this->pages->findPublished($slug, $this->translator->locale());
        } catch (\PDOException) { // store not migrated yet
            return null;
        }
        if ($page === null) {
            return null;
        }
        $page['body'] = $this->sanitizer->cleanPage((string) $page['body']);

        return $this->responder->view($request, 'shop/pages/cms.html.twig', ['page' => $page]);
    }

    public function about(ServerRequestInterface $request): ResponseInterface
    {
        if (($page = $this->cms($request, 'about')) !== null) {
            return $page;
        }
        return $this->responder->view($request, 'shop/pages/about.html.twig');
    }

    public function info(ServerRequestInterface $request): ResponseInterface
    {
        if (($page = $this->cms($request, 'info')) !== null) {
            return $page;
        }
        return $this->responder->view($request, 'shop/pages/info.html.twig', [
            'payment_methods' => array_map(
                static fn (PaymentMethod $m) => ['label' => $m->label(), 'description' => $m->description()],
                array_values($this->payments->enabled())
            ),
        ]);
    }

    public function support(ServerRequestInterface $request): ResponseInterface
    {
        if (($page = $this->cms($request, 'support')) !== null) {
            return $page;
        }
        return $this->responder->view($request, 'shop/pages/support.html.twig');
    }

    public function terms(ServerRequestInterface $request): ResponseInterface
    {
        if (($page = $this->cms($request, 'terms')) !== null) {
            return $page;
        }
        return $this->responder->view($request, 'shop/pages/terms.html.twig');
    }

    public function privacy(ServerRequestInterface $request): ResponseInterface
    {
        if (($page = $this->cms($request, 'privacy')) !== null) {
            return $page;
        }
        return $this->responder->view($request, 'shop/pages/privacy.html.twig');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\ShippingMethodRepository;
use App\Service\ShippingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin → Shipping: add, edit, enable/disable, reorder and delete shipping options. */
final class ShippingController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly ShippingMethodRepository $methods,
        private readonly ShippingService $shipping,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, (int) ($request->getQueryParams()['edit'] ?? 0));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $id = (int) ($body['id'] ?? 0);

        if ($action === 'create' || ($action === 'update' && $id > 0)) {
            [$data, $errors] = $this->shipping->validate($body);
            if ($errors !== []) {
                return $this->page($request, $action === 'update' ? $id : 0, $body, $errors, 422);
            }
            if ($action === 'create') {
                $this->methods->create($data);
                $this->session->flash('success', $this->translator->trans('Shipping method "{name}" added.', ['name' => $data['name']]));
            } else {
                $this->methods->update($id, $data);
                $this->session->flash('success', $this->translator->trans('Shipping method updated.'));
            }
        } elseif ($action === 'toggle' && ($method = $this->methods->find($id)) !== null) {
            $this->methods->setActive($id, !$method['is_active']);
            $this->session->flash('success', $this->translator->trans($method['is_active'] ? '"{name}" disabled.' : '"{name}" enabled.', ['name' => $method['name']]));
        } elseif ($action === 'move' && in_array($body['direction'] ?? '', ['up', 'down'], true)) {
            $this->methods->move($id, (string) $body['direction']);
        } elseif ($action === 'delete' && $id > 0) {
            $this->methods->delete($id);
            $this->session->flash('success', $this->translator->trans('Shipping method deleted. Past orders keep their shipping details.'));
        }

        return $this->responder->redirectToRoute('admin.shipping');
    }

    /**
     * @param array<string, mixed>|null $input  posted values to show again after a validation error
     * @param list<string>             $errors
     */
    private function page(ServerRequestInterface $request, int $editId, ?array $input = null, array $errors = [], int $status = 200): ResponseInterface
    {
        $methods = $this->methods->findAll();

        return $this->responder->view($request, 'admin/shipping.html.twig', [
            'methods'      => $methods,
            'active_count' => count(array_filter($methods, static fn (array $m) => (bool) $m['is_active'])),
            'edit_id'      => $editId,
            'input'        => $input,
            'errors'       => $errors,
        ], $status);
    }
}

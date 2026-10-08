<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\CouponRepository;
use App\Service\CouponService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin → Discounts: add, edit, activate/deactivate and delete discount codes.
 * A code that has been used on an order can't be deleted (orders keep a copy
 * of it) — deactivate it instead.
 */
final class DiscountController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CouponRepository $coupons,
        private readonly CouponService $service,
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
            [$data, $errors] = $this->service->validate($body, $action === 'update' ? $id : 0);
            if ($errors !== []) {
                return $this->page($request, $action === 'update' ? $id : 0, $body, $errors, 422);
            }
            if ($action === 'create') {
                $this->coupons->create($data);
                $this->session->flash('success', $this->translator->trans('Discount code {code} added.', ['code' => $data['code']]));
            } else {
                $existing = $this->coupons->find($id);
                if ($existing !== null && $existing['code'] !== $data['code'] && $this->coupons->orderCount((string) $existing['code']) > 0) {
                    return $this->page($request, $id, $body, [
                        $this->translator->trans('{code} has been used on orders, so its code can\'t be renamed. Deactivate it and add a new one.', ['code' => $existing['code']]),
                    ], 422);
                }
                $this->coupons->update($id, $data);
                $this->session->flash('success', $this->translator->trans('Discount code {code} updated.', ['code' => $data['code']]));
            }
        } elseif ($action === 'toggle' && ($coupon = $this->coupons->find($id)) !== null) {
            $this->coupons->setActive($id, !$coupon['is_active']);
            $this->session->flash('success', $this->translator->trans($coupon['is_active'] ? '{code} deactivated.' : '{code} activated.', ['code' => $coupon['code']]));
        } elseif ($action === 'delete' && ($coupon = $this->coupons->find($id)) !== null) {
            if ($this->coupons->orderCount((string) $coupon['code']) > 0) {
                $this->session->flash('error', $this->translator->trans('{code} has been used on orders and can\'t be deleted — deactivate it instead.', ['code' => $coupon['code']]));
            } else {
                $this->coupons->delete($id);
                $this->session->flash('success', $this->translator->trans('Discount code {code} deleted.', ['code' => $coupon['code']]));
            }
        }

        return $this->responder->redirectToRoute('admin.discounts');
    }

    /**
     * @param array<string, mixed>|null $input  posted values to show again after a validation error
     * @param list<string>             $errors
     */
    private function page(ServerRequestInterface $request, int $editId, ?array $input = null, array $errors = [], int $status = 200): ResponseInterface
    {
        $coupons = $this->coupons->findAll();
        $now = date('Y-m-d H:i:s');
        foreach ($coupons as &$coupon) {
            $coupon['summary'] = $this->service->describe($coupon);
            $coupon['state'] = match (true) {
                !(int) $coupon['is_active']                                  => 'inactive',
                $coupon['ends_at'] !== null && $now >= $coupon['ends_at']    => 'expired',
                $coupon['starts_at'] !== null && $now < $coupon['starts_at'] => 'scheduled',
                $coupon['usage_limit'] !== null
                    && (int) $coupon['used_count'] >= (int) $coupon['usage_limit'] => 'used up',
                default                                                      => 'active',
            };
        }
        unset($coupon);

        return $this->responder->view($request, 'admin/discounts.html.twig', [
            'coupons' => $coupons,
            'edit_id' => $editId,
            'input'   => $input,
            'errors'  => $errors,
        ], $status);
    }
}

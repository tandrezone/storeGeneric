<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use App\Repository\DashboardRepository;
use App\Repository\VariantRepository;
use App\Security\AdminAuthenticator;
use App\Security\AdminRole;
use App\Service\StoreSettings;
use App\Support\Money;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin landing page (/admin): sales KPIs, orders waiting for action, low
 * stock, best sellers, recent orders and a revenue chart. Staff see order
 * counts and stock but no money figures.
 */
final class DashboardController
{
    private const PERIODS = ['today' => 1, '7 days' => 7, '30 days' => 30];
    private const CHART_DAYS = 30;

    public function __construct(
        private readonly Responder $responder,
        private readonly DashboardRepository $dashboard,
        private readonly VariantRepository $variants,
        private readonly StoreSettings $store,
        private readonly PaymentRegistry $payments,
        private readonly AdminAuthenticator $auth,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $showRevenue = $this->auth->can(AdminRole::Manager);
        $threshold = $this->store->lowStockThreshold();

        $kpis = [];
        foreach (self::PERIODS as $label => $days) {
            $kpis[] = ['label' => $label, 'days' => $days] + $this->dashboard->totals($days);
        }

        $topProducts = $this->dashboard->topProducts(self::CHART_DAYS, 8);
        $currency = $this->store->currency();
        $daily = array_map(
            static fn (array $d) => $d + ['revenue_label' => Money::format($d['revenue'], $currency)],
            $this->dashboard->dailyRevenue(self::CHART_DAYS)
        );
        if (!$showRevenue) {
            // Not only hidden in the view: money figures never reach a staff page.
            $kpis = array_map(static fn (array $k) => ['revenue' => null, 'average' => null] + $k, $kpis);
            $topProducts = array_map(static fn (array $p) => ['revenue' => null] + $p, $topProducts);
            $daily = [];
        }

        return $this->responder->view($request, 'admin/dashboard.html.twig', [
            'show_revenue'    => $showRevenue,
            'kpis'            => $kpis,
            'daily'           => $daily,
            'chart_days'      => self::CHART_DAYS,
            'awaiting'        => $this->dashboard->awaitingCounts(),
            'awaiting_orders' => $this->dashboard->awaitingOrders(10),
            'low_stock'       => $this->variants->findLowStock($threshold, 10),
            'low_stock_count' => $this->variants->countLowStock($threshold),
            'threshold'       => $threshold,
            'top_products'    => $topProducts,
            'recent_orders'   => $this->dashboard->recentOrders(8),
            'payment_labels'  => array_map(static fn (PaymentMethod $m) => $m->label(), $this->payments->all()),
        ]);
    }
}

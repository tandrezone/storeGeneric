<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Repository\PageViewRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AnalyticsController
{
    private const RANGES = [7, 30, 90];

    public function __construct(
        private readonly Responder $responder,
        private readonly PageViewRepository $pageViews,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $days = (int) ($request->getQueryParams()['days'] ?? 30);
        if (!in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        return $this->responder->view($request, 'admin/analytics.html.twig', [
            'ranges'       => self::RANGES,
            'days'         => $days,
            'summary'      => $this->pageViews->summary($days),
            'daily'        => $this->pageViews->dailyVisits($days),
            'top_products' => $this->pageViews->topProducts($days, 10),
            'top_paths'    => $this->pageViews->topPaths($days, 8),
        ]);
    }
}

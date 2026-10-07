<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\Repository\CategoryRepository;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CategoryController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->view($request, 'admin/categories.html.twig', [
            'categories' => $this->categories->findAllWithProductCounts(),
        ]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $id = (int) ($body['id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));

        switch ((string) ($body['action'] ?? '')) {
            case 'create':
                if ($name === '') {
                    $this->session->flash('error', 'Please provide a name.');
                    break;
                }
                $this->categories->create($name);
                $this->session->flash('success', "Category \"{$name}\" added.");
                break;

            case 'update':
                if ($id <= 0 || $name === '') {
                    $this->session->flash('error', 'Please provide a name.');
                    break;
                }
                $this->categories->rename($id, $name);
                $this->session->flash('success', 'Category updated.');
                break;

            case 'delete':
                try {
                    $this->categories->delete($id);
                    $this->session->flash('success', 'Category deleted.');
                } catch (PDOException) {
                    $this->session->flash('error', 'Could not delete: it still has products assigned to it.');
                }
                break;
        }

        return $this->responder->redirectToRoute('admin.categories');
    }
}

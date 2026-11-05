<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\PageRepository;
use App\Security\HtmlSanitizer;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin → Pages: edit the built-in pages (About, Info, Support, Terms, Privacy) or add your own at /p/<slug>.
 * Deleting a built-in page's row brings back its built-in text.
 */
final class PageController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly PageRepository $pages,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $edit = isset($query['id']) ? $this->pages->find((int) $query['id']) : null;
        $new = $edit === null && isset($query['slug']) ? ['slug' => (string) $query['slug'], 'locale' => (string) ($query['locale'] ?? ''), 'title' => '', 'body' => '', 'is_published' => 1, 'in_footer' => 0, 'sort_order' => 0] : null;

        return $this->page($request, $edit ?? $new);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $id = (int) ($body['id'] ?? 0);

        if ($action === 'delete' && ($page = $this->pages->find($id)) !== null) {
            $this->pages->delete($id);
            $this->session->flash('success', $this->translator->trans(in_array($page['slug'], PageRepository::BUILT_IN, true) ? 'Page removed — the built-in text is back.' : 'Page deleted.'));

            return $this->responder->redirectToRoute('admin.pages');
        }
        if ($action !== 'save') {
            return $this->responder->redirectToRoute('admin.pages');
        }

        $existing = $id > 0 ? $this->pages->find($id) : null;
        $locale = $existing !== null ? (string) $existing['locale'] : $this->locale((string) ($body['locale'] ?? ''));
        $slug = $existing !== null ? (string) $existing['slug'] : mb_strtolower(trim((string) ($body['slug'] ?? '')));
        $title = mb_substr(trim((string) ($body['title'] ?? '')), 0, 180);
        $html = $this->sanitizer->cleanPage((string) ($body['body'] ?? ''));

        $errors = [];
        if ($existing === null && (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80)) {
            $errors[] = $this->translator->trans('The address can only use lowercase letters, numbers and hyphens.');
        }
        if ($title === '') {
            $errors[] = $this->translator->trans('A title is required.');
        }
        if (trim(strip_tags($html)) === '') {
            $errors[] = $this->translator->trans('The page needs some content.');
        }
        $input = ['id' => $id, 'slug' => $slug, 'locale' => $locale, 'title' => $title, 'body' => $html, 'is_published' => !empty($body['is_published']) ? 1 : 0, 'in_footer' => !empty($body['in_footer']) ? 1 : 0, 'sort_order' => (int) ($body['sort_order'] ?? 0)];
        if ($errors !== []) {
            return $this->page($request, $input, $errors, 422);
        }

        try {
            if ($existing !== null) {
                $this->pages->update($id, array_diff_key($input, ['id' => 1, 'slug' => 1, 'locale' => 1]));
            } else {
                $this->pages->create(array_diff_key($input, ['id' => 1]));
            }
        } catch (PDOException) {
            return $this->page($request, $input, [$this->translator->trans('A page with that address already exists for that language.')], 422);
        }
        $this->session->flash('success', $this->translator->trans('Page saved.'));

        return $this->responder->redirectToRoute('admin.pages');
    }

    private function locale(string $value): string
    {
        return $value === '' ? '' : ($this->translator->normalize($value) ?? '');
    }

    /** @param array<string, mixed>|null $editing @param list<string> $errors */
    private function page(ServerRequestInterface $request, ?array $editing, array $errors = [], int $status = 200): ResponseInterface
    {
        $rows = $this->pages->all_();
        $have = [];
        foreach ($rows as $row) {
            $have[$row['slug'] . '|' . $row['locale']] = true;
        }

        return $this->responder->view($request, 'admin/pages.html.twig', [
            'pages'     => $rows,
            'built_in'  => PageRepository::BUILT_IN,
            'have'      => $have,
            'languages' => $this->translator->languages(),
            'editing'   => $editing,
            'errors'    => $errors,
        ], $status);
    }
}

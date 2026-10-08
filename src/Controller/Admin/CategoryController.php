<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\CategoryRepository;
use App\Service\ImageUploader;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Admin → Categories: create, rename, describe, set an image, delete. */
final class CategoryController
{
    public const IMAGE_DIR = 'assets/images/categories';
    private const MAX_NAME = 100;
    private const MAX_DESCRIPTION = 5000;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CategoryRepository $categories,
        private readonly ImageUploader $uploader,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $id = (int) ($body['id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $image = $request->getUploadedFiles()['image'] ?? null;
        $editId = null;

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'create':
                    $this->validate($name, $description);
                    // Store the image first, so a rejected upload creates nothing.
                    $imagePath = $this->hasUpload($image) ? $this->uploader->store($image, self::IMAGE_DIR, 'category') : null;
                    $newId = $this->categories->create($name, $description !== '' ? $description : null);
                    if ($imagePath !== null) {
                        $this->categories->updateDetails($newId, $name, $description !== '' ? $description : null, $imagePath);
                    }
                    $this->session->flash('success', $this->translator->trans('Category "{name}" added.', ['name' => $name]));
                    break;

                case 'update':
                    $category = $id > 0 ? $this->categories->findById($id) : null;
                    if ($category === null) {
                        throw new RuntimeException($this->translator->trans('Category not found.'));
                    }
                    $editId = $id;
                    $this->validate($name, $description);
                    $this->update($category, $name, $description, $image, !empty($body['remove_image']));
                    $this->session->flash('success', $this->translator->trans('Category updated.'));
                    break;

                case 'delete':
                    $category = $this->categories->findById($id);
                    try {
                        $this->categories->delete($id);
                        if ($category !== null && $category['image_path'] !== null) {
                            $this->uploader->delete((string) $category['image_path'], self::IMAGE_DIR);
                        }
                        $this->session->flash('success', $this->translator->trans('Category deleted.'));
                    } catch (PDOException) {
                        $this->session->flash('error', $this->translator->trans('Could not delete: it still has products assigned to it.'));
                    }
                    break;
            }
        } catch (RuntimeException $e) {
            return $this->page($request, [$e->getMessage()], 422, $editId !== null ? ['id' => $editId] + $body : $body);
        }

        return $this->responder->redirectToRoute('admin.categories');
    }

    /**
     * Saves the new details; a new image replaces (and deletes) the old one.
     *
     * @param array<string, mixed> $category
     */
    private function update(array $category, string $name, string $description, mixed $image, bool $removeImage): void
    {
        $oldImage = $category['image_path'] !== null ? (string) $category['image_path'] : null;
        $imagePath = $removeImage ? null : $oldImage;
        if ($this->hasUpload($image)) {
            $imagePath = $this->uploader->store($image, self::IMAGE_DIR, 'category-' . (int) $category['id']);
        }

        try {
            $this->categories->updateDetails((int) $category['id'], $name, $description !== '' ? $description : null, $imagePath);
        } catch (PDOException $e) {
            if ($imagePath !== null && $imagePath !== $oldImage) {
                $this->uploader->delete($imagePath, self::IMAGE_DIR);
            }
            $this->logger->warning('Category update failed', ['exception' => $e]);
            throw new RuntimeException($this->translator->trans('Could not save the category because of a database error. Please try again.'));
        }

        if ($oldImage !== null && $oldImage !== $imagePath) {
            $this->uploader->delete($oldImage, self::IMAGE_DIR);
        }
    }

    private function validate(string $name, string $description): void
    {
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new RuntimeException($this->translator->trans('Please provide a name (max {max} characters).', ['max' => self::MAX_NAME]));
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new RuntimeException($this->translator->trans('The description can be at most {max} characters.', ['max' => self::MAX_DESCRIPTION]));
        }
    }

    /** @phpstan-assert-if-true UploadedFileInterface $file */
    private function hasUpload(mixed $file): bool
    {
        return $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $input posted values to show again
     */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200, ?array $input = null): ResponseInterface
    {
        return $this->responder->view($request, 'admin/categories.html.twig', [
            'categories' => $this->categories->findAllWithProductCounts(),
            'errors'     => $errors,
            'input'      => $input,
            'limits'     => ['name' => self::MAX_NAME, 'description' => self::MAX_DESCRIPTION],
        ], $status);
    }
}

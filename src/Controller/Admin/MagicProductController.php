<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Service\MagicProductCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Admin → Products → "Magic add product": name / core identifier, up to 3 photos
 * and raw notes in; a complete product with variants out, saved as "created"
 * and opened for review (see MagicProductCreator).
 */
final class MagicProductController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly MagicProductCreator $creator,
        private readonly Translator $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles()['photos'] ?? [];
        $files = is_array($files) ? array_values($files) : [$files];

        try {
            $input = $this->creator->prepare((string) ($body['identifier'] ?? ''), (string) ($body['notes'] ?? ''), $files);
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->back();
        }

        // The AI call can take a while: don't hold the session lock meanwhile.
        $this->session->release();
        try {
            $data = $this->creator->generate($input);
        } catch (Throwable $e) {
            $this->session->resume();
            $this->session->flash('error', $this->translator->trans('Magic add failed: {reason}', ['reason' => $e->getMessage()]));

            return $this->back();
        }
        $this->session->resume();

        try {
            $result = $this->creator->save($data, $input['uploads']);
        } catch (Throwable $e) {
            $this->logger->warning('Magic add: could not save the product', ['exception' => $e]);
            $this->session->flash('error', $this->translator->trans('Could not create the product. Please try again.'));

            return $this->back();
        }

        $message = $this->translator->transPlural('Product "{name}" created with {count} variant — review it, then approve it to show it on the storefront.', $result['variants'], ['name' => $result['name']]);
        if ($result['warnings'] !== []) {
            $message .= ' ' . implode(' ', $result['warnings']);
        }
        $this->session->flash($result['warnings'] !== [] ? 'error' : 'success', $message);

        return $this->responder->redirectToRoute('admin.products', [], ['edit' => $result['id'], 'status' => 'created']);
    }

    private function back(): ResponseInterface
    {
        return $this->responder->redirectToRoute('admin.products', [], ['magic' => 1]);
    }
}

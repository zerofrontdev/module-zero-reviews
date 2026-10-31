<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Plugin;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\CsrfValidator;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Review\Controller\Product\Post;
use Zero\Reviews\Model\Photos;

/**
 * A review posted with more photos than PHP takes in one request
 * (post_max_size) arrives with no fields at all, not even the form key, so
 * Magento's CSRF check refuses it with "Invalid Form Key" before the
 * controller runs. Say what happened instead, and go back to the product.
 * (The form key cannot go into the action URL: the product page is cached
 * and would hand one visitor's key to everybody.)
 */
class OversizedPost
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly RedirectInterface $redirect,
        private readonly Photos $photos
    ) {
    }

    /**
     * @param CsrfValidator $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @param ActionInterface $action
     * @return void
     * @throws InvalidRequestException
     */
    public function aroundValidate(
        CsrfValidator $subject,
        callable $proceed,
        RequestInterface $request,
        ActionInterface $action
    ): void {
        if ($action instanceof Post
            && $request instanceof Http
            && $request->isPost()
            && !$request->getPostValue()
            && (int) $request->getServer('CONTENT_LENGTH') > 0
        ) {
            throw new InvalidRequestException(
                $this->redirectFactory->create()->setUrl($this->redirect->getRefererUrl()),
                [__(
                    'The photos are too large together (at most %1 MB per review). Choose fewer or smaller ones.',
                    $this->photos->requestLimitMegabytes()
                )]
            );
        }
        $proceed($request, $action);
    }
}

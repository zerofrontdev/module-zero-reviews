<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Test\Unit\Plugin;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\CsrfValidator;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Filesystem;
use Magento\Review\Controller\Product\Post;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Zero\Reviews\Model\Photos;
use Zero\Reviews\Plugin\OversizedPost;

/**
 * A review POST larger than post_max_size reaches Magento with no fields, so the CSRF check would say
 * "Invalid Form Key". The plugin replaces that with the photo-size message and a redirect back; every other
 * request goes through Magento's validator untouched.
 */
class OversizedPostTest extends TestCase
{
    private const REFERER = 'https://shop.test/forge/bottle.html';

    private RedirectFactory&MockObject $redirectFactory;
    private Redirect&MockObject $redirectResult;
    private Photos $photos;
    private OversizedPost $plugin;
    private CsrfValidator&Stub $subject;

    protected function setUp(): void
    {
        // Mocks: every test states what it expects of the redirect (made once, or never).
        $this->redirectResult = $this->createMock(Redirect::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $redirect = $this->createStub(RedirectInterface::class);
        $redirect->method('getRefererUrl')->willReturn(self::REFERER);
        // Photos is a plain class; its request limit comes from php.ini, its collaborators are not used here.
        $this->photos = new Photos(
            $this->createStub(ResourceConnection::class),
            $this->createStub(Filesystem::class),
            $this->createStub(StoreManagerInterface::class)
        );
        $this->plugin = new OversizedPost($this->redirectFactory, $redirect, $this->photos);
        $this->subject = $this->createStub(CsrfValidator::class);
    }

    public function testAnOversizedReviewPostIsRefusedWithThePhotoMessageAndARedirectBack(): void
    {
        $this->redirectFactory->expects(self::once())->method('create')->willReturn($this->redirectResult);
        $this->redirectResult->expects(self::once())->method('setUrl')->with(self::REFERER)->willReturnSelf();
        $proceeded = false;
        $proceed = static function () use (&$proceeded): void {
            $proceeded = true;
        };

        try {
            $this->plugin->aroundValidate(
                $this->subject,
                $proceed,
                $this->request(true, [], '9000000'),
                $this->createStub(Post::class)
            );
            self::fail('An oversized review post must be refused.');
        } catch (InvalidRequestException $e) {
            self::assertSame($this->redirectResult, $e->getReplaceResult());
            $messages = $e->getMessages();
            self::assertCount(1, $messages);
            self::assertSame(
                sprintf(
                    'The photos are too large together (at most %d MB per review). Choose fewer or smaller ones.',
                    $this->photos->requestLimitMegabytes()
                ),
                (string) $messages[0]
            );
        }
        self::assertFalse($proceeded, "Magento's CSRF check must not run: it would add \"Invalid Form Key\".");
    }

    /**
     * Each case flips one of the four conditions (review Post action, POST, empty body, CONTENT_LENGTH > 0).
     *
     * @return array<string, array{bool, bool, array<string, mixed>, string|null}>
     */
    public static function otherRequests(): array
    {
        return [
            'another controller' => [false, true, [], '9000000'],
            'a GET request' => [true, false, [], '9000000'],
            'a post with fields (normal review)' => [true, true, ['form_key' => 'abc', 'nickname' => 'Ann'], '512'],
            'an empty post with no body' => [true, true, [], '0'],
            'an empty post without CONTENT_LENGTH' => [true, true, [], null],
        ];
    }

    /**
     * @param bool $reviewAction
     * @param bool $isPost
     * @param array<string, mixed> $post
     * @param string|null $contentLength
     */
    #[DataProvider('otherRequests')]
    public function testEveryOtherRequestGoesThroughMagentosValidator(
        bool $reviewAction,
        bool $isPost,
        array $post,
        ?string $contentLength
    ): void {
        $request = $this->request($isPost, $post, $contentLength);
        $action = $reviewAction ? $this->createStub(Post::class) : $this->createStub(ActionInterface::class);

        $this->assertProceeds($request, $action);
    }

    public function testARequestThatIsNotHttpGoesThroughMagentosValidator(): void
    {
        $this->assertProceeds($this->createStub(RequestInterface::class), $this->createStub(Post::class));
    }

    public function testMagentosRefusalStillComesThroughForANormalPost(): void
    {
        // A normal post with a wrong form key: Magento's own exception must reach the front controller unchanged.
        $this->redirectFactory->expects(self::never())->method('create');
        $this->redirectResult->expects(self::never())->method('setUrl');
        $refusal = new InvalidRequestException($this->redirectResult);
        $proceed = static function () use ($refusal): void {
            throw $refusal;
        };

        $this->expectExceptionObject($refusal);
        $this->plugin->aroundValidate(
            $this->subject,
            $proceed,
            $this->request(true, ['form_key' => 'wrong'], '300'),
            $this->createStub(Post::class)
        );
    }

    private function assertProceeds(RequestInterface $request, ActionInterface $action): void
    {
        $this->redirectFactory->expects(self::never())->method('create');
        $this->redirectResult->expects(self::never())->method('setUrl');
        $calls = [];
        $proceed = static function (RequestInterface $r, ActionInterface $a) use (&$calls): void {
            $calls[] = [$r, $a];
        };

        $this->plugin->aroundValidate($this->subject, $proceed, $request, $action);

        self::assertSame([[$request, $action]], $calls);
    }

    /**
     * @param bool $isPost
     * @param array<string, mixed> $post
     * @param string|null $contentLength
     * @return Http&Stub
     */
    private function request(bool $isPost, array $post, ?string $contentLength): Http&Stub
    {
        $request = $this->createStub(Http::class);
        $request->method('isPost')->willReturn($isPost);
        $request->method('getPostValue')->willReturn($post);
        $request->method('getServer')->willReturnCallback(
            static fn (?string $name = null, $default = null) => $name === 'CONTENT_LENGTH' ? $contentLength : $default
        );

        return $request;
    }
}

<?php

declare(strict_types=1);

namespace Setono\SyliusRedirectPlugin\EventListener;

use Doctrine\Common\Persistence\ObjectManager;
use Setono\SyliusRedirectPlugin\Model\NotFoundInterface;
use Setono\SyliusRedirectPlugin\Model\RedirectionPath;
use Setono\SyliusRedirectPlugin\Resolver\RedirectionPathResolverInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Webmozart\Assert\Assert;

final class NotFoundSubscriber implements EventSubscriberInterface
{
    /** @var ObjectManager */
    private $objectManager;

    /** @var ChannelContextInterface */
    private $channelContext;

    /** @var RedirectionPathResolverInterface */
    private $redirectionPathResolver;

    /** @var FactoryInterface */
    private $notFoundFactory;

    public function __construct(
        ObjectManager $objectManager,
        ChannelContextInterface $channelContext,
        RedirectionPathResolverInterface $redirectionPathResolver,
        FactoryInterface $notFoundFactory
    ) {
        $this->objectManager = $objectManager;
        $this->channelContext = $channelContext;
        $this->redirectionPathResolver = $redirectionPathResolver;
        $this->notFoundFactory = $notFoundFactory;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpException || $throwable->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            return;
        }

        $redirectionPath = $this->redirectionPathResolver->resolveFromRequest(
            $event->getRequest(), $this->channelContext->getChannel(), true
        );

        if ($redirectionPath->isEmpty()) {
            $this->log($event->getRequest());
        } else {
            $this->redirect($event, $redirectionPath);
        }

        $this->objectManager->flush();
    }

    private function log(Request $request): void
    {
        /** @var NotFoundInterface $notFound */
        $notFound = $this->notFoundFactory->createNew();
        $notFound->onRequest($request);

        $this->objectManager->persist($notFound);
    }

    private function redirect(ExceptionEvent $event, RedirectionPath $redirectionPath): void
    {
        $redirectionPath->markAsAccessed();

        $lastRedirect = $redirectionPath->last();
        Assert::notNull($lastRedirect);

        $event->setResponse(new RedirectResponse(
            $lastRedirect->getDestination(),
            $lastRedirect->isPermanent() ? Response::HTTP_MOVED_PERMANENTLY : Response::HTTP_FOUND
        ));
    }
}

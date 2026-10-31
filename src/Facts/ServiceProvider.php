<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Facts;

use Flarum\Discussion\Command\DeleteDiscussionHandler as NativeHandler;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Database\ConnectionInterface;

final class ServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(NativeHandler::class, fn ($handler, $container) =>
            new DeleteDiscussionHandler($handler, $container->make(ConnectionInterface::class)));
    }
}

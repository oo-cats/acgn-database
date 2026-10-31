<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Facts;

use Flarum\Discussion\Command\DeleteDiscussion;
use Flarum\Discussion\Command\DeleteDiscussionHandler as NativeHandler;
use Illuminate\Database\ConnectionInterface;

/** Include native FK-cascade association removal in the catalog transaction. */
final class DeleteDiscussionHandler extends NativeHandler
{
    public function __construct(private NativeHandler $inner, private ConnectionInterface $db) {}

    public function handle(DeleteDiscussion $command)
    {
        return (new FactsMutation($this->db))->run('acgn-catalog', 0, [], [(int) $command->discussionId], 'link.discussion-delete',
            fn () => $this->inner->handle($command));
    }
}

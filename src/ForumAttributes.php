<?php

namespace Local\AcgnDatabase;

use Flarum\Api\Serializer\ForumSerializer;

final class ForumAttributes
{
    public function __invoke(ForumSerializer $serializer): array
    {
        $actor = $serializer->getActor();
        $view = $actor->can('viewForum') && $actor->can('acgn.view');
        return [
            'acgnView' => $view,
            'acgnRequests' => $view && !$actor->isGuest(),
            'acgnCreate' => $view && !$actor->isGuest() && $actor->can('acgn.create'),
            'acgnUpdate' => $view && !$actor->isGuest() && $actor->can('acgn.update'),
            'acgnDelete' => $view && !$actor->isGuest() && $actor->can('acgn.delete'),
            'acgnApprove' => $view && !$actor->isGuest() && $actor->can('acgn.approve') && $actor->can('acgn.create'),
            'acgnCollect' => $view && !$actor->isGuest() && $actor->can('acgn.collect'),
            'acgnPersonalImport' => $view && !$actor->isGuest() && $actor->can('acgn.collect') && $actor->can('acgn.import_collection'),
            'acgnLink' => $view && $actor->can('acgn.link'),
        ];
    }
}

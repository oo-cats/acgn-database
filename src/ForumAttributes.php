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
            'acgnManage' => $view && $actor->can('acgn.manage'),
            'acgnImport' => $view && !$actor->isGuest() && $actor->can('acgn.import'),
            'acgnCollect' => $view && !$actor->isGuest() && $actor->can('acgn.collect'),
            'acgnPersonalImport' => $view && !$actor->isGuest() && $actor->can('acgn.collect') && $actor->can('acgn.import_collection'),
            'acgnLink' => $view && $actor->can('acgn.link'),
        ];
    }
}

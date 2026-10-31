<?php
namespace Local\AcgnDatabase\Api;

final class RequestSubjectSerializer extends \Flarum\Api\Serializer\AbstractSerializer
{
    protected $type = 'acgnImportRequests';
    protected function getDefaultAttributes($model) { return []; }
}

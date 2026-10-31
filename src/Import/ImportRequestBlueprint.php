<?php
namespace Local\AcgnDatabase\Import;

final class ImportRequestBlueprint implements \Flarum\Notification\Blueprint\BlueprintInterface
{
    public function getFromUser() { return null; }
    public function getSubject() { return null; }
    public function getData() { return []; }
    public static function getType() { return 'acgnImportRequest'; }
    public static function getSubjectModel() { return RequestSubject::class; }
}

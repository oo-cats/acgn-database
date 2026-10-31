<?php

use Flarum\Extend;
use Flarum\Api\Serializer\ForumSerializer;
use Local\AcgnDatabase\Api\Controller;
use Local\AcgnDatabase\Api\CollectionImportController;
use Local\AcgnDatabase\Api\ShowcaseController;
use Local\AcgnDatabase\ForumAttributes;
use Local\AcgnDatabase\Api\ImportRequestController;
use Local\AcgnDatabase\Import\{ApprovalWorkCommand, ApprovalSchedule, ImportRequestBlueprint};

$api = new Extend\Routes('api');
foreach (['subjects', 'people'] as $resource) {
    $prefix = 'local-acgn-database.'.$resource;
    $api->get('/acgn/'.$resource, $prefix.'.index', Controller::class)
        ->post('/acgn/'.$resource, $prefix.'.create', Controller::class)
        ->get('/acgn/'.$resource.'/{id:\d+}', $prefix.'.show', Controller::class)
        ->patch('/acgn/'.$resource.'/{id:\d+}', $prefix.'.update', Controller::class)
        ->put('/acgn/'.$resource.'/{id:\d+}/archive', $prefix.'.archive', Controller::class);
}
$api->put('/acgn/subjects/{id:\d+}/record', 'local-acgn-database.record.save', Controller::class)
    ->delete('/acgn/subjects/{id:\d+}/record', 'local-acgn-database.record.delete', Controller::class)
    ->post('/acgn/subjects/{id:\d+}/credits', 'local-acgn-database.credits.save', Controller::class)
    ->delete('/acgn/subjects/{id:\d+}/credits', 'local-acgn-database.credits.delete', Controller::class)
    ->put('/acgn/subjects/{id:\d+}/discussions/{discussion:\d+}', 'local-acgn-database.discussions.save', Controller::class)
    ->delete('/acgn/subjects/{id:\d+}/discussions/{discussion:\d+}', 'local-acgn-database.discussions.delete', Controller::class)
    ->get('/acgn/discussions/{discussion:\d+}/subjects', 'local-acgn-database.discussions.subjects', Controller::class)
    ->post('/acgn/import/bangumi/preview', 'local-acgn-database.import.preview', Controller::class)
    ->post('/acgn/import/bangumi/confirm', 'local-acgn-database.import.confirm', Controller::class);
$api->post('/acgn/collection-import/preview', 'local-acgn-database.collection.preview', CollectionImportController::class)
    ->post('/acgn/collection-import/confirm', 'local-acgn-database.collection.confirm', CollectionImportController::class)
    ->delete('/acgn/collection-import', 'local-acgn-database.collection.discard', CollectionImportController::class);
$api->get('/acgn/import-requests', 'local-acgn-database.requests.index', ImportRequestController::class)
    ->post('/acgn/import-requests/{id:\d+}/preview', 'local-acgn-database.requests.preview', ImportRequestController::class)
    ->post('/acgn/import-requests/{id:\d+}/decision', 'local-acgn-database.requests.decide', ImportRequestController::class)
    ->post('/acgn/import-applications/{id:\d+}/cancel', 'local-acgn-database.applications.cancel', ImportRequestController::class);
$api->get('/acgn/users/{user:\d+}/showcases/recent-watching', 'local-acgn-database.showcase.watching', ShowcaseController::class)
    ->get('/acgn/users/{user:\d+}/showcases/recent-ratings', 'local-acgn-database.showcase.ratings', ShowcaseController::class)
    ->get('/acgn/showcases/settings', 'local-acgn-database.showcase.settings', ShowcaseController::class)
    ->put('/acgn/showcases/settings', 'local-acgn-database.showcase.settings.save', ShowcaseController::class)
    ->patch('/acgn/subjects/{id:\d+}/record/sharing', 'local-acgn-database.showcase.sharing', ShowcaseController::class)
    ->post('/acgn/subjects/{id:\d+}/record/activity', 'local-acgn-database.showcase.activity', ShowcaseController::class);

return [
    $api,
    (new Extend\ServiceProvider())->register(\Local\AcgnDatabase\Facts\ServiceProvider::class),
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/resources/forum.less')
        ->route('/acgn', 'local-acgn-database.catalog')
        ->route('/acgn/subjects/{id:\d+}', 'local-acgn-database.subject')
        ->route('/acgn/people', 'local-acgn-database.people')
        ->route('/acgn/people/{id:\d+}', 'local-acgn-database.person')
        ->route('/acgn/import-requests', 'local-acgn-database.requests'),
    (new Extend\Console())->command(ApprovalWorkCommand::class)->schedule(ApprovalWorkCommand::class, ApprovalSchedule::class),
    (new Extend\Notification())->type(ImportRequestBlueprint::class, \Local\AcgnDatabase\Api\RequestSubjectSerializer::class, ['alert']),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js'),
    (new Extend\ApiSerializer(ForumSerializer::class))->attributes(ForumAttributes::class),
    new Extend\Locales(__DIR__.'/resources/locale'),
];

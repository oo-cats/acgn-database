<?php

declare(strict_types=1);

namespace Local\AcgnDatabase\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Local\AcgnDatabase\{Catalog, Input};
use Local\AcgnDatabase\Import\{Importer, ImportException};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class Controller implements RequestHandlerInterface
{
    public function __construct(private Catalog $catalog, private Importer $importer) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('viewForum');
        $actor->assertCan('acgn.view');
        $params = $request->getQueryParams();
        $data = $request->getParsedBody() ?? [];
        if (!is_array($data)) Input::fail('body', '请求体必须为 JSON 对象。');
        $route = str_replace('local-acgn-database.', '', (string) $request->getAttribute('routeName'));
        $people = str_starts_with($route, 'people.');
        $id = isset($params['id']) ? Input::integer($params['id'], 'id', 1) : null;
        $method = $request->getMethod();
        try {
            $result = match ($route) {
                'subjects.index', 'people.index' => $this->catalog->listing($params, $actor, $people),
                'subjects.create', 'people.create' => $this->catalog->save($data, $actor, null, $people),
                'subjects.show', 'people.show' => $this->catalog->detail($id, $actor, $people),
                'subjects.update', 'people.update' => $this->catalog->save($data, $actor, $id, $people),
                'subjects.archive', 'people.archive' => $this->catalog->archive($id, $data, $actor, $people),
                'record.save', 'record.delete' => $this->catalog->record($id, $data, $actor, $method === 'DELETE'),
                'credits.save', 'credits.delete' => $this->catalog->credit($id, $data, $actor, $method === 'DELETE'),
                'discussions.save', 'discussions.delete' => $this->catalog->link($id, Input::integer($params['discussion'], 'discussion', 1), $actor, $method === 'DELETE'),
                'discussions.subjects' => $this->catalog->discussionSubjects(Input::integer($params['discussion'], 'discussion', 1), $actor),
                'import.preview' => $this->importer->preview($data, $actor),
                'import.confirm' => $this->importer->confirm($data, $actor),
                default => throw new \LogicException('Unknown ACGN API route.'),
            };
            return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
        } catch (ImportException $error) {
            return new JsonResponse(['errors' => [['status' => '502', 'detail' => $error->getMessage()]]], 502, ['Cache-Control' => 'private, no-store']);
        }
    }
}

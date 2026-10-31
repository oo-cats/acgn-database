<?php

namespace Local\AcgnDatabase\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\AcgnDatabase\Input;
use Local\AcgnDatabase\Import\{CollectionImporter, ImportException};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class CollectionImportController implements RequestHandlerInterface
{
    public function __construct(private CollectionImporter $importer) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = $request->getParsedBody() ?? [];
        if (!is_array($data) || ($data !== [] && array_is_list($data))) Input::fail('body', '请求体必须为 JSON 对象。');
        $actor = RequestUtil::getActor($request);
        try {
            $result = match ($request->getAttribute('routeName')) {
                'local-acgn-database.collection.preview' => $this->importer->preview($data, $actor),
                'local-acgn-database.collection.confirm' => $this->importer->confirm($data, $actor),
                'local-acgn-database.collection.discard' => $this->importer->discard($data, $actor),
                default => throw new \LogicException('Unknown personal import route.'),
            };
            return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
        } catch (ImportException $error) {
            return new JsonResponse(['errors' => [['status' => '502', 'detail' => $error->getMessage()]]], 502, ['Cache-Control' => 'private, no-store']);
        }
    }
}

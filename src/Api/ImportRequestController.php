<?php
namespace Local\AcgnDatabase\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\AcgnDatabase\Input;
use Local\AcgnDatabase\Import\{ImportRequests, ImportException};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class ImportRequestController implements RequestHandlerInterface
{
    public function __construct(private ImportRequests $requests) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request); $params = $request->getQueryParams(); $data = $request->getParsedBody() ?? [];
        if (!is_array($data) || ($data !== [] && array_is_list($data))) Input::fail('body', '请求体必须为 JSON 对象。');
        $id = isset($params['id']) ? Input::integer($params['id'], 'id', 1) : null;
        try {
            $result = match ($request->getAttribute('routeName')) {
                'local-acgn-database.requests.index' => $this->requests->listing($params, $actor),
                'local-acgn-database.requests.preview' => $this->requests->preview($id, $data, $actor),
                'local-acgn-database.requests.decide' => $this->requests->decide($id, $data, $actor),
                'local-acgn-database.applications.cancel' => $this->requests->cancel($id, $actor),
                default => throw new \LogicException('Unknown import request route.'),
            };
            return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
        } catch (ImportException $error) {
            return new JsonResponse(['errors' => [['status' => '502', 'detail' => $error->getMessage()]]], 502, ['Cache-Control' => 'private, no-store']);
        }
    }
}

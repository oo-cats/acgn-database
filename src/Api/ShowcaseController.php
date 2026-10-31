<?php

namespace Local\AcgnDatabase\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\AcgnDatabase\Input;
use Local\AcgnDatabase\Profile\RecordShowcase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class ShowcaseController implements RequestHandlerInterface
{
    public function __construct(private RecordShowcase $showcases) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $route = $request->getAttribute('routeName');
        $params = $request->getQueryParams();
        $body = $request->getParsedBody() ?? [];
        if (!is_array($body) || ($body !== [] && array_is_list($body))) Input::fail('body', '请求体必须为 JSON 对象。');
        if ($route === 'local-acgn-database.showcase.watching' || $route === 'local-acgn-database.showcase.ratings') {
            if (array_diff(array_keys($params), ['user', 'limit', 'with_progress'])) Input::fail('query', '不支持此查询参数。');
            $progress = $params['with_progress'] ?? '0';
            if (!in_array($progress, ['0', '1'], true)) Input::fail('with_progress', '请使用 0 或 1。');
            $result = $this->showcases->forViewer($actor, Input::integer($params['user'] ?? null, 'user', 1),
                $route === 'local-acgn-database.showcase.watching' ? 'watching' : 'ratings', Input::integer($params['limit'] ?? 6, 'limit', 1, 6), $progress === '1');
        } elseif (in_array($route, ['local-acgn-database.showcase.sharing', 'local-acgn-database.showcase.activity'], true)) {
            if (array_diff(array_keys($params), ['id'])) Input::fail('query', '不支持此查询参数。');
            $id = Input::integer($params['id'] ?? null, 'id', 1);
            $result = $route === 'local-acgn-database.showcase.sharing' ? $this->showcases->shareRecord($id, $body, $actor)
                : $this->showcases->recordActivity($id, $body, $actor);
        } else {
            if ($params) Input::fail('query', '此接口不接受查询参数。');
            $result = match ($route) {
                'local-acgn-database.showcase.settings' => $this->showcases->settings($actor),
                'local-acgn-database.showcase.settings.save' => $this->showcases->saveSettings($body, $actor),
                default => throw new \LogicException('Unknown showcase route.'),
            };
        }
        return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
    }
}

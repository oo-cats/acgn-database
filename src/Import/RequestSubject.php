<?php
namespace Local\AcgnDatabase\Import;

use Flarum\Database\{AbstractModel, ScopeVisibilityTrait};
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** Public request identity for native notification grouping; never a private application. */
final class RequestSubject extends AbstractModel
{
    use ScopeVisibilityTrait;
    protected $table = 'acgn_import_requests';

    public function scopeWhereVisibleTo(Builder $query, User $actor, string $ability = 'view')
    {
        if ($actor->isGuest() || !$actor->can('viewForum') || !$actor->can('acgn.view')) return $query->whereRaw('1=0');
        if ($actor->can('acgn.approve') && $actor->can('acgn.create')) return $query;
        return $query->whereExists(function ($applications) use ($actor): void {
            $applications->selectRaw('1')->from('acgn_import_applications')->whereColumn('request_id', 'acgn_import_requests.id')->where('user_id', $actor->id);
        });
    }
}

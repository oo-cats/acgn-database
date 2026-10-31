<?php

use Illuminate\Database\Schema\{Builder, Blueprint};

return [
    'up' => function (Builder $schema): void {
        $db = $schema->getConnection();
        // Retain legacy grants for rollback, but production no longer authorizes them.
        foreach ($db->table('group_permission')->whereIn('permission', ['acgn.manage', 'acgn.import'])->get() as $grant) {
            foreach ($grant->permission === 'acgn.manage' ? ['create', 'update', 'delete'] : ['create'] as $action) {
                $key = ['group_id' => $grant->group_id, 'permission' => 'acgn.'.$action];
                if (!$db->table('group_permission')->where($key)->exists()) $db->table('group_permission')->insert($key);
            }
        }
        // Retired nullable column remains for schema rollback compatibility only.
        $db->table('acgn_subjects')->whereNotNull('source_score')->update(['source_score' => null]);
        $db->table('acgn_imports')->delete(); // Invalidate old previews containing aggregate ratings.
        $schema->create('acgn_import_requests', function (Blueprint $t): void {
            $t->increments('id'); $t->unsignedInteger('source_id')->unique(); $t->string('title');
            $t->string('status', 16)->default('pending'); $t->unsignedInteger('subject_id')->nullable();
            $t->unsignedInteger('revision')->default(0); $t->unsignedInteger('generation')->default(0); $t->unsignedInteger('notify_after')->default(0);
            $t->boolean('notify_done')->default(false); $t->unsignedInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable(); $t->timestamps();
            $t->index(['status', 'id']); $t->index(['notify_done', 'status', 'id']);
            $t->foreign('subject_id')->references('id')->on('acgn_subjects')->onDelete('set null');
            $t->foreign('decided_by')->references('id')->on('users')->onDelete('set null');
        });
        $schema->create('acgn_import_applications', function (Blueprint $t): void {
            $t->increments('id'); $t->unsignedInteger('request_id'); $t->unsignedInteger('user_id');
            $t->mediumText('payload')->nullable(); $t->string('status', 16)->default('pending'); $t->unsignedInteger('generation')->default(0);
            $t->timestamp('expires_at'); $t->timestamp('notified_at')->nullable(); $t->timestamps();
            $t->unique(['request_id', 'user_id']); $t->index(['status', 'id']);
            $t->index(['user_id', 'id']); $t->index(['user_id', 'status', 'expires_at']); $t->index(['request_id', 'status', 'id']); $t->index(['notified_at', 'status', 'id']);
            $t->foreign('request_id')->references('id')->on('acgn_import_requests')->onDelete('cascade');
            $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema): void {
        if ($schema->hasTable('notifications')) $schema->getConnection()->table('notifications')->where('type', 'acgnImportRequest')->delete();
        $schema->dropIfExists('acgn_import_applications'); $schema->dropIfExists('acgn_import_requests');
        $schema->getConnection()->table('group_permission')->whereIn('permission', ['acgn.create', 'acgn.update', 'acgn.delete', 'acgn.approve'])->delete();
    },
];

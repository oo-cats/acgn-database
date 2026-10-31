<?php

use Illuminate\Database\Schema\{Builder, Blueprint};

return [
    'up' => function (Builder $schema): void {
        $schema->table('acgn_records', function (Blueprint $table): void {
            $table->string('share_visibility', 16)->default('self');
            $table->boolean('share_score')->default(false);
            $table->boolean('share_progress')->default(false);
            // No historical backfill: an edit/import timestamp is not watch/rating activity.
            $table->timestamp('watch_updated_at', 6)->nullable();
            $table->timestamp('rated_at', 6)->nullable();
            $table->index(['user_id', 'watch_updated_at', 'subject_id'], 'acgn_records_user_watch_index');
            $table->index(['user_id', 'rated_at', 'subject_id'], 'acgn_records_user_rating_index');
        });
        $schema->create('acgn_showcase_settings', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->string('watching_visibility', 16)->default('self');
            $table->string('ratings_visibility', 16)->default('self');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('acgn_showcase_settings');
        $schema->table('acgn_records', function (Blueprint $table): void {
            $table->dropIndex('acgn_records_user_watch_index');
            $table->dropIndex('acgn_records_user_rating_index');
        });
        $schema->table('acgn_records', function (Blueprint $table): void {
            $table->dropColumn(['share_visibility', 'share_score', 'share_progress', 'watch_updated_at', 'rated_at']);
        });
    },
];

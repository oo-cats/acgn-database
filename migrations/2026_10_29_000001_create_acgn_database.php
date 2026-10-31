<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (Builder $schema): void {
        $schema->create('acgn_subjects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type', 16);
            $table->string('title');
            $table->string('original_title')->default('');
            $table->text('aliases');
            $table->text('summary');
            $table->text('cover_url');
            $table->string('release_date', 10)->default('');
            $table->unsignedInteger('total_units')->nullable();
            $table->text('tags');
            $table->string('source', 32)->nullable();
            $table->unsignedInteger('source_id')->nullable();
            $table->decimal('source_score', 4, 2)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['source', 'source_id']);
            $table->index(['archived', 'type', 'id']);
        });
        $schema->create('acgn_people', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kind', 16);
            $table->string('name');
            $table->string('original_name')->default('');
            $table->text('aliases');
            $table->text('summary');
            $table->text('image_url');
            $table->string('source', 32)->nullable();
            $table->unsignedInteger('source_id')->nullable();
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['source', 'kind', 'source_id']);
            $table->index(['archived', 'kind', 'id']);
        });
        $schema->create('acgn_credits', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('person_id');
            $table->string('role', 100);
            $table->unique(['subject_id', 'person_id', 'role']);
            $table->foreign('subject_id')->references('id')->on('acgn_subjects')->onDelete('cascade');
            $table->foreign('person_id')->references('id')->on('acgn_people')->onDelete('cascade');
        });
        $schema->create('acgn_records', function (Blueprint $table): void {
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('user_id');
            $table->string('status', 16);
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('favorite')->default(false);
            $table->unsignedInteger('progress')->default(0);
            $table->timestamps();
            $table->primary(['subject_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('subject_id')->references('id')->on('acgn_subjects')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        $schema->create('acgn_discussions', function (Blueprint $table): void {
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('discussion_id');
            $table->primary(['subject_id', 'discussion_id']);
            $table->foreign('subject_id')->references('id')->on('acgn_subjects')->onDelete('cascade');
            $table->foreign('discussion_id')->references('id')->on('discussions')->onDelete('cascade');
        });
        $schema->create('acgn_imports', function (Blueprint $table): void {
            $table->string('token', 48)->primary();
            $table->unsignedInteger('user_id');
            $table->mediumText('payload');
            $table->timestamp('expires_at')->index();
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        $schema->getConnection()->table('group_permission')->insert([
            ['group_id' => 2, 'permission' => 'acgn.view'],
            ['group_id' => 3, 'permission' => 'acgn.collect'],
        ]);
    },
    'down' => function (Builder $schema): void {
        foreach (['acgn_imports', 'acgn_discussions', 'acgn_records', 'acgn_credits', 'acgn_people', 'acgn_subjects'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->getConnection()->table('group_permission')->whereIn('permission', ['acgn.view', 'acgn.collect', 'acgn.manage', 'acgn.import', 'acgn.link'])->delete();
    },
];

<?php

use Illuminate\Database\Schema\{Builder, Blueprint};

return [
    'up' => function (Builder $schema): void {
        $schema->table('acgn_records', function (Blueprint $table): void {
            $table->text('comments')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->string('imported_from', 32)->nullable();
            $table->unsignedInteger('imported_account')->nullable();
            $table->string('source_updated_at', 40)->nullable();
            $table->timestamp('imported_at')->nullable();
        });
        // One expiring preview per user; consumed drafts retain only the request cooldown.
        $schema->create('acgn_collection_imports', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->string('token', 48)->nullable()->unique();
            $table->mediumText('payload')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        $schema->getConnection()->table('group_permission')->insert(['group_id' => 3, 'permission' => 'acgn.import_collection']);
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('acgn_collection_imports');
        $schema->table('acgn_records', fn (Blueprint $table) => $table->dropColumn(['comments', 'revision', 'imported_from', 'imported_account', 'source_updated_at', 'imported_at']));
        $schema->getConnection()->table('group_permission')->where('permission', 'acgn.import_collection')->delete();
    },
];

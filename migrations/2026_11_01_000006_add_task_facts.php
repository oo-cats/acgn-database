<?php

use Illuminate\Database\Schema\{Builder, Blueprint};

return [
    'up' => function (Builder $schema): void {
        $schema->create('acgn_first_completions', function (Blueprint $table): void {
            $table->unsignedInteger('user_id'); $table->unsignedInteger('subject_id');
            $table->dateTime('observed_at', 6); $table->string('origin', 16);
            $table->primary(['user_id', 'subject_id']);
            $table->index(['user_id', 'origin', 'observed_at', 'subject_id'], 'acgn_completion_user_origin_time');
            $table->index(['subject_id', 'user_id'], 'acgn_completion_subject_user');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('subject_id')->references('id')->on('acgn_subjects')->onDelete('restrict');
        });
        $schema->table('acgn_discussions', fn (Blueprint $table) => $table->index(['discussion_id', 'subject_id'], 'acgn_discussion_subject'));
    },
    'down' => function (Builder $schema): void {
        $db = $schema->getConnection();
        if ($db->getDriverName() === 'mysql') {
            // InnoDB may remove its automatic FK index after the covering index is added.
            $indexes = $db->select('SHOW INDEX FROM '.$db->getQueryGrammar()->wrapTable('acgn_discussions'));
            $other = array_filter($indexes, fn ($row) => $row->Key_name !== 'acgn_discussion_subject' && (int) $row->Seq_in_index === 1 && $row->Column_name === 'discussion_id');
            if (!$other) $schema->table('acgn_discussions', fn (Blueprint $table) => $table->index('discussion_id', 'acgn_discussions_discussion_id_foreign'));
        }
        $schema->table('acgn_discussions', fn (Blueprint $table) => $table->dropIndex('acgn_discussion_subject'));
        $schema->dropIfExists('acgn_first_completions');
    },
];

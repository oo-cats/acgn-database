<?php
use Illuminate\Database\Schema\{Builder, Blueprint};

// Flarum's notification visibility subquery uses an unqualified subject_id.
// The subject model's own table must not shadow notifications.subject_id.
return [
    'up' => function (Builder $schema): void {
        $schema->table('acgn_import_requests', fn(Blueprint $t) => $t->renameColumn('subject_id', 'local_subject_id'));
    },
    'down' => function (Builder $schema): void {
        $schema->table('acgn_import_requests', fn(Blueprint $t) => $t->renameColumn('local_subject_id', 'subject_id'));
    },
];

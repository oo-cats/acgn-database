import app from 'flarum/admin/app';

app.initializers.add('local-acgn-database', () => {
  const trans = (key) => app.translator.trans(`local-acgn-database.admin.${key}`);
  app.extensionData.for('local-acgn-database')
    .registerSetting({ setting: 'acgn.bangumi_user_agent', label: trans('user_agent'), help: trans('user_agent_help'), type: 'text' })
    .registerPermission({ icon: 'fas fa-book-open', label: trans('view'), permission: 'acgn.view', allowGuest: true }, 'view')
    .registerPermission({ icon: 'fas fa-star', label: trans('collect'), permission: 'acgn.collect' }, 'reply')
    .registerPermission({ icon: 'fas fa-file-import', label: trans('import_collection'), permission: 'acgn.import_collection' }, 'reply')
    .registerPermission({ icon: 'fas fa-pen', label: trans('manage'), permission: 'acgn.manage' }, 'moderate')
    .registerPermission({ icon: 'fas fa-cloud-download-alt', label: trans('import'), permission: 'acgn.import' }, 'moderate')
    .registerPermission({ icon: 'fas fa-link', label: trans('link'), permission: 'acgn.link' }, 'moderate');
});

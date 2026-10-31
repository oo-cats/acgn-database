import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from './common';

export default class ImportRequestNotification extends Notification {
  icon() { return 'fas fa-file-import'; }
  href() { return app.route('acgn.requests') + '?scope=' + (this.attrs.notification.content().scope === 'review' ? 'review' : 'mine'); }
  content() {
    const data = this.attrs.notification.content();
    return <span>{t('requests')} · {data.title} · {t('request_' + data.status)}</span>;
  }
}

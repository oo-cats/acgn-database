import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { api, can, t, cover } from './common';

export default class ImportRequestsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.scope = can('Approve') && m.route.param('scope') !== 'mine' ? 'review' : 'mine';
    this.items = []; this.next = null; this.after = 0; this.busy = false; this.error = false; this.preview = null;
    this.filter = 'pending';
    if (can('View') && can('Requests')) this.load();
    app.setTitle(t('requests'));
  }
  async load(after = 0) {
    this.busy = true; this.error = false; this.preview = null;
    try {
      const result = await api(`/import-requests?${new URLSearchParams({ scope: this.scope, status: this.filter, after })}`);
      this.items = result.items; this.next = result.next; this.after = after;
    } catch (_) { this.error = true; }
    finally { this.busy = false; m.redraw(); }
  }
  async run(item, action) {
    if (this.busy) return;
    this.busy = true; this.error = false;
    try {
      if (action === 'preview') {
        this.preview = await api(`/import-requests/${item.id}/preview`, 'POST', { revision: item.revision });
        this.preview.item = item;
      } else {
        if (action === 'cancel') await api(`/import-applications/${item.id}/cancel`, 'POST', {});
        else await api(`/import-requests/${item.id}/decision`, 'POST', {
          decision: action, revision: item.revision, ...(action === 'approved' ? { token: this.preview.token } : {}),
        });
        await this.load(this.after);
      }
    } catch (_) { this.error = true; }
    finally { this.busy = false; m.redraw(); }
  }
  view() {
    if (!can('View') || !can('Requests')) return <main className="container Acgn-page"><p>{t('no_access')}</p></main>;
    return <main className="container Acgn-page Acgn-requests">
      <nav className="Acgn-tabs"><Link href={app.route('acgn.catalog')}>{t('catalog')}</Link></nav>
      <h1>{t('requests')}</h1><p className="helpText">{t(this.scope === 'review' ? 'request_review_hint' : 'request_mine_hint')}</p>
      <div className="Acgn-actions">
        {can('Requests') && <Button className="Button" disabled={this.busy} onclick={() => { this.scope = 'mine'; this.load(); }}>{t('request_mine')}</Button>}
        {can('Approve') && <Button className="Button" disabled={this.busy} onclick={() => { this.scope = 'review'; this.load(); }}>{t('request_review')}</Button>}
        {this.scope === 'review' && <select className="FormControl" aria-label={t('request_status')} value={this.filter} disabled={this.busy} onchange={e => { this.filter = e.target.value; this.load(); }}>
          {['pending', 'approved', 'rejected'].map(value => <option value={value}>{t('request_' + value)}</option>)}
        </select>}
        <Button className="Button" disabled={this.busy} onclick={() => this.load(this.after)}>{t('request_refresh')}</Button>
      </div>
      {this.error && <p className="Alert Alert--error" role="alert">{t('request_error')}</p>}
      {this.busy && <LoadingIndicator />}
      {this.preview && <section className="Acgn-preview Acgn-collection-item" aria-live="polite">
        {cover(this.preview.subject)}<h2>{this.preview.subject.title}</h2><p className="Acgn-summary">{this.preview.subject.summary}</p>
        <p>{t('import_counts', { people: this.preview.people.length, credits: this.preview.credits.length })}</p>
        <p>{t('request_auto_import')}</p>
        {this.preview.truncated && <p>{t('truncated')}</p>}
        <Button className="Button Button--primary" disabled={this.busy} onclick={() => this.run(this.preview.item, 'approved')}>{t('request_approve')}</Button>
      </section>}
      {!this.busy && !this.items.length && <p className="Acgn-empty">{t('request_empty')}</p>}
      {this.items.map(item => <section className="Acgn-collection-item" key={item.id}>
        <h2>{item.title}</h2><a href={`https://bgm.tv/subject/${item.source_id}`} target="_blank" rel="noopener noreferrer">{t('view_source')}</a>
        <p>{t('request_' + item.status)}</p>
        {item.subject_id && <Link href={app.route('acgn.subject', { id: item.subject_id })}>{t('personal_local_entry')}</Link>}
        {item.status === 'pending' && <div className="Acgn-actions">
          {this.scope === 'review' ? <><Button className="Button" disabled={this.busy} onclick={() => this.run(item, 'preview')}>{t('preview')}</Button>
            <Button className="Button" disabled={this.busy} onclick={() => { if (window.confirm(t('request_reject_confirm'))) this.run(item, 'rejected'); }}>{t('request_reject')}</Button></>
            : <Button className="Button" disabled={this.busy} onclick={() => this.run(item, 'cancel')}>{t('request_cancel')}</Button>}
        </div>}
      </section>)}
      <div className="Acgn-pagination"><Button className="Button" disabled={this.busy || !this.after} onclick={() => this.load()}>{t('request_first')}</Button>
        <Button className="Button" disabled={this.busy || this.next === null} onclick={() => this.load(this.next)}>{t('next')}</Button></div>
    </main>;
  }
}

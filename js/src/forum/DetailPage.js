import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import LogInModal from 'flarum/forum/components/LogInModal';
import extractText from 'flarum/common/utils/extractText';
import EntityModal from './EntityModal';
import CreditModal from './CreditModal';
import { api, t, can, STATUSES, cover, url, listText, sourceUrl } from './common';

export default class DetailPage extends Page {
  oninit(vnode) {
    super.oninit(vnode); this.people = !!this.attrs.people; this.item = null; this.busy = false; this.failed = false; this.discussion = '';
    this.id = m.route.param('id'); this.load();
  }
  onbeforeupdate(vnode) {
    super.onbeforeupdate(vnode);
    const id = m.route.param('id');
    if (this.id !== id) { this.id = id; this.item = null; this.discussion = ''; this.load(); }
  }
  apply(item) {
    this.item = item;
    this.record = { status: 'planned', score: null, favorite: false, progress: 0, comments: '', ...item.my_record };
    app.setTitle(item[this.people ? 'name' : 'title']);
  }
  async load() {
    const id = this.id; this.failed = false;
    try { const item = await api(`/${this.people ? 'people' : 'subjects'}/${id}`); if (this.id === id) this.apply(item); }
    catch (_) { if (this.id === id) this.failed = true; }
    finally { m.redraw(); }
  }
  async run(path, method, data, links = false) {
    if (this.busy) return;
    this.busy = true;
    try {
      const item = await api(path, method, data);
      if (links) { this.item.discussions = item; this.discussion = ''; }
      else this.apply(item);
    } catch (_) { /* Flarum displays validation and permission errors. */ }
    finally { this.busy = false; m.redraw(); }
  }
  view() {
    if (!can('View')) return <div className="container Acgn-page"><p>{t('no_access')}</p></div>;
    if (this.failed) return <div className="container Acgn-page"><p role="alert">{t('load_error')}</p><Button className="Button" onclick={() => this.load()}>{t('retry')}</Button></div>;
    if (!this.item) return <div className="container Acgn-page"><LoadingIndicator /></div>;
    const item = this.item;
    return <main className="container Acgn-page">
      <nav className="Acgn-tabs"><Link href={app.route('acgn.catalog')}>{t('catalog')}</Link><Link href={app.route('acgn.people')}>{t('people')}</Link></nav>
      {item.archived && <p className="Alert">{t('archived')}</p>}
      <header className="Acgn-detail-header">{cover(item, this.people)}<div className="Acgn-detail-info">
        <span className="Acgn-type">{t(item[this.people ? 'kind' : 'type'])}</span><h1>{item[this.people ? 'name' : 'title']}</h1>
        <p>{item[this.people ? 'original_name' : 'original_title']}</p>
        {!!item.aliases.length && <p>{t('aliases')}: {listText(item.aliases)}</p>}
        {!this.people && <><p>{item.release_date} {item.total_units && <span> · {t('units', { count: item.total_units })}</span>}</p>
          {this.ratingsView()}
          <p className="Acgn-tags">{item.tags.map((tag) => <span>{tag}</span>)}</p></>}
        {item.source === 'bangumi' && <a href={sourceUrl(item, this.people)} target="_blank" rel="noopener noreferrer">{t('view_source')} ↗</a>}
        {(can('Update') || can('Delete')) && <div className="Acgn-actions">{can('Update') && <Button className="Button" disabled={this.busy} onclick={() => app.modal.show(EntityModal, { people: this.people, entity: item, onSave: (updated) => this.apply(updated) })}>{t('edit')}</Button>}
          {can('Delete') && <Button className="Button" loading={this.busy} disabled={this.busy} onclick={() => {
            if (window.confirm(app.translator.trans(`local-acgn-database.forum.${item.archived ? 'restore_confirm' : 'archive_confirm'}`))) {
              this.run(`/${this.people ? 'people' : 'subjects'}/${item.id}/archive`, 'PUT', { revision: item.revision, archived: !item.archived });
            }
          }}>{t(item.archived ? 'restore' : 'archive')}</Button>}</div>}
      </div></header>
      <div className="Acgn-detail-grid"><div className="Acgn-main">
        <section><h2>{t('summary')}</h2><p className="Acgn-summary">{item.summary || t('no_summary')}</p></section>
        <section><div className="Acgn-section-title"><h2>{t(this.people ? 'works' : 'credits')}</h2>
          {!this.people && can('Update') && <Button className="Button" onclick={() => app.modal.show(CreditModal, { subject: item, onSave: (updated) => this.apply(updated) })}>{t('add_credit')}</Button>}
        </div>
          {this.people ? <ul className="Acgn-relations">{item.works.map((work) => <li key={work.credit_id}><Link href={url(work)}>{work.title}</Link><span>{work.role}</span></li>)}</ul>
            : <ul className="Acgn-relations">{item.credits.map((credit) => <li key={credit.credit_id}><Link href={url(credit, true)}>{credit.name}</Link><span>{credit.role}</span>
              {can('Update') && <Button className="Button Button--text" disabled={this.busy} onclick={() => {
                if (window.confirm(app.translator.trans('local-acgn-database.forum.unlink_credit_confirm'))) this.run(`/subjects/${item.id}/credits`, 'DELETE', { person_id: credit.id, role: credit.role });
              }}>{t('unlink')}</Button>}</li>)}</ul>}
          {!(this.people ? item.works : item.credits).length && <p className="helpText">{t('no_relations')}</p>}
          {item.credits_more && <p className="helpText">{t('relations_more')}</p>}
        </section>
        {!this.people && this.discussionsView()}
      </div>{!this.people && <aside className="Acgn-record">{this.recordView()}</aside>}</div>
    </main>;
  }
  ratingsView() {
    const item = this.item;
    const counts = item.rating_distribution || Array(10).fill(0);
    const maximum = Math.max(1, ...counts);
    const rated = item.rating_count > 0;
    return <div className="Acgn-rating-panel" role="group" aria-labelledby="acgn-rating-title">
      <div className="Acgn-rating-summary">
        <h2 id="acgn-rating-title">{t('local_rating')}</h2>
        <span className="Acgn-rating-stat-label">{t('rating_mean')}</span>
        <div className="Acgn-rating-mean"><strong>{rated ? Number(item.rating).toFixed(2) : '—'}</strong><span> / 10</span></div>
        <p>{t('rating_count', { count: item.rating_count })}</p>
        <dl className="Acgn-rating-variance"><dt>{t('rating_variance')}</dt><dd>{rated && Number.isFinite(item.rating_variance) ? item.rating_variance.toFixed(2) : '—'}</dd></dl>
      </div>
      <figure className="Acgn-rating-distribution">
        <figcaption>{t('rating_distribution')}</figcaption>
        <ol className="Acgn-rating-bars" aria-label={extractText(t('rating_distribution'))}>
          {counts.map((count, index) => {
            const label = extractText(t('rating_bucket', { score: index + 1, count, percentage: rated ? (count / item.rating_count * 100).toFixed(1) : '0.0' }));
            return <li key={index + 1} tabIndex="0" aria-label={label} title={label}>
              <span className="Acgn-rating-track" aria-hidden="true"><span className="Acgn-rating-stack" style={{ height: `${count / maximum * 100}%` }}>
                <span className="Acgn-rating-bar-count">{count}</span><span className="Acgn-rating-bar" />
              </span></span>
              <span className="Acgn-rating-score" aria-hidden="true">{index + 1}</span>
            </li>;
          })}
        </ol>
      </figure>
      <p className="Acgn-rating-note">{rated ? t('rating_method') : t('rating_empty')}</p>
    </div>;
  }
  recordView() {
    const item = this.item;
    if (!app.session.user) return <><h2>{t('my_record')}</h2><Button className="Button Button--primary" onclick={() => app.modal.show(LogInModal)}>{t('login_to_collect')}</Button></>;
    if (!can('Collect') || item.archived) return <p className="helpText">{t('collection_unavailable')}</p>;
    return <form onsubmit={(e) => { e.preventDefault(); this.run(`/subjects/${item.id}/record`, 'PUT', this.record); }}>
      <h2>{t('my_record')}</h2><p className="helpText">{t('record_privacy')}</p>
      <div className="Form-group"><label for="acgn-record-status">{t('status')}</label><select id="acgn-record-status" className="FormControl" value={this.record.status} onchange={(e) => { this.record.status = e.target.value; }}>{STATUSES.map((s) => <option value={s}>{t(s)}</option>)}</select></div>
      <div className="Form-group"><label for="acgn-record-score">{t('my_score')}</label><select id="acgn-record-score" className="FormControl" value={this.record.score ?? ''} onchange={(e) => { this.record.score = e.target.value === '' ? null : Number(e.target.value); }}>
        <option value="">{t('unrated')}</option>{Array.from({ length: 10 }, (_, i) => 10 - i).map((n) => <option value={n}>{n}</option>)}
      </select></div>
      <div className="Form-group"><label for="acgn-record-progress">{t('progress')}</label><input id="acgn-record-progress" className="FormControl" type="number" min="0" max={item.total_units ?? 1000000} step="1" value={this.record.progress}
        oninput={(e) => { this.record.progress = e.target.value === '' ? 0 : Number(e.target.value); }} /></div>
      <div className="Form-group"><label><input type="checkbox" checked={this.record.favorite} onchange={(e) => { this.record.favorite = e.target.checked; }} /> {t('favorite')}</label></div>
      <div className="Form-group"><label for="acgn-record-comments">{t('comments')}</label><textarea id="acgn-record-comments" className="FormControl" rows="4" maxlength="2000" value={this.record.comments}
        oninput={event => { this.record.comments = event.target.value; }} /><small className="helpText">{t('comments_hint')}</small></div>
      {item.my_record?.imported_from === 'bangumi' && <p className="helpText">{t('personal_imported_at', { date: item.my_record.imported_at })}</p>}
      <Button className="Button Button--primary" type="submit" loading={this.busy} disabled={this.busy}>{t('save_record')}</Button>
      {item.my_record && <Button className="Button Button--text" disabled={this.busy} onclick={() => {
        if (window.confirm(app.translator.trans('local-acgn-database.forum.remove_record_confirm'))) this.run(`/subjects/${item.id}/record`, 'DELETE');
      }}>{t('remove_record')}</Button>}
    </form>;
  }
  discussionsView() {
    const item = this.item;
    return <section><h2>{t('discussions')}</h2><ul className="Acgn-relations">{item.discussions.map((discussion) => <li key={discussion.id}>
      <Link href={app.route.discussion({ id: () => discussion.id, slug: () => discussion.slug })}>{discussion.title}</Link>
      {can('Link') && <Button className="Button Button--text" disabled={this.busy} onclick={() => this.run(`/subjects/${item.id}/discussions/${discussion.id}`, 'DELETE', undefined, true)}>{t('unlink')}</Button>}
    </li>)}</ul>
      {!item.discussions.length && <p className="helpText">{t('no_discussions')}</p>}
      {item.discussions.length === 50 && <p className="helpText">{t('discussions_more')}</p>}
      {can('Link') && <form className="Acgn-toolbar" onsubmit={(e) => { e.preventDefault(); this.linkDiscussion(); }}><input className="FormControl" aria-label={app.translator.trans('local-acgn-database.forum.discussion_link')}
        placeholder={t('discussion_link')} value={this.discussion} oninput={(e) => { this.discussion = e.target.value; }} />
        <Button className="Button" type="submit" loading={this.busy} disabled={this.busy || !this.discussion.trim()}>{t('link_discussion')}</Button></form>}
    </section>;
  }
  linkDiscussion() {
    const text = this.discussion.trim();
    let id = /^\d+$/.test(text) ? text : null;
    if (!id) {
      try {
        const link = new URL(text, window.location.origin);
        if (link.origin === window.location.origin) id = link.pathname.match(/\/d\/(\d+)(?:[-/]|$)/)?.[1];
      } catch (_) { /* Invalid URLs are reported below. */ }
    }
    if (!id) { app.alerts.show({ type: 'error' }, t('invalid_discussion')); return; }
    this.run(`/subjects/${this.item.id}/discussions/${id}`, 'PUT', undefined, true);
  }
}

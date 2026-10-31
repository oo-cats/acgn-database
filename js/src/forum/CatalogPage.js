import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import EntityModal from './EntityModal';
import ImportModal from './ImportModal';
import CollectionImportModal from './CollectionImportModal';
import { api, t, can, TYPES, cover, url } from './common';

export default class CatalogPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.people = !!this.attrs.people;
    this.query = m.route.param('q') || ''; this.filter = m.route.param('type') || ''; this.mine = false; this.archived = false;
    this.page = 1; this.items = []; this.busy = false; this.failed = false; this.more = false; this.load();
    app.setTitle(t(this.people ? 'people' : 'catalog'));
  }
  oncreate(vnode) { super.oncreate(vnode); app.history.push('acgn', app.route(this.people ? 'acgn.people' : 'acgn.catalog')); }
  async load(page = 1) {
    this.busy = true; this.failed = false;
    const query = new URLSearchParams({ q: this.query, page, [this.people ? 'kind' : 'type']: this.filter,
      mine: this.mine ? '1' : '', archived: this.archived ? '1' : '' });
    try {
      const data = await api(`/${this.people ? 'people' : 'subjects'}?${query}`);
      this.items = data.items; this.more = data.more; this.page = page;
    } catch (_) { this.failed = true; }
    finally { this.busy = false; m.redraw(); }
  }
  view() {
    if (!can('View')) return <div className="container Acgn-page"><p>{t('no_access')}</p></div>;
    return <main className="container Acgn-page">
      <header className="Acgn-header"><div><p className="Acgn-eyebrow">acgn-database</p><h1>{t(this.people ? 'people' : 'catalog')}</h1></div>
        <div className="Acgn-actions">
          {can('PersonalImport') && !this.people && <Button className="Button" icon="fas fa-file-import" onclick={() => app.modal.show(CollectionImportModal, { onImported: () => { this.mine = true; this.load(); } })}>{t('personal_import')}</Button>}
          {can('Requests') && <Link className="Button" href={app.route('acgn.requests')}>{t('requests')}</Link>}
          {can('Create') && !this.people && <Button className="Button" icon="fas fa-cloud-download-alt" onclick={() => app.modal.show(ImportModal)}>{t('import')}</Button>}
          {can('Create') && <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => app.modal.show(EntityModal, { people: this.people, onSave: (item) => m.route.set(url(item, this.people)) })}>{t(this.people ? 'new_person' : 'new_subject')}</Button>}
        </div>
      </header>
      <nav className="Acgn-tabs"><Link href={app.route('acgn.catalog')}>{t('catalog')}</Link><Link href={app.route('acgn.people')}>{t('people')}</Link></nav>
      <form className="Acgn-toolbar" onsubmit={(e) => { e.preventDefault(); if (!this.busy) this.load(); }}>
        <input className="FormControl" aria-label={app.translator.trans('local-acgn-database.forum.search_label')} placeholder={t('search_placeholder')} maxlength="100" value={this.query} oninput={(e) => { this.query = e.target.value; }} />
        <select className="FormControl" aria-label={app.translator.trans('local-acgn-database.forum.type')} value={this.filter} onchange={(e) => { this.filter = e.target.value; this.load(); }} disabled={this.busy}>
          <option value="">{t('all')}</option>{(this.people ? ['person', 'character'] : TYPES).map((v) => <option value={v}>{t(v)}</option>)}
        </select>
        {!this.people && app.session.user && <label className="Acgn-check"><input type="checkbox" checked={this.mine} disabled={this.busy} onchange={(e) => { this.mine = e.target.checked; this.load(); }} /> {t('my_collection')}</label>}
        {can('Delete') && <label className="Acgn-check"><input type="checkbox" checked={this.archived} disabled={this.busy} onchange={(e) => { this.archived = e.target.checked; this.load(); }} /> {t('archived_only')}</label>}
        <Button className="Button" type="submit" disabled={this.busy} icon="fas fa-search">{t('search')}</Button>
      </form>
      {this.busy ? <LoadingIndicator /> : this.failed ? <div className="Acgn-empty"><p role="alert">{t('load_error')}</p><Button className="Button" onclick={() => this.load()}>{t('retry')}</Button></div>
        : this.items.length ? <div className="Acgn-grid">{this.items.map((item) => <Link className="Acgn-card" href={url(item, this.people)} key={item.id}>
          {cover(item, this.people)}<div className="Acgn-card-body"><span className="Acgn-type">{t(item[this.people ? 'kind' : 'type'])}</span><h3>{item[this.people ? 'name' : 'title']}</h3>
            <p>{item[this.people ? 'original_name' : 'original_title']}</p>{!this.people && <span className="Acgn-rating">★ {item.rating ?? '—'} <small>{t('rating_count', { count: item.rating_count })}</small></span>}
            {!this.people && this.mine && item.my_record && <div className="Acgn-card-record"><p>{t(item.my_record.status)} · {t('progress')}: {item.my_record.progress}</p>
              {item.my_record.comments && <p className="Acgn-comment-snippet">{item.my_record.comments}</p>}</div>}
          </div></Link>)}</div> : <div className="Acgn-empty">{t('empty')}</div>}
      <div className="Acgn-pagination"><Button className="Button" disabled={this.busy || this.page === 1} onclick={() => this.load(this.page - 1)}>{t('previous')}</Button>
        <span>{this.page}</span><Button className="Button" disabled={this.busy || !this.more || this.page >= 500} onclick={() => this.load(this.page + 1)}>{t('next')}</Button></div>
    </main>;
  }
}

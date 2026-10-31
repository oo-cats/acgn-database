import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { api, t, url } from './common';

export default class CollectionImportModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.accessToken = ''; this.preview = null; this.selected = new Set(); this.conflict = 'skip';
    this.consent = false; this.busy = false; this.error = false; this.result = null; this.nextOffset = null; this.closed = false;
  }
  className() { return 'Acgn-modal Acgn-collection-modal Modal--large'; }
  title() { return t('personal_import'); }
  onsubmit(event) { event.preventDefault(); }
  onremove() { this.closed = true; this.accessToken = ''; this.discard(); }
  async discard() {
    const token = this.preview?.token;
    this.preview = null;
    if (token) { try { await api('/collection-import', 'DELETE', { token }); } catch (_) { /* Expiry remains the fallback for an interrupted close. */ } }
  }
  async fetchPage(offset = 0) {
    if (this.busy || !this.accessToken.trim()) return;
    this.busy = true; this.error = false; this.result = null; this.consent = false;
    try {
      this.preview = await api('/collection-import/preview', 'POST', { access_token: this.accessToken.trim(), offset });
      if (this.closed) { await this.discard(); return; }
      this.selected = new Set(this.preview.items.filter(item => (item.reason === null || item.reason === 'missing') && !item.existing).map(item => item.source_id));
    } catch (_) { this.preview = null; this.error = true; }
    finally { this.busy = false; m.redraw(); }
  }
  async confirm() {
    if (this.busy || !this.preview || !this.selected.size || !this.consent) return;
    this.busy = true; this.error = false;
    try {
      const nextOffset = this.preview.next_offset;
      this.result = await api('/collection-import/confirm', 'POST', { token: this.preview.token, source_ids: Array.from(this.selected), conflict: this.conflict, consent: this.consent });
      this.nextOffset = nextOffset;
      this.preview = null; this.selected.clear(); this.consent = false;
      if (this.attrs.onImported) this.attrs.onImported();
    } catch (_) { this.error = true; }
    finally { this.busy = false; m.redraw(); }
  }
  recordSummary(record) {
    return <div className="Acgn-import-record"><p>{t(record.status)} · {t('my_score')}: {record.score ?? '—'} · {t('progress')}: {record.progress}</p>
      <p className="Acgn-short-comment">{record.comments || t('no_comments')}</p></div>;
  }
  content() {
    return <div className="Modal-body">
      <p>{t('personal_import_hint')}</p>
      {this.error && <p className="Alert Alert--error" role="alert">{t('personal_import_error')}</p>}
      {this.result && <p className="Alert Alert--success" role="status">{t('personal_import_result', this.result)}</p>}
      <div className="Form-group"><label for="acgn-access-token">{t('personal_access_token')}</label>
        <input id="acgn-access-token" type="password" autoComplete="off" className="FormControl" value={this.accessToken} disabled={this.busy}
          oninput={event => { this.accessToken = event.target.value; }} />
        <small>{t('personal_token_hint')}</small></div>
      <Button type="button" className="Button" loading={this.busy} disabled={this.busy || !this.accessToken.trim()} onclick={() => this.fetchPage()}>{t('personal_read')}</Button>
      {this.result && this.nextOffset !== null && <Button type="button" className="Button" disabled={this.busy || !this.accessToken.trim()} onclick={() => this.fetchPage(this.nextOffset)}>{t('personal_continue')}</Button>}
      {this.preview && <div className="Acgn-collection-preview" aria-live="polite">
        <p>{t('personal_identity', { username: this.preview.account.username, total: this.preview.total, offset: this.preview.offset })}</p>
        <p className="helpText">{t('personal_mapping_hint')}</p>
        {!this.preview.items.length && <p>{t('personal_empty')}</p>}
        {this.preview.items.map(item => <section className="Acgn-collection-item" key={item.source_id}>
          <label className="Acgn-collection-choice"><input type="checkbox" checked={this.selected.has(item.source_id)} disabled={this.busy || (item.reason !== null && item.reason !== 'missing')}
            onchange={event => { if (event.target.checked) this.selected.add(item.source_id); else this.selected.delete(item.source_id); }} /> <strong>{item.title}</strong></label>
          {item.subject_id && item.reason === null && <a href={url({ id: item.subject_id })} target="_blank" rel="noopener noreferrer">{t('personal_local_entry')}</a>}
          {item.reason && <p className="helpText">{t('personal_unavailable_' + item.reason)}</p>}
          <h3>{t('personal_source_record')}</h3>{this.recordSummary(item.record)}
          {item.existing && <details><summary>{t('personal_existing')}</summary>{this.recordSummary(item.existing)}</details>}
        </section>)}
        <div className="Form-group"><label for="acgn-import-conflict">{t('personal_conflict')}</label>
          <select id="acgn-import-conflict" className="FormControl" value={this.conflict} disabled={this.busy} onchange={event => { this.conflict = event.target.value; }}>
            <option value="skip">{t('personal_skip')}</option><option value="replace">{t('personal_replace')}</option>
          </select></div>
        <label className="Acgn-import-consent"><input type="checkbox" checked={this.consent} disabled={this.busy} onchange={event => { this.consent = event.target.checked; }} /> {t('personal_consent')}</label>
        <p className="helpText">{t('personal_preview_expiry')}</p>
        <div className="Acgn-actions">
          <Button type="button" className="Button Button--primary" loading={this.busy} disabled={this.busy || !this.selected.size || !this.consent} onclick={() => this.confirm()}>{t('personal_confirm', { count: this.selected.size })}</Button>
          <Button type="button" className="Button" disabled={this.busy || !this.accessToken.trim() || this.preview.offset === 0} onclick={() => this.fetchPage(Math.max(0, this.preview.offset - 20))}>{t('previous')}</Button>
          <Button type="button" className="Button" disabled={this.busy || !this.accessToken.trim() || this.preview.next_offset === null} onclick={() => this.fetchPage(this.preview.next_offset)}>{t('next')}</Button>
        </div>
      </div>}
    </div>;
  }
}

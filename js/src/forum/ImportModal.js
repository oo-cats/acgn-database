import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { api, t, TYPES, cover, url } from './common';

export default class ImportModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.subject = ''; this.type = 'anime'; this.preview = null; this.busy = false; }
  className() { return 'Acgn-modal Modal--large'; }
  title() { return t('import'); }
  onsubmit(event) { event.preventDefault(); return this.run(); }
  content() {
    return <div className="Modal-body">
      <p className="helpText">{t('import_hint')}</p>
      <div className="Form-group"><label for="acgn-import-subject">{t('bangumi_subject')}</label><input id="acgn-import-subject" className="FormControl" value={this.subject} maxlength="300" disabled={this.busy}
        oninput={(e) => { this.subject = e.target.value; this.preview = null; }} /></div>
      <div className="Form-group"><label for="acgn-import-type">{t('type')}</label><select id="acgn-import-type" className="FormControl" value={this.type} disabled={this.busy}
        onchange={(e) => { this.type = e.target.value; this.preview = null; }}>{TYPES.map((v) => <option value={v}>{t(v)}</option>)}</select><small>{t('book_hint')}</small></div>
      {this.preview && <section className="Acgn-preview" aria-live="polite">
        {cover(this.preview.subject)}<h3>{this.preview.subject.title}</h3>
        <p>{this.preview.subject.original_title}</p><p>{t('import_counts', { people: this.preview.people.length, credits: this.preview.credits.length })}</p>
        <p className="Acgn-summary">{this.preview.subject.summary}</p>
        <details><summary>{t('credits')}</summary><ul>{this.preview.credits.map((credit) => {
          const person = this.preview.people.find((p) => `${p.kind}:${p.source_id}` === credit.key);
          return <li>{person?.name} · {credit.role}</li>;
        })}</ul></details>
        {this.preview.truncated && <p className="Alert">{t('truncated')}</p>}
        <p className="helpText">{t('import_preserve')}</p>
      </section>}
      <Button type="submit" className="Button Button--primary" loading={this.busy} disabled={this.busy || !this.subject.trim()}>{t(this.preview ? 'confirm_import' : 'preview')}</Button>
    </div>;
  }
  async run() {
    if (this.busy) return;
    this.busy = true;
    try {
      if (!this.preview) this.preview = await api('/import/bangumi/preview', 'POST', { subject: this.subject, type: this.type });
      else {
        const item = await api('/import/bangumi/confirm', 'POST', { token: this.preview.token });
        this.hide();
        m.route.set(url(item));
      }
    } catch (_) { this.preview = null; }
    finally { this.busy = false; m.redraw(); }
  }
}

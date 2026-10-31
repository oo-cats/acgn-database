import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { api, t } from './common';

export default class CreditModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.query = ''; this.results = []; this.person = ''; this.role = ''; this.busy = false; this.error = false; }
  title() { return t('add_credit'); }
  className() { return 'Acgn-modal'; }
  onsubmit(event) { event.preventDefault(); return this.save(); }
  content() {
    return <div className="Modal-body">
      <div className="Form-group"><label for="acgn-credit-search">{t('search_people')}</label>
        <input id="acgn-credit-search" className="FormControl" value={this.query} maxlength="100" oninput={(e) => { this.query = e.target.value; }} onkeydown={(e) => { if (e.key === 'Enter') { e.preventDefault(); this.search(); } }} />
        <Button type="button" className="Button" disabled={this.busy} loading={this.busy} onclick={() => this.search()}>{t('search')}</Button>
      </div>
      {this.error && <p role="alert">{t('load_error')}</p>}
      <div>
        <div className="Form-group"><label for="acgn-credit-person">{t('person')}</label><select id="acgn-credit-person" className="FormControl" value={this.person} onchange={(e) => { this.person = e.target.value; }}>
          <option value="">{t('select_person')}</option>{this.results.map((p) => <option value={p.id}>{p.name} · {t(p.kind)}</option>)}
        </select></div>
        <div className="Form-group"><label for="acgn-credit-role">{t('role')}</label><input id="acgn-credit-role" className="FormControl" value={this.role} maxlength="100" oninput={(e) => { this.role = e.target.value; }} /></div>
        <Button className="Button Button--primary" type="submit" loading={this.busy} disabled={this.busy || !this.person || !this.role.trim()}>{t('save')}</Button>
      </div>
    </div>;
  }
  async search() {
    if (this.busy) return;
    this.busy = true; this.error = false;
    try { this.results = (await api(`/people?q=${encodeURIComponent(this.query)}`)).items; this.person = ''; }
    catch (_) { this.error = true; }
    finally { this.busy = false; m.redraw(); }
  }
  async save() {
    if (this.busy) return;
    this.busy = true;
    try {
      const item = await api(`/subjects/${this.attrs.subject.id}/credits`, 'POST', { person_id: Number(this.person), role: this.role });
      this.hide(); this.attrs.onSave(item);
    } catch (_) { /* API errors are shown by Flarum. */ }
    finally { this.busy = false; m.redraw(); }
  }
}

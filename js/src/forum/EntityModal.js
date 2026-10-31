import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { api, t, TYPES, listText, splitText } from './common';

export default class EntityModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.people = !!this.attrs.people;
    this.fields = { type: 'anime', kind: 'person', title: '', name: '', original_title: '', original_name: '',
      aliases: [], tags: [], summary: '', cover_url: '', image_url: '', release_date: '', total_units: null, ...this.attrs.entity };
    this.aliases = listText(this.fields.aliases);
    this.tags = listText(this.fields.tags);
    this.busy = false;
  }
  className() { return 'Acgn-modal Modal--large'; }
  title() { return t(this.attrs.entity ? 'edit' : (this.people ? 'new_person' : 'new_subject')); }
  onsubmit(event) { return this.save(event); }
  field(key, type = 'text', maxLength = 255) {
    return <div className="Form-group"><label for={`acgn-field-${key}`}>{t(key)}</label><input id={`acgn-field-${key}`} className="FormControl" type={type} maxlength={maxLength}
      value={this.fields[key] ?? ''} oninput={(e) => { this.fields[key] = e.target.value; }} /></div>;
  }
  content() {
    return <div className="Modal-body">
      <div className="Form-group"><label for="acgn-field-category">{t(this.people ? 'kind' : 'type')}</label><select id="acgn-field-category" className="FormControl"
        value={this.fields[this.people ? 'kind' : 'type']} disabled={this.people && !!this.fields.source}
        onchange={(e) => { this.fields[this.people ? 'kind' : 'type'] = e.target.value; }}>
        {(this.people ? ['person', 'character'] : TYPES).map((v) => <option value={v}>{t(v)}</option>)}
      </select></div>
      {this.field(this.people ? 'name' : 'title')}
      {this.field(this.people ? 'original_name' : 'original_title')}
      <div className="Form-group"><label for="acgn-field-aliases">{t('aliases')}</label><input id="acgn-field-aliases" className="FormControl" value={this.aliases} maxlength="4000" oninput={(e) => { this.aliases = e.target.value; }} /><small>{t('list_hint')}</small></div>
      {this.field(this.people ? 'image_url' : 'cover_url', 'url', 2000)}
      {!this.people && <>
        {this.field('release_date', 'date', 10)}
        {this.field('total_units', 'number')}
        <div className="Form-group"><label for="acgn-field-tags">{t('tags')}</label><input id="acgn-field-tags" className="FormControl" value={this.tags} maxlength="2600" oninput={(e) => { this.tags = e.target.value; }} /></div>
      </>}
      <div className="Form-group"><label for="acgn-field-summary">{t('summary')}</label><textarea id="acgn-field-summary" className="FormControl" rows="8" maxlength="20000" value={this.fields.summary} oninput={(e) => { this.fields.summary = e.target.value; }} /></div>
      <Button className="Button Button--primary" type="submit" loading={this.busy} disabled={this.busy}>{t('save')}</Button>
    </div>;
  }
  async save(event) {
    event.preventDefault();
    if (this.busy) return;
    this.busy = true;
    const data = { ...this.fields, aliases: splitText(this.aliases), tags: splitText(this.tags) };
    data.total_units = data.total_units === '' || data.total_units === null ? null : Number(data.total_units);
    const base = this.people ? '/people' : '/subjects';
    try {
      const entity = await api(base + (this.attrs.entity ? `/${this.attrs.entity.id}` : ''), this.attrs.entity ? 'PATCH' : 'POST', data);
      this.hide();
      this.attrs.onSave(entity);
    } catch (_) { /* Flarum renders API validation errors. */ }
    finally { this.busy = false; m.redraw(); }
  }
}

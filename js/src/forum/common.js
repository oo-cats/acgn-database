import app from 'flarum/forum/app';

export const TYPES = ['anime', 'manga', 'game', 'novel'];
export const STATUSES = ['planned', 'current', 'completed', 'on_hold', 'dropped'];
export const t = (key, vars = {}) => app.translator.trans(`local-acgn-database.forum.${key}`, vars);
export const can = (name) => !!app.forum.attribute(`acgn${name}`);
export const url = (item, people = false) => app.route(people ? 'acgn.person' : 'acgn.subject', { id: item.id });
export const api = (path, method = 'GET', body) => app.request({ method, url: `${app.forum.attribute('apiUrl')}/acgn${path}`, body });
export const listText = (value) => (value || []).join('、');
export const splitText = (value) => value.split(/[,，、\n]/).map((s) => s.trim()).filter(Boolean);
export const sourceUrl = (item, people = false) => `https://bgm.tv/${people ? (item.kind === 'character' ? 'character' : 'person') : 'subject'}/${item.source_id}`;

export function cover(item, people = false) {
  const image = people ? item.image_url : item.cover_url;
  return image ? <img className="Acgn-cover" src={image} alt="" loading="lazy" referrerpolicy="no-referrer" />
    : <span className="Acgn-cover Acgn-cover--empty" aria-hidden="true"><i className={`fas ${people ? 'fa-user' : 'fa-book-open'}`} /></span>;
}

import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import { api, t, url } from './common';

export default class DiscussionSubjects extends Component {
  oninit(vnode) { super.oninit(vnode); this.items = []; this.failed = false; this.id = this.attrs.discussion.id(); this.load(); }
  onbeforeupdate(vnode) {
    super.onbeforeupdate(vnode);
    const id = vnode.attrs.discussion.id();
    if (this.id !== id) { this.id = id; this.items = []; this.load(); }
  }
  async load() {
    const id = this.id; this.failed = false;
    try { const items = await api(`/discussions/${id}/subjects`); if (id === this.id) this.items = items; }
    catch (_) { if (id === this.id) this.failed = true; }
    finally { m.redraw(); }
  }
  view() {
    if (this.failed || !this.items.length) return <div />;
    return <section className="Acgn-discussion-subjects"><h3>{t('related_works')}</h3>{this.items.map((item) => <Link href={url(item)} key={item.id}>{item.title}</Link>)}</section>;
  }
}

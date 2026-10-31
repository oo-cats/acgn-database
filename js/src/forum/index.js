import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import LinkButton from 'flarum/common/components/LinkButton';
import CatalogPage from './CatalogPage';
import DetailPage from './DetailPage';
import DiscussionSubjects from './DiscussionSubjects';
import { can, t } from './common';

class PeoplePage extends CatalogPage {
  oninit(vnode) { vnode.attrs.people = true; super.oninit(vnode); }
}
class PersonPage extends DetailPage {
  oninit(vnode) { vnode.attrs.people = true; super.oninit(vnode); }
}

app.initializers.add('local-acgn-database', () => {
  app.routes['acgn.catalog'] = { path: '/acgn', component: CatalogPage };
  app.routes['acgn.subject'] = { path: '/acgn/subjects/:id', component: DetailPage };
  app.routes['acgn.people'] = { path: '/acgn/people', component: PeoplePage };
  app.routes['acgn.person'] = { path: '/acgn/people/:id', component: PersonPage };
  extend(IndexPage.prototype, 'navItems', function (items) {
    if (can('View')) items.add('acgn-database', <LinkButton href={app.route('acgn.catalog')} icon="fas fa-book-open">{t('catalog')}</LinkButton>, 15);
  });
  extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
    if (can('View') && this.discussion) items.add('acgn-database', <DiscussionSubjects discussion={this.discussion} />, -20);
  });
});

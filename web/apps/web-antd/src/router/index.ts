import { platformRoutes } from './routes/platform';
import { systemRoutes } from './routes/system';
import { centerRoutes } from './routes/center';
import type { RouteRecordRaw } from 'vue-router';
import type { Menu } from '../api/contracts';
import { createRouter, createWebHashHistory } from 'vue-router';
import { findMenuByPath } from '@vben/utils';
import { activateRealm, entryContext, isCanceled, loadUser, loginPath, session } from '../api/identity';

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/system/login' },
  ...(['platform', 'system'] as const).map(realm => ({ path: `/${realm}/login`, component: () => import('#/layouts/auth.vue'), children: [
    { path: '', name: `${realm}-login`, component: () => import('#/views/auth/login.vue'), meta: { public: true, realm, title: realm === 'platform' ? '平台登录' : '系统登录' } },
  ] })),
  { path: '/system/handoff', component: () => import('#/layouts/auth.vue'), children: [
    { path: '', component: () => import('#/views/auth/handoff.vue'), meta: { public: true, realm: 'system', title: '进入客户系统' } },
  ] },
  platformRoutes,
  systemRoutes,
  centerRoutes,
  { path: '/:pathMatch(.*)*', component: () => import('#/views/not-found.vue'), meta: { public: true, title: '页面不存在' } },
];
function namePages(records: RouteRecordRaw[], parent = '') {
  for (const record of records) {
    const path = record.path.startsWith('/') ? record.path : `${parent}/${record.path}`;
    if (record.children) namePages(record.children, path);
    else if (!record.redirect && record.meta?.title && !record.meta.public) {
      record.name ||= path.replace(/^\//, '').replace(/[^a-z0-9]+/g, '-');
      // 页面离开时释放请求和一次性秘密；标签页只保留导航，不缓存业务实例。
      record.meta = { ...record.meta, keepAlive: false };
    }
  }
}
namePages(routes);
export const router = createRouter({ history: createWebHashHistory(), routes });

/** 将服务端可管理的菜单元数据同步到 Vben 路由，页面标题、图标和排序保持单一来源。 */
function syncRouteMenuMeta(menus: Menu[]) {
  const metadata = new Map<string, Menu>();
  const visit = (items: Menu[]) => {
    for (const item of items) {
      metadata.set(item.path, item);
      if (item.children) visit(item.children);
    }
  };
  visit(menus);
  for (const record of router.getRoutes()) {
    const item = metadata.get(record.path);
    if (!item) continue;
    record.meta = { ...record.meta, title: item.name, icon: item.icon, order: item.order };
  }
}

router.beforeEach(async to => {
  const realm = to.meta.realm === 'platform' ? 'platform' : 'system';
  if (realm === 'platform' && entryContext.bound) return '/system/login';
  activateRealm(realm);
  if (to.meta.public) return true;
  if (!session.token) return loginPath(realm);
  try { await loadUser(); }
  catch (error) { return isCanceled(error) ? false : loginPath(realm); }
  syncRouteMenuMeta(session.menus);
  if (typeof to.meta.permission === 'string' && !session.permissions.includes(to.meta.permission)) return `/${realm}/profile`;
  const directory = to.matched.at(-1);
  if (directory?.meta.directory) {
    const menu = findMenuByPath(session.menus, directory.path) as Menu | null;
    const first = directory.children.find(child => typeof child.meta?.permission !== 'string' || session.permissions.includes(child.meta.permission));
    return menu?.redirectPath || first?.path || `/${realm}/profile`;
  }
  return true;
});

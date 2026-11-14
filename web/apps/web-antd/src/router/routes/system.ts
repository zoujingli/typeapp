import type { RouteRecordRaw } from 'vue-router';

const basic = () => import('#/layouts/basic.vue');
const profile = () => import('#/views/shared/identity/profile.vue');
const overview = () => import('#/views/shared/overview.vue');
const users = () => import('#/views/shared/identity/users.vue');
const roles = () => import('#/views/shared/identity/roles.vue');

export const systemRoutes: RouteRecordRaw = { path: '/system', component: basic, redirect: '/system/overview', meta: { realm: 'system', title: '客户系统' }, children: [
    { path: '/system/overview', component: overview, meta: { title: '工作台', icon: 'lucide:layout-dashboard', order: 0, permission: 'system.overview.read' } },
    { path: '/system/profile', component: profile, meta: { title: '个人中心', icon: 'lucide:user', order: 1 } },
    { path: 'groups/access', name: 'system-directory-access', meta: { directory: true, title: '用户与权限', icon: 'lucide:users-round', order: 10 }, children: [
      { path: '/system/users', component: users, meta: { title: '用户管理', icon: 'lucide:users', order: 10, permission: 'system.users.read' } },
      { path: '/system/roles', component: roles, meta: { title: '角色管理', icon: 'lucide:shield-check', order: 20, permission: 'system.roles.read' } },
    ] },
    { path: 'groups/settings', name: 'system-directory-settings', meta: { directory: true, title: '系统管理', icon: 'lucide:settings', order: 30 }, children: [
      { path: '/system/site', component: () => import('#/views/shared/site-settings.vue'), meta: { title: '系统设置', icon: 'lucide:palette', order: 10, permission: 'system.site.read' } },
      { path: '/system/audit', component: () => import('#/views/shared/identity/audit.vue'), meta: { title: '操作审计', icon: 'lucide:scroll-text', order: 20, permission: 'system.audit.read' } },
    ] },
  ] };

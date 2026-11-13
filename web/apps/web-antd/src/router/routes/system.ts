import type { RouteRecordRaw } from 'vue-router';

const basic = () => import('#/layouts/basic.vue');
const profile = () => import('#/views/shared/identity/profile.vue');
const users = () => import('#/views/shared/identity/users.vue');
const roles = () => import('#/views/shared/identity/roles.vue');

export const systemRoutes: RouteRecordRaw = { path: '/system', component: basic, redirect: '/system/profile', meta: { realm: 'system', title: '客户系统' }, children: [
    { path: '/system/profile', component: profile, meta: { title: '个人中心' } },
    { path: 'groups/access', name: 'system-directory-access', meta: { directory: true, title: '用户与权限', icon: 'lucide:users-round' }, children: [
      { path: '/system/users', component: users, meta: { title: '用户管理', permission: 'system.users.read' } },
      { path: '/system/roles', component: roles, meta: { title: '角色管理', permission: 'system.roles.read' } },
    ] },
    { path: 'groups/settings', name: 'system-directory-settings', meta: { directory: true, title: '系统管理', icon: 'lucide:settings' }, children: [
      { path: '/system/site', component: () => import('#/views/shared/site-settings.vue'), meta: { title: '系统设置', permission: 'system.site.read' } },
      { path: '/system/audit', component: () => import('#/views/shared/identity/audit.vue'), meta: { title: '操作审计', permission: 'system.audit.read' } },
    ] },
  ] };

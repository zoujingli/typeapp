import type { RouteRecordRaw } from 'vue-router';

const basic = () => import('#/layouts/basic.vue');
const profile = () => import('#/views/shared/identity/profile.vue');
const overview = () => import('#/views/shared/overview.vue');
const users = () => import('#/views/shared/identity/users.vue');
const roles = () => import('#/views/shared/identity/roles.vue');

export const platformRoutes: RouteRecordRaw = { path: '/platform', component: basic, redirect: '/platform/overview', meta: { realm: 'platform', title: '平台管理' }, children: [
    { path: '/platform/overview', component: overview, meta: { title: '工作台', icon: 'lucide:layout-dashboard', order: 0 } },
    { path: '/platform/profile', component: profile, meta: { title: '个人中心', icon: 'lucide:user', order: 1 } },
    { path: 'groups/operations', name: 'platform-directory-operations', meta: { directory: true, title: '客户与物联', icon: 'lucide:building-2', order: 10 }, children: [
      { path: '/platform/tenants', component: () => import('#/views/platform/tenants.vue'), meta: { title: '客户管理', icon: 'lucide:building-2', order: 10, permission: 'platform.tenants.read' } },
      { path: '/platform/enrollments', component: () => import('#/views/center/enrollments.vue'), meta: { title: '注册资格', icon: 'lucide:badge-check', order: 20, permission: 'platform.enrollments.read' } },
      { path: '/platform/devices', component: () => import('#/views/center/devices.vue'), meta: { title: '设备资产', icon: 'lucide:cpu', order: 30, permission: 'platform.devices.read' } },
      { path: '/platform/devices/:device', component: () => import('#/views/center/device-detail.vue'), meta: { title: '设备详情', icon: 'lucide:cpu', order: 30, permission: 'platform.devices.read', activePath: '/platform/devices' } },
      { path: '/platform/applications', component: () => import('#/views/center/applications.vue'), meta: { title: '接入应用', icon: 'lucide:app-window', order: 40, permission: 'platform.applications.read' } },
      { path: '/platform/reassignments', component: () => import('#/views/center/reassignments.vue'), meta: { title: '设备转配', icon: 'lucide:arrow-left-right', order: 50, permission: 'platform.reassignments.read' } },
    ] },
    { path: 'groups/access', name: 'platform-directory-access', meta: { directory: true, title: '平台权限', icon: 'lucide:shield-check', order: 20 }, children: [
      { path: '/platform/users', component: users, meta: { title: '平台用户', icon: 'lucide:users', order: 10, permission: 'platform.users.read' } },
      { path: '/platform/roles', component: roles, meta: { title: '平台角色', icon: 'lucide:shield-check', order: 20, permission: 'platform.roles.read' } },
      { path: '/platform/menus', component: () => import('#/views/platform/menus.vue'), meta: { title: '菜单管理', icon: 'lucide:menu', order: 30, permission: 'platform.menus.read' } },
    ] },
    { path: 'groups/monitoring', name: 'platform-directory-monitoring', meta: { directory: true, title: '运维监控', icon: 'lucide:activity', order: 30 }, children: [
      { path: '/platform/operations', component: () => import('#/views/platform/operations.vue'), meta: { title: '运行概览', icon: 'lucide:activity', order: 10, permission: 'platform.operations.read' } },
      { path: '/platform/broker', component: () => import('#/views/shared/mqtt-resources.vue'), meta: { title: 'MQTT 资源', icon: 'lucide:network', order: 20, permission: 'platform.broker.read' } },
      { path: '/platform/broker/access', component: () => import('#/views/platform/mqtt/access.vue'), meta: { title: 'MQTT 授权', icon: 'lucide:network', order: 20, permission: 'platform.broker.read' } },
      { path: '/platform/broker/quotas', component: () => import('#/views/platform/mqtt/quotas.vue'), meta: { title: 'MQTT 配额', icon: 'lucide:network', order: 20, permission: 'platform.broker.read' } },
      { path: '/platform/broker/runtime', component: () => import('#/views/platform/mqtt/runtime.vue'), meta: { title: 'MQTT 配置', icon: 'lucide:sliders-horizontal', order: 20, permission: 'platform.broker.read' } },
      { path: '/platform/broker/debug', component: () => import('#/views/platform/mqtt/debug.vue'), meta: { title: 'MQTT 调试', icon: 'lucide:bug', order: 20, permission: 'platform.broker.read' } },
      { path: '/platform/broker/nodes', component: () => import('#/views/platform/mqtt/nodes.vue'), meta: { title: 'MQTT 节点', icon: 'lucide:server', order: 20, permission: 'platform.broker.read' } },
      { path: '/platform/audit', component: () => import('#/views/shared/identity/audit.vue'), meta: { title: '操作审计', icon: 'lucide:scroll-text', order: 30, permission: 'platform.audit.read' } },
    ] },
    { path: 'groups/settings', name: 'platform-directory-settings', meta: { directory: true, title: '平台设置', icon: 'lucide:settings', order: 40 }, children: [
      { path: '/platform/site', component: () => import('#/views/shared/site-settings.vue'), meta: { title: '平台设置', icon: 'lucide:palette', order: 10, permission: 'platform.site.read' } },
      { path: '/platform/system-defaults', component: () => import('#/views/shared/site-settings.vue'), meta: { title: '系统登录与初始配置', icon: 'lucide:panels-top-left', order: 20, permission: 'platform.defaults.read' } },
      { path: '/platform/config', component: () => import('#/views/platform/configuration.vue'), meta: { title: '运行配置', icon: 'lucide:sliders-horizontal', order: 30, permission: 'platform.config.read' } },
    ] },
  ] };

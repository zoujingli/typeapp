import { createRouter, createWebHashHistory } from 'vue-router';
import { activateRealm, ApiError, isCanceled, loadUser, loginPath, selectTenant, session, sessionExitPath } from './api';

/** 三个身份域共用页面注册；导航守卫负责界面上下文，服务端仍独立执行每次授权。 */
export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/login', component: () => import('./views/login.vue'), meta: { title: '用户登录', hideInMenu: true } },
    { path: '/admin/login', component: () => import('./views/login.vue'), meta: { title: '平台登录', hideInMenu: true } },
    { path: '/broker/login', component: () => import('./views/login.vue'), meta: { title: 'Broker 登录', hideInMenu: true } },
    { path: '/', component: () => import('./layouts/basic.vue'), redirect: '/profile', children: [
      { path: 'profile', component: () => import('./views/profile.vue'), meta: { title: '个人工作区' } },
      { path: 'tenants', component: () => import('./views/tenants.vue'), meta: { title: '我的租户' } },
      { path: 'members', component: () => import('./views/admin-users.vue'), meta: { title: '租户成员' } },
      { path: 'roles', component: () => import('./views/admin-roles.vue'), meta: { title: '租户角色' } },
      { path: 'products', component: () => import('./views/products.vue'), meta: { title: '产品管理' } },
      { path: 'devices', component: () => import('./views/devices.vue'), meta: { title: '设备管理' } },
      { path: 'devices/:device', component: () => import('./views/device-detail.vue'), meta: { title: '设备详情', hideInMenu: true } },
      { path: 'history', component: () => import('./views/history.vue'), meta: { title: '历史数据' } },
      { path: 'transfers', component: () => import('./views/transfers.vue'), meta: { title: '设备转移' } },
      { path: 'alarm-rules', component: () => import('./views/alarm-rules.vue'), meta: { title: '告警规则' } },
      { path: 'alarms', component: () => import('./views/alarms.vue'), meta: { title: '告警中心' } },
      { path: 'notifications', component: () => import('./views/notifications.vue'), meta: { title: '站内通知' } },
      { path: 'audit', component: () => import('./views/audit.vue'), meta: { title: '操作审计' } },
      { path: 'operations', component: () => import('./views/operations.vue'), meta: { title: '运行概览' } },
      { path: 'broker-resources', component: () => import('./views/broker-resources.vue'), meta: { title: 'Broker 资源', requiresTenant: true } },
      { path: 'broker-access', component: () => import('./views/broker-access.vue'), meta: { title: 'Broker 授权', requiresTenant: true } },
      { path: 'broker-quotas', component: () => import('./views/broker-quotas.vue'), meta: { title: 'Broker 配额', requiresTenant: true } },
      { path: 'broker-runtime', component: () => import('./views/broker-runtime.vue'), meta: { title: 'Broker 运行配置', requiresTenant: true } },
      { path: 'broker-debug', component: () => import('./views/broker-debug.vue'), meta: { title: 'Broker 调试订阅', requiresTenant: true } },
      { path: 'broker-audit', component: () => import('./views/audit.vue'), meta: { title: 'Broker 审计', auditScope: 'application-broker', requiresTenant: true } },
      { path: 'products/:product/models', component: () => import('./views/models.vue'), meta: { title: '物模型版本', hideInMenu: true } },
      { path: 'admin', redirect: '/admin/profile' },
      { path: 'admin/profile', component: () => import('./views/profile.vue'), meta: { title: '平台个人工作区' } },
      { path: 'admin/users', component: () => import('./views/admin-users.vue'), meta: { title: '平台人员' } },
      { path: 'admin/customers', component: () => import('./views/admin-users.vue'), meta: { title: '客户账号' } },
      { path: 'admin/roles', component: () => import('./views/admin-roles.vue'), meta: { title: '平台角色' } },
      { path: 'admin/tenants', component: () => import('./views/tenants.vue'), meta: { title: '平台租户' } },
      { path: 'admin/site', component: () => import('./views/site-settings.vue'), meta: { title: '站点设置' } },
      { path: 'admin/configuration', component: () => import('./views/system-configuration.vue'), meta: { title: '系统配置' } },
      { path: 'admin/devices', component: () => import('./views/devices.vue'), meta: { title: '设备资产' } },
      { path: 'admin/audit', component: () => import('./views/audit.vue'), meta: { title: '平台操作审计' } },
      { path: 'admin/operations', component: () => import('./views/operations.vue'), meta: { title: '平台运行概览' } },
      { path: 'admin/broker', component: () => import('./views/broker-resources.vue'), meta: { title: '平台 Broker 资源' } },
      { path: 'admin/broker-access', component: () => import('./views/broker-access.vue'), meta: { title: '平台 Broker 授权' } },
      { path: 'admin/broker-quotas', component: () => import('./views/broker-quotas.vue'), meta: { title: '平台 Broker 配额' } },
      { path: 'admin/broker-runtime', component: () => import('./views/broker-runtime.vue'), meta: { title: '平台 Broker 运行配置' } },
      { path: 'admin/broker-debug', component: () => import('./views/broker-debug.vue'), meta: { title: '平台 Broker 调试订阅' } },
      { path: 'admin/broker-audit', component: () => import('./views/audit.vue'), meta: { title: '平台 Broker 审计', auditScope: 'application-broker' } },
      { path: 'broker/nodes', component: () => import('./views/broker-nodes.vue'), meta: { title: 'Broker 节点' } },
      { path: 'broker/resources', component: () => import('./views/broker-resources.vue'), meta: { title: 'Broker 资源' } },
      { path: 'broker/access', component: () => import('./views/broker-access.vue'), meta: { title: 'Broker 授权' } },
      { path: 'broker/quotas', component: () => import('./views/broker-quotas.vue'), meta: { title: 'Broker 配额' } },
      { path: 'broker/runtime', component: () => import('./views/broker-runtime.vue'), meta: { title: 'Broker 运行配置' } },
      { path: 'broker/debug', component: () => import('./views/broker-debug.vue'), meta: { title: 'Broker 调试订阅' } },
      { path: 'broker/audit', component: () => import('./views/audit.vue'), meta: { title: 'Broker 审计', auditScope: 'standalone' } },
    ] },
    { path: '/:pathMatch(.*)*', redirect: '/profile' },
  ],
});
router.beforeEach(async (to) => {
  const realm = to.path.startsWith('/broker/') ? 'broker' : to.path === '/admin' || to.path.startsWith('/admin/') ? 'admin' : 'customer';
  activateRealm(realm);
  if (to.path === loginPath(realm)) return session.impersonating ? '/profile' : true;
  if (!session.token) return sessionExitPath();
  try { await loadUser(); }
  catch (error) {
    if (isCanceled(error)) return false;
    if (realm === 'customer' && error instanceof ApiError && error.status === 403) {
      selectTenant(null);
      session.notice = '当前租户权限已改变，请重新选择租户。';
      if (to.path !== '/tenants') return '/tenants';
      try { await loadUser(''); return true; }
      catch (retryError) { return isCanceled(retryError) ? false : sessionExitPath(); }
    }
    return sessionExitPath();
  }
  if (to.meta.auditScope === 'standalone' && !session.user?.platform_admin) return '/broker/nodes';
  if (to.meta.requiresTenant && !session.tenant) return '/tenants';
  return true;
});
